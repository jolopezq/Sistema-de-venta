<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Product;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SaleSyncService
{
    protected InventoryService $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * Sincroniza un lote de ventas recibidas del frontend (Offline-First).
     */
    public function syncBatch(array $salesData, int $cashierId): array
    {
        $results = [
            'synced' => [],
            'failed' => [],
        ];

        foreach ($salesData as $saleData) {
            try {
                $saleId = $saleData['id'];

                // 1. Idempotencia: Verificar si el UUID ya existe en BD
                if (Sale::where('id', $saleId)->exists()) {
                    // Ya sincronizado en un intento anterior
                    $results['synced'][] = $saleId;
                    continue;
                }

                // 2. Transacción ACID: Venta + Items + Pagos + Stock + Puntos
                DB::transaction(function () use ($saleData, $cashierId) {
                    $this->processSale($saleData, $cashierId);
                });

                $results['synced'][] = $saleId;
            } catch (Exception $e) {
                Log::error("Error sincronizando venta {$saleData['id']}: " . $e->getMessage());
                $results['failed'][] = [
                    'id' => $saleData['id'],
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Procesa una venta individual dentro de una transacción.
     */
    protected function processSale(array $data, int $cashierId): void
    {
        // Insertar Venta Principal
        $sale = Sale::create([
            'id' => $data['id'],
            'cashier_id' => $cashierId,
            'customer_id' => $data['customer_id'] ?? null,
            'subtotal' => $data['subtotal'],
            'discount_amount' => $data['discount_amount'] ?? 0,
            'total_amount' => $data['total_amount'],
            'status' => $data['status'] ?? 'completed',
            'source' => $data['source'] ?? 'pos',
            'sync_status' => 'synced',
            'created_at' => $data['created_at'] ?? now(),
        ]);

        // Insertar Items y Descontar Stock
        foreach ($data['items'] as $itemData) {
            $product = Product::with('recipes.ingredient')->findOrFail($itemData['product_id']);
            
            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'quantity' => $itemData['quantity'],
                'unit_price' => $itemData['unit_price'],
                'subtotal' => $itemData['subtotal'],
                'topping_modifications' => $itemData['topping_modifications'] ?? null,
            ]);

            // Solo descontar stock si la venta no fue anulada
            if ($sale->status === 'completed') {
                $this->inventoryService->deductFromRecipe($product, $itemData['quantity'], $cashierId, $sale->id);
            }
        }

        // Insertar Pagos
        if (!empty($data['payments'])) {
            foreach ($data['payments'] as $paymentData) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'method' => $paymentData['method'],
                    'amount' => $paymentData['amount'],
                ]);
            }
        }

        // Calcular puntos de Fidelidad (Cashback)
        if ($sale->customer_id && $sale->status === 'completed') {
            $this->updateLoyaltyPoints($sale);
        }
    }

    /**
     * Actualiza los puntos de lealtad del cliente.
     */
    protected function updateLoyaltyPoints(Sale $sale): void
    {
        $config = LoyaltyConfig::active();
        if (!$config || $config->accumulation_rate <= 0) {
            return;
        }

        $customer = Customer::find($sale->customer_id);
        if (!$customer) return;

        // Calcula puntos basados en el total_amount
        $pointsEarned = floor($sale->total_amount / $config->accumulation_rate);
        
        if ($pointsEarned > 0) {
            $customer->loyalty_points += $pointsEarned;
            $customer->save();
        }
    }
}
