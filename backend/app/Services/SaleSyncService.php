<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleItemOption;
use App\Models\LoyaltyConfig;
use App\Models\Option;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Ingredient;
use App\Repositories\SaleRepository;
use App\Repositories\ProductRepository;
use App\Repositories\CustomerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
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
     * @return array{synced: string[], failed: array<array{id: string, error: string}>}
     */
    public function syncBatch(array $salesData, int $cashierId): array
    {
        $results = ['synced' => [], 'failed' => []];

        foreach ($salesData as $saleData) {
            $saleId = $saleData['id'];
            try {
                // Idempotencia: Si ya existe en BD, retornar como synced
                if ($this->saleRepository->existsByUuid($saleId)) {
                    $results['synced'][] = $saleId;
                    continue;
                }

                // Transacción ACID
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
     */
    public function processSale(array $data, int $cashierId): void
    {
        $createdAt = isset($data['created_at']) ? Carbon::parse($data['created_at']) : now();
        $datePrefix = $createdAt->format('dmy');
        
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
        $this->insertSaleItems($sale, $data['items'], $cashierId, $orderIsTakeaway);

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

        // 4. Acumular puntos de fidelización
        if ($sale->customer_id && $sale->status === 'completed') {
            $this->updateLoyaltyPoints($sale);
        }
    }

    /**
     * Inserta los ítems de una venta y descuenta el stock de recetas e insumos.
     */
    public function insertSaleItems(Sale $sale, array $itemsData, int $cashierId, bool $orderIsTakeaway): void
    {
        foreach ($itemsData as $itemData) {
            $product = $this->productRepository->findWithRecipes($itemData['product_id']);
            $modifiers = $itemData['modifiers'] ?? $itemData['topping_modifications'] ?? [];
            
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
                'sale_id'        => $sale->id,
                'product_id'     => $product->id,
                'quantity'       => $itemData['quantity'],
                'unit_price'     => $itemData['unit_price'],
                'subtotal'       => $itemData['subtotal'],
                'is_takeaway'    => $itemIsTakeaway,
                'item_note'      => $itemData['item_note'] ?? null,
                'allergen_flags' => isset($itemData['allergen_flags']) && is_array($itemData['allergen_flags']) ? $itemData['allergen_flags'] : null,
            ]);

            $saleItemOptions = collect();
            foreach ($modifiers as $mod) {
                $opt = $selectedOptions->get($mod['option_id']);
                if ($opt) {
                    $saleItemOption = SaleItemOption::create([
                        'sale_item_id'              => $saleItem->id,
                        'option_id'                 => $opt->id,
                        'option_group_id'           => $opt->option_group_id,
                        'option_name_snapshot'      => $opt->name,
                        'additional_price_snapshot' => $opt->additional_price,
                        'quantity'                  => $mod['quantity'] ?? 1,
                    ]);
                    $saleItemOptions->push($saleItemOption);
                }
            }

            if ($sale->status === 'completed') {
                $this->inventoryService->deductFromRecipe(
                    $product,
                    $itemData['quantity'],
                    $cashierId,
                    $saleItem
                );

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
    }

    /**
     * Crea una venta retroactiva o manual por un Super Admin.
     */
    public function adminCreateSale(array $data, int $superAdminId): Sale
    {
        return DB::transaction(function () use ($data, $superAdminId) {
            $createdAt = isset($data['created_at']) ? Carbon::parse($data['created_at']) : now();
            $datePrefix = $createdAt->format('dmy');

            $todayCount = Sale::whereDate('created_at', $createdAt->toDateString())->count();
            $dailySeq = $todayCount + 1;
            $orderNumber = sprintf('%s-%04d', $datePrefix, $dailySeq);

            $itemsCollection = collect($data['items'] ?? []);
            $totalItemsCount = $itemsCollection->count();
            $takeawayItemsCount = $itemsCollection->filter(fn($i) => !empty($i['is_takeaway']))->count();
            $orderIsTakeaway = !empty($data['is_takeaway']) || ($totalItemsCount > 0 && $takeawayItemsCount === $totalItemsCount);

            $cashierId = $data['cashier_id'] ?? $superAdminId;
            $saleId = $data['id'] ?? Str::uuid()->toString();

            $sale = $this->saleRepository->create([
                'id'                 => $saleId,
                'order_number'       => $orderNumber,
                'daily_sequence'     => $dailySeq,
                'cashier_id'         => $cashierId,
                'customer_id'        => $data['customer_id'] ?? null,
                'subtotal'           => $data['subtotal'],
                'discount_amount'    => $data['discount_amount'] ?? 0,
                'total_amount'       => $data['total_amount'],
                'status'             => 'completed',
                'preparation_status' => 'delivered',
                'source'             => 'manual_retroactive',
                'is_takeaway'        => $orderIsTakeaway,
                'notes'              => $data['notes'] ?? null,
                'edit_reason'        => $data['edit_reason'],
                'edited_by'          => $superAdminId,
                'edited_at'          => now(),
                'sync_status'        => 'synced',
                'created_at'         => $createdAt,
            ]);

            $this->insertSaleItems($sale, $data['items'], $cashierId, $orderIsTakeaway);

            if (!empty($data['payments'])) {
                foreach ($data['payments'] as $paymentData) {
                    SalePayment::create([
                        'sale_id' => $sale->id,
                        'method'  => $paymentData['method'],
                        'amount'  => $paymentData['amount'],
                    ]);
                }
            }

            if ($sale->customer_id && $sale->status === 'completed') {
                $this->updateLoyaltyPoints($sale);
            }

            AuditLog::create([
                'user_id'     => $superAdminId,
                'action'      => 'admin_sale_create',
                'module'      => 'Sales',
                'description' => "Registro manual/retroactivo de venta Ticket #{$orderNumber} (Total: Bs {$sale->total_amount}, Fecha: {$createdAt->format('Y-m-d H:i')}). Motivo: {$data['edit_reason']}",
            ]);

            return $sale->fresh(['cashier:id,name', 'customer:id,name,ci_or_phone', 'items.product:id,name', 'items.saleItemOptions.option:id,name', 'payments', 'editedByUser:id,name']);
        });
    }

    /**
     * Edita una venta existente corrigiendo items, pagos, stock y auditoría (Solo Super Admin).
     */
    public function adminUpdateSale(Sale $sale, array $data, int $superAdminId): Sale
    {
        if ($sale->status === 'voided') {
            throw new Exception("No se puede editar una venta que ya ha sido anulada.");
        }

        return DB::transaction(function () use ($sale, $data, $superAdminId) {
            $previousTotal = (float) $sale->total_amount;
            $previousCustomer = $sale->customer_id ? $this->customerRepository->find($sale->customer_id) : null;

            // 1. Revertir inventario de los items existentes
            $saleItemIds = $sale->items()->pluck('id');
            $saleItemOptionIds = SaleItemOption::whereIn('sale_item_id', $saleItemIds)->pluck('id');

            $movements = InventoryMovement::where('type', 'sale')
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
                $reversalQuantity = -$originalMovement->quantity_changed;
                InventoryMovement::create([
                    'ingredient_id'    => $originalMovement->ingredient_id,
                    'quantity_changed' => $reversalQuantity,
                    'type'             => 'adjustment',
                    'notes'            => 'Reverso por edición manual de Venta Ticket #' . $sale->order_number,
                    'performed_by'     => $superAdminId,
                    'reference_type'   => $originalMovement->reference_type,
                    'reference_id'     => $originalMovement->reference_id,
                ]);

                $ingredient = Ingredient::find($originalMovement->ingredient_id);
                if ($ingredient) {
                    $ingredient->current_stock += $reversalQuantity;
                    $ingredient->save();
                }
            }

            // 2. Revertir puntos de lealtad previos
            $config = LoyaltyConfig::active();
            if ($config && $config->accumulation_rate > 0 && $previousCustomer) {
                $pointsToDeduct = (int) floor($previousTotal / $config->accumulation_rate);
                $this->customerRepository->incrementPoints($previousCustomer, -$pointsToDeduct);
            }

            // 3. Eliminar opciones, items y pagos anteriores
            SaleItemOption::whereIn('sale_item_id', $saleItemIds)->delete();
            $sale->items()->delete();
            $sale->payments()->delete();

            // 4. Actualizar cabecera de la venta
            $createdAt = isset($data['created_at']) ? Carbon::parse($data['created_at']) : $sale->created_at;
            $itemsCollection = collect($data['items'] ?? []);
            $totalItemsCount = $itemsCollection->count();
            $takeawayItemsCount = $itemsCollection->filter(fn($i) => !empty($i['is_takeaway']))->count();
            $orderIsTakeaway = !empty($data['is_takeaway']) || ($totalItemsCount > 0 && $takeawayItemsCount === $totalItemsCount);

            $cashierId = $data['cashier_id'] ?? $sale->cashier_id;

            $sale->update([
                'cashier_id'      => $cashierId,
                'customer_id'     => $data['customer_id'] ?? null,
                'subtotal'        => $data['subtotal'],
                'discount_amount' => $data['discount_amount'] ?? 0,
                'total_amount'    => $data['total_amount'],
                'is_takeaway'     => $orderIsTakeaway,
                'notes'           => $data['notes'] ?? null,
                'edited_by'       => $superAdminId,
                'edited_at'       => now(),
                'edit_reason'     => $data['edit_reason'],
                'created_at'      => $createdAt,
            ]);

            // 5. Insertar nuevos items y descontar nuevo inventario
            $this->insertSaleItems($sale, $data['items'], $cashierId, $orderIsTakeaway);

            // 6. Insertar nuevos pagos
            if (!empty($data['payments'])) {
                foreach ($data['payments'] as $paymentData) {
                    SalePayment::create([
                        'sale_id' => $sale->id,
                        'method'  => $paymentData['method'],
                        'amount'  => $paymentData['amount'],
                    ]);
                }
            }

            // 7. Acumular nuevos puntos de lealtad
            if ($sale->customer_id && $sale->status === 'completed') {
                $this->updateLoyaltyPoints($sale);
            }

            // 8. Registro en log de auditoría
            AuditLog::create([
                'user_id'     => $superAdminId,
                'action'      => 'admin_sale_edit',
                'module'      => 'Sales',
                'description' => "Edición manual de venta Ticket #{$sale->order_number} por Super Admin. Total anterior: Bs {$previousTotal}, Nuevo total: Bs {$sale->total_amount}. Motivo: {$data['edit_reason']}",
            ]);

            return $sale->fresh(['cashier:id,name', 'customer:id,name,ci_or_phone', 'items.product:id,name', 'items.saleItemOptions.option:id,name', 'payments', 'editedByUser:id,name']);
        });
    }

    /**
     * Acumula puntos de lealtad del cliente usando actualización atómica.
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
            $saleItemOptionIds = SaleItemOption::whereIn('sale_item_id', $saleItemIds)->pluck('id');

            $movements = InventoryMovement::where('type', 'sale')
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
                $reversalQuantity = -$originalMovement->quantity_changed;

                InventoryMovement::create([
                    'ingredient_id'    => $originalMovement->ingredient_id,
                    'quantity_changed' => $reversalQuantity,
                    'type'             => 'adjustment',
                    'notes'            => 'Reverso por anulación de Venta UUID: ' . $sale->id,
                    'performed_by'     => $userId,
                    'reference_type'   => $originalMovement->reference_type,
                    'reference_id'     => $originalMovement->reference_id,
                ]);

                $ingredient = Ingredient::find($originalMovement->ingredient_id);
                if ($ingredient) {
                    $ingredient->current_stock += $reversalQuantity;
                    $ingredient->save();
                }
            }

            $config = LoyaltyConfig::active();
            if ($config && $config->accumulation_rate > 0 && $sale->customer_id) {
                $customer = $this->customerRepository->find($sale->customer_id);
                if ($customer) {
                    $pointsToDeduct = (int) floor($sale->total_amount / $config->accumulation_rate);
                    $this->customerRepository->incrementPoints($customer, -$pointsToDeduct);
                }
            }
        });
    }
}
