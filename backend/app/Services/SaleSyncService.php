<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\LoyaltyConfig;
use App\Repositories\SaleRepository;
use App\Repositories\ProductRepository;
use App\Repositories\CustomerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SaleSyncService
{
    public function __construct(
        private readonly InventoryService    $inventoryService,
        private readonly SaleRepository      $saleRepository,
        private readonly ProductRepository   $productRepository,
        private readonly CustomerRepository  $customerRepository,
    ) {}

    /**
     * Sincroniza un lote de ventas recibidas del frontend (Offline-First).
     *
     * Procesa cada venta individualmente para que un fallo parcial no
     * bloquee la sincronización de las demás ventas del lote.
     *
     * @return array{synced: string[], failed: array<array{id: string, error: string}>}
     */
    public function syncBatch(array $salesData, int $cashierId): array
    {
        $results = ['synced' => [], 'failed' => []];

        foreach ($salesData as $saleData) {
            $saleId = $saleData['id'];
            try {
                // Idempotencia: Verificar si el UUID ya existe en BD
                // Si ya fue sincronizado, retornar 200 sin duplicar (buenas-practicas.md §4)
                if ($this->saleRepository->existsByUuid($saleId)) {
                    $results['synced'][] = $saleId;
                    continue;
                }

                // Transacción ACID: Venta + Items + Pagos + Stock + Puntos
                DB::transaction(function () use ($saleData, $cashierId) {
                    $this->processSale($saleData, $cashierId);
                });

                $results['synced'][] = $saleId;

            } catch (Exception $e) {
                Log::error("Error sincronizando venta {$saleId}: " . $e->getMessage(), [
                    'sale_id'    => $saleId,
                    'cashier_id' => $cashierId,
                    'exception'  => $e,
                ]);
                $results['failed'][] = ['id' => $saleId, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Procesa una venta individual dentro de una transacción ACID.
     *
     * Orden garantizado:
     * 1. Crear registro de venta (cabecera).
     * 2. Crear items y descontar insumos del inventario.
     * 3. Registrar pagos.
     * 4. Acumular puntos de fidelización (si aplica).
     *
     * Si cualquier paso falla, DB::transaction() hace rollback total.
     */
    protected function processSale(array $data, int $cashierId): void
    {
        // 1. Insertar Venta Principal
        $sale = $this->saleRepository->create([
            'id'              => $data['id'],
            'cashier_id'      => $cashierId,
            'customer_id'     => $data['customer_id'] ?? null,
            'subtotal'        => $data['subtotal'],
            'discount_amount' => $data['discount_amount'] ?? 0,
            'total_amount'    => $data['total_amount'],
            'status'          => $data['status'] ?? 'completed',
            'source'          => $data['source'] ?? 'pos',
            'sync_status'     => 'synced',
            'created_at'      => $data['created_at'] ?? now(),
        ]);

        // 2. Insertar Items y Descontar Stock
        foreach ($data['items'] as $itemData) {
            // Usar repositorio en lugar de consulta directa
            $product = $this->productRepository->findWithRecipes($itemData['product_id']);

            SaleItem::create([
                'sale_id'               => $sale->id,
                'product_id'            => $product->id,
                'quantity'              => $itemData['quantity'],
                'unit_price'            => $itemData['unit_price'],
                'subtotal'              => $itemData['subtotal'],
                'topping_modifications' => $itemData['topping_modifications'] ?? null,
            ]);

            // Solo descontar stock en ventas completadas (no en anuladas)
            if ($sale->status === 'completed') {
                $this->inventoryService->deductFromRecipe(
                    $product,
                    $itemData['quantity'],
                    $cashierId,
                    $sale->id
                );
            }
        }

        // 3. Insertar Pagos
        if (!empty($data['payments'])) {
            foreach ($data['payments'] as $paymentData) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'method'  => $paymentData['method'],
                    'amount'  => $paymentData['amount'],
                ]);
            }
        }

        // 4. Acumular puntos de fidelización (dentro de la misma transacción)
        if ($sale->customer_id && $sale->status === 'completed') {
            $this->updateLoyaltyPoints($sale);
        }
    }

    /**
     * Acumula puntos de lealtad del cliente usando actualización atómica.
     *
     * CORRECCIÓN: Se busca al cliente DENTRO de la transacción para
     * evitar lecturas sucias. Se usa CustomerRepository::incrementPoints()
     * que internamente usa SQL `INCREMENT` en lugar de leer-modificar-guardar.
     */
    protected function updateLoyaltyPoints(Sale $sale): void
    {
        $config = LoyaltyConfig::active();
        if (!$config || $config->accumulation_rate <= 0) {
            return;
        }

        $customer = $this->customerRepository->find($sale->customer_id);
        if (!$customer) {
            return;
        }

        $pointsEarned = (int) floor($sale->total_amount / $config->accumulation_rate);

        // Actualización atómica — sin race condition entre cajeros
        $this->customerRepository->incrementPoints($customer, $pointsEarned);
    }
}
