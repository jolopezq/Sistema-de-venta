<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\LoyaltyConfig;
use App\Models\Option;
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
    public function processSale(array $data, int $cashierId): void
    {
        $createdAt = isset($data['created_at']) ? \Carbon\Carbon::parse($data['created_at']) : now();
        $datePrefix = $createdAt->format('dmy'); // Formato Día-Mes-Año DDMMAA (ej: 270826)
        
        $dailySeq = $data['daily_sequence'] ?? null;
        $orderNumber = $data['order_number'] ?? null;

        if (!$orderNumber) {
            $todayCount = Sale::whereDate('created_at', $createdAt->toDateString())->count();
            $dailySeq = $todayCount + 1;
            $orderNumber = sprintf('%s-%04d', $datePrefix, $dailySeq);
        }

        $itemsCollection = collect($data['items'] ?? []);
        $totalItemsCount = $itemsCollection->count();
        $takeawayItemsCount = $itemsCollection->filter(fn($i) => !empty($i['is_takeaway']))->count();

        // La orden es 'takeaway' solo si se forzó a nivel global o si el 100% de los ítems son para llevar
        $orderIsTakeaway = !empty($data['is_takeaway']) || ($totalItemsCount > 0 && $takeawayItemsCount === $totalItemsCount);

        // 1. Insertar Venta Principal
        $sale = $this->saleRepository->create([
            'id'                 => $data['id'],
            'order_number'       => $orderNumber,
            'daily_sequence'     => $dailySeq,
            'cashier_id'         => $cashierId,
            'customer_id'        => $data['customer_id'] ?? null,
            'subtotal'           => $data['subtotal'],
            'discount_amount'    => $data['discount_amount'] ?? 0,
            'total_amount'       => $data['total_amount'],
            'status'             => $data['status'] ?? 'completed',
            'preparation_status' => $data['preparation_status'] ?? 'received',
            'source'             => $data['source'] ?? 'pos',
            'is_takeaway'        => $orderIsTakeaway,
            'notes'              => $data['notes'] ?? null,
            'sync_status'        => 'synced',
            'created_at'         => $createdAt,
        ]);

        // 2. Insertar Items y Descontar Stock
        foreach ($data['items'] as $itemData) {
            // Usar repositorio en lugar de consulta directa
            $product = $this->productRepository->findWithRecipes($itemData['product_id']);

            /**
             * Validar reglas de negocio (Min/Max) en el backend (V2)
             */
            $modifiers = $itemData['modifiers'] ?? $itemData['topping_modifications'] ?? [];
            
            // Agrupar modificadores por grupo para validarlos
            $selectedOptionIds = array_column($modifiers, 'option_id');
            $selectedOptions = Option::whereIn('id', $selectedOptionIds)->get()->keyBy('id');
            
            $selectionsByGroup = [];
            foreach ($modifiers as $mod) {
                $opt = $selectedOptions->get($mod['option_id']);
                if ($opt && $opt->is_active) {
                    $selectionsByGroup[$opt->option_group_id] = ($selectionsByGroup[$opt->option_group_id] ?? 0) + 1;
                }
            }

            foreach ($product->optionGroups as $group) {
                if (!$group->is_active) continue;
                
                $count = $selectionsByGroup[$group->id] ?? 0;
                
                if ($count < $group->min_selections) {
                    throw new Exception("El producto {$product->name} requiere al menos {$group->min_selections} opciones en el grupo '{$group->name}'");
                }
                
                if ($group->max_selections !== null && $count > $group->max_selections) {
                    throw new Exception("El producto {$product->name} excede el máximo de {$group->max_selections} opciones en el grupo '{$group->name}'");
                }
            }

            $itemIsTakeaway = $orderIsTakeaway ? true : !empty($itemData['is_takeaway']);

            $saleItem = SaleItem::create([
                'sale_id'               => $sale->id,
                'product_id'            => $product->id,
                'quantity'              => $itemData['quantity'],
                'unit_price'            => $itemData['unit_price'],
                'subtotal'              => $itemData['subtotal'],
                'is_takeaway'           => $itemIsTakeaway,
                'item_note'             => $itemData['item_note'] ?? null,
                'allergen_flags'        => isset($itemData['allergen_flags']) && is_array($itemData['allergen_flags']) ? $itemData['allergen_flags'] : null,
            ]);

            // Save sale_item_options
            $saleItemOptions = collect();
            foreach ($modifiers as $mod) {
                $opt = $selectedOptions->get($mod['option_id']);
                if ($opt) {
                    $saleItemOption = \App\Models\SaleItemOption::create([
                        'sale_item_id' => $saleItem->id,
                        'option_id' => $opt->id,
                        'option_group_id' => $opt->option_group_id,
                        'option_name_snapshot' => $opt->name,
                        'additional_price_snapshot' => $opt->additional_price,
                        'quantity' => $mod['quantity'] ?? 1,
                    ]);
                    $saleItemOptions->push($saleItemOption);
                }
            }

            // Solo descontar stock en ventas completadas (no en anuladas)
            if ($sale->status === 'completed') {
                // Descuenta la receta BASE del producto (ej: 100g Acai)
                $this->inventoryService->deductFromRecipe(
                    $product,
                    $itemData['quantity'],
                    $cashierId,
                    $saleItem
                );

                // Descuenta las recetas de cada OPCIÓN seleccionada
                if ($saleItemOptions->isNotEmpty()) {
                    $this->inventoryService->deductFromOptionRecipes(
                        $saleItemOptions,
                        $itemData['quantity'],
                        $cashierId,
                        $saleItem
                    );
                }
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

    /**
     * Anula una venta y revierte los movimientos de inventario de manera atómica.
     */
    public function voidSale(Sale $sale, int $userId, string $reason): void
    {
        if ($sale->status === 'voided') {
            throw new Exception("La venta ya se encuentra anulada.");
        }

        DB::transaction(function () use ($sale, $userId, $reason) {
            $sale->status = 'voided';
            $sale->void_reason = $reason;
            $sale->voided_by = $userId;
            $sale->save();

            // Reverse inventory movements
            $saleItemIds = $sale->items()->pluck('id');
            $saleItemOptionIds = \App\Models\SaleItemOption::whereIn('sale_item_id', $saleItemIds)->pluck('id');

            $movements = \App\Models\InventoryMovement::where('type', 'sale')
                ->where(function ($query) use ($saleItemIds, $saleItemOptionIds) {
                    $query->where(function ($q) use ($saleItemIds) {
                        $q->where('reference_type', 'sale_item')
                          ->whereIn('reference_id', $saleItemIds);
                    })->orWhere(function ($q) use ($saleItemOptionIds) {
                        $q->where('reference_type', 'sale_item_option')
                          ->whereIn('reference_id', $saleItemOptionIds);
                    });
                })->get();

            foreach ($movements as $originalMovement) {
                // Revert movement: same ingredient, opposite quantity
                $reversalQuantity = -$originalMovement->quantity_changed;

                \App\Models\InventoryMovement::create([
                    'ingredient_id' => $originalMovement->ingredient_id,
                    'quantity_changed' => $reversalQuantity,
                    'type' => 'adjustment', // Usamos 'adjustment' para devoluciones o podemos crear 'void'
                    'notes' => 'Reverso por anulación de Venta UUID: ' . $sale->id,
                    'performed_by' => $userId,
                    'reference_type' => $originalMovement->reference_type,
                    'reference_id' => $originalMovement->reference_id,
                ]);

                $ingredient = \App\Models\Ingredient::find($originalMovement->ingredient_id);
                $ingredient->current_stock += $reversalQuantity;
                $ingredient->save();
            }

            // También debemos revertir puntos de lealtad si se le otorgaron al cliente
            $config = LoyaltyConfig::active();
            if ($config && $config->accumulation_rate > 0 && $sale->customer_id) {
                $customer = $this->customerRepository->find($sale->customer_id);
                if ($customer) {
                    $pointsToDeduct = (int) floor($sale->total_amount / $config->accumulation_rate);
                    // Pasamos puntos negativos para descontar
                    $this->customerRepository->incrementPoints($customer, -$pointsToDeduct);
                }
            }
        });
    }
}
