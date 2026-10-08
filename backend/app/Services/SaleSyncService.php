<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\CashRegisterSession;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleItemOption;
use App\Models\LoyaltyConfig;
use App\Models\Option;
use App\Models\Product;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Ingredient;
use App\Repositories\SaleRepository;
use App\Repositories\ProductRepository;
use App\Repositories\CustomerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
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
        private readonly SalePricingService  $pricingService,
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
        // El servidor recalcula subtotales, total y pagos (ver SalePricingService)
        [$data, $discrepancies] = $this->pricingService->normalizeTotals($data);
        [$data['payments'], $paymentIssues] = $this->normalizeChannelPayments($data);
        $discrepancies = array_merge($discrepancies, $paymentIssues);

        // El POS envía la hora en UTC (ISO 8601); se guarda en la zona horaria de la app,
        // igual que opened_at/closed_at de las sesiones de caja.
        $createdAt = isset($data['created_at'])
            ? Carbon::parse($data['created_at'])->setTimezone(config('app.timezone'))
            : now();

        // Se respeta el número impreso en el ticket del POS; solo se genera uno nuevo
        // si no viene o si ya lo usó otra venta (p. ej. otro dispositivo offline).
        $dailySeq = $data['daily_sequence'] ?? null;
        $orderNumber = $data['order_number'] ?? null;

        if (!$orderNumber || Sale::withTrashed()->where('order_number', $orderNumber)->exists()) {
            [$orderNumber, $dailySeq] = $this->nextOrderNumber($createdAt);
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
        $priceAdjustments = [];
        $priceMismatches = $this->insertSaleItems($sale, $data['items'], $cashierId, $orderIsTakeaway, true, $priceAdjustments);
        $discrepancies = array_merge($discrepancies, $priceMismatches);

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

        $this->auditClosedSessionChange($sale, $sale->cash_register_session_id, $cashierId, 'Venta sincronizada tarde');

        // 5. Dejar constancia de cualquier diferencia entre el POS y el servidor
        $this->auditPriceAdjustments($priceAdjustments, $orderNumber, $cashierId);

        if (!empty($discrepancies)) {
            AuditLog::create([
                'user_id'     => $cashierId,
                'action'      => 'sale_sync_discrepancy',
                'module'      => 'Sales',
                'description' => "Venta Ticket #{$orderNumber} sincronizada con diferencias: " . implode('; ', $discrepancies),
            ]);
        }
    }

    /**
     * Inserta los ítems de una venta y descuenta el stock de recetas e insumos.
     *
     * @param bool $checkCatalogPrices Compara cada precio cobrado con el catálogo vigente.
     * @param array $priceAdjustments Recibe los ajustes de precio con motivo (ver resolvePriceOverride).
     * @return string[] Diferencias de precio encontradas (vacío si no se verifican).
     */
    public function insertSaleItems(Sale $sale, array $itemsData, int $cashierId, bool $orderIsTakeaway, bool $checkCatalogPrices = false, array &$priceAdjustments = []): array
    {
        $priceMismatches = [];
        $source = $sale->source ?? 'pos';

        foreach ($itemsData as $itemData) {
            $product = $this->productRepository->findWithRecipes($itemData['product_id']);
            $modifiers = $itemData['modifiers'] ?? $itemData['topping_modifications'] ?? [];
            
            $selectedOptionIds = array_column($modifiers, 'option_id');
            $selectedOptions = Option::with('optionGroup')->whereIn('id', $selectedOptionIds)->get()->keyBy('id');
            
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

            $overrideReason = $this->resolvePriceOverride($product, $itemData, $selectedOptions, $source, $priceAdjustments);

            if ($checkCatalogPrices) {
                // Un ajuste con motivo no es una discrepancia: queda en su propio registro de auditoría
                $mismatch = $overrideReason === null
                    ? $this->pricingService->catalogPriceMismatch($product, $itemData, $selectedOptions, $source)
                    : null;
                if ($mismatch) {
                    $priceMismatches[] = $mismatch;
                }

                // Un POS con catálogo en caché puede vender algo que ya no se ofrece en PedidosYa:
                // la venta ya se cobró, así que se acepta y queda constancia.
                if ($source === 'pedidosya' && $product->available_on_pedidosya === false) {
                    $priceMismatches[] = "{$product->name}: no está disponible en PedidosYa";
                }
            }

            $itemIsTakeaway = $orderIsTakeaway ? true : !empty($itemData['is_takeaway']);

            $saleItem = SaleItem::create([
                'sale_id'        => $sale->id,
                'product_id'     => $product->id,
                'quantity'       => $itemData['quantity'],
                'unit_price'     => $itemData['unit_price'],
                'price_override_reason' => $overrideReason,
                'subtotal'       => $itemData['subtotal'],
                // Gramos de un producto por peso (Autoservicio), para costearlo
                'weight_grams'   => $product->is_weight_based && !empty($itemData['weight_grams']) ? (float) $itemData['weight_grams'] : null,
                'is_takeaway'    => $itemIsTakeaway,
                'item_note'      => $itemData['item_note'] ?? null,
                'allergen_flags' => isset($itemData['allergen_flags']) && is_array($itemData['allergen_flags']) ? $itemData['allergen_flags'] : null,
                'created_at'     => $sale->created_at,
                'updated_at'     => $sale->created_at,
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
                        'additional_price_snapshot' => SalePricingService::optionPrice($opt, $sale->source ?? 'pos'),
                        'quantity'                  => $mod['quantity'] ?? 1,
                        'created_at'                => $sale->created_at,
                        'updated_at'                => $sale->created_at,
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

        return $priceMismatches;
    }

    /**
     * Crea una venta retroactiva o manual por un Super Admin.
     */
    public function adminCreateSale(array $data, int $superAdminId): Sale
    {
        return DB::transaction(function () use ($data, $superAdminId) {
            $createdAt = isset($data['created_at']) 
                ? Carbon::parse($data['created_at'])->setTimezone(config('app.timezone', 'America/La_Paz')) 
                : now();
            [$orderNumber, $dailySeq] = $this->nextOrderNumber($createdAt);

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
                // Permite registrar el canal de origen ('pedidosya' o 'pos') para trazabilidad y arqueo de caja
                'source'             => $data['source'] ?? 'manual_retroactive',
                'is_takeaway'        => $orderIsTakeaway,
                'notes'              => $data['notes'] ?? null,
                'edit_reason'        => $data['edit_reason'],
                'edited_by'          => $superAdminId,
                'edited_at'          => now(),
                'sync_status'        => 'synced',
                'created_at'         => $createdAt,
            ]);

            $sale->created_at = $createdAt;
            $sale->saveQuietly();

            $priceAdjustments = [];
            $this->insertSaleItems($sale, $data['items'], $cashierId, $orderIsTakeaway, false, $priceAdjustments);
            $this->auditPriceAdjustments($priceAdjustments, $orderNumber, $superAdminId);

            [$data['payments'], $paymentIssues] = $this->platformPaymentsIfPedidosYa($sale, $data);

            if (!empty($data['payments'])) {
                foreach ($data['payments'] as $paymentData) {
                    SalePayment::create([
                        'sale_id'    => $sale->id,
                        'method'     => $paymentData['method'],
                        'amount'     => $paymentData['amount'],
                        'created_at' => $sale->created_at,
                        'updated_at' => $sale->created_at,
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
            $this->auditPaymentIssues($paymentIssues, $orderNumber, $superAdminId);

            $this->auditClosedSessionChange($sale, $sale->cash_register_session_id, $superAdminId, 'Venta retroactiva');

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
            $previousSessionId = $sale->cash_register_session_id;
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
            $createdAt = isset($data['created_at']) 
                ? Carbon::parse($data['created_at'])->setTimezone(config('app.timezone', 'America/La_Paz')) 
                : $sale->created_at;
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
                // Preservar o actualizar el canal de origen de la venta (ej. 'pedidosya')
                'source'          => $data['source'] ?? $sale->source,
                'is_takeaway'     => $orderIsTakeaway,
                'notes'           => $data['notes'] ?? null,
                'edited_by'       => $superAdminId,
                'edited_at'       => now(),
                'edit_reason'     => $data['edit_reason'],
                'created_at'      => $createdAt,
            ]);

            $sale->created_at = $createdAt;
            $sale->saveQuietly();

            // 5. Insertar nuevos items y descontar nuevo inventario
            $priceAdjustments = [];
            $this->insertSaleItems($sale, $data['items'], $cashierId, $orderIsTakeaway, false, $priceAdjustments);
            $this->auditPriceAdjustments($priceAdjustments, $sale->order_number, $superAdminId);

            // 6. Insertar nuevos pagos (PedidosYa: un único pago de plataforma)
            [$data['payments'], $paymentIssues] = $this->platformPaymentsIfPedidosYa($sale, $data);
            $this->auditPaymentIssues($paymentIssues, $sale->order_number, $superAdminId);

            if (!empty($data['payments'])) {
                foreach ($data['payments'] as $paymentData) {
                    SalePayment::create([
                        'sale_id'    => $sale->id,
                        'method'     => $paymentData['method'],
                        'amount'     => $paymentData['amount'],
                        'created_at' => $sale->created_at,
                        'updated_at' => $sale->created_at,
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

            $this->auditClosedSessionChange($sale, $previousSessionId, $superAdminId, 'Edición de venta');
            if ($sale->cash_register_session_id !== $previousSessionId) {
                $this->auditClosedSessionChange($sale, $sale->cash_register_session_id, $superAdminId, 'Edición de venta');
            }

            return $sale->fresh(['cashier:id,name', 'customer:id,name,ci_or_phone', 'items.product:id,name', 'items.saleItemOptions.option:id,name', 'payments', 'editedByUser:id,name']);
        });
    }

    /**
     * Decide qué motivo de ajuste de precio se guarda en la línea.
     *
     * Solo los pedidos PedidosYa admiten ajustar el precio de una línea (promo de la plataforma,
     * precio desactualizado). Si el precio coincide con el tarifario no hay ajuste; en una venta
     * del local el motivo se descarta y queda constancia.
     *
     * @param array $priceAdjustments Acumula ['type' => 'override'|'ignored', 'text' => string].
     */
    protected function resolvePriceOverride(Product $product, array $itemData, Collection $selectedOptions, string $source, array &$priceAdjustments): ?string
    {
        $reason = trim((string) ($itemData['price_override_reason'] ?? ''));
        if ($reason === '') {
            return null;
        }

        $charged = (float) $itemData['unit_price'];

        if ($source !== 'pedidosya') {
            $priceAdjustments[] = [
                'type' => 'ignored',
                'text' => "{$product->name}: ajuste de precio a {$charged} con motivo \"{$reason}\" ignorado (solo se permite en pedidos PedidosYa)",
            ];
            return null;
        }

        $expected = $this->pricingService->expectedUnitPrice($product, $itemData, $selectedOptions, $source);
        if (!$this->pricingService->differsFromExpected($expected, $charged)) {
            return null; // Cobrado a precio del tarifario: no hay ajuste que registrar
        }

        $priceAdjustments[] = [
            'type' => 'override',
            'text' => "{$product->name}: tarifario {$expected}, cobrado {$charged}. Motivo: {$reason}",
        ];

        return $reason;
    }

    /**
     * Deja en auditoría los ajustes de precio de PedidosYa y los motivos descartados.
     */
    protected function auditPriceAdjustments(array $priceAdjustments, string $orderNumber, int $userId): void
    {
        $byType = collect($priceAdjustments)->groupBy('type');

        if ($byType->has('override')) {
            AuditLog::create([
                'user_id'     => $userId,
                'action'      => 'pedidosya_price_override',
                'module'      => 'Sales',
                'description' => "Pedido PedidosYa Ticket #{$orderNumber} con precio ajustado: " . $byType['override']->pluck('text')->implode('; '),
            ]);
        }

        if ($byType->has('ignored')) {
            AuditLog::create([
                'user_id'     => $userId,
                'action'      => 'sale_sync_discrepancy',
                'module'      => 'Sales',
                'description' => "Venta Ticket #{$orderNumber}: " . $byType['ignored']->pluck('text')->implode('; '),
            ]);
        }
    }

    /**
     * Normaliza los pagos según el canal: PedidosYa va como un único pago de plataforma
     * por el total; el resto, con la regla de sobrepago (ver SalePricingService).
     *
     * @return array{0: array, 1: string[]} Pagos normalizados y discrepancias.
     */
    protected function normalizeChannelPayments(array $data): array
    {
        $payments = $data['payments'] ?? [];
        $total = (float) $data['total_amount'];

        return ($data['source'] ?? null) === 'pedidosya'
            ? $this->pricingService->normalizePlatformPayments($payments, $total)
            : $this->pricingService->normalizePayments($payments, $total);
    }

    /**
     * En registros y ediciones del admin solo se ajustan los pagos de PedidosYa;
     * los de venta local se guardan tal como se ingresaron.
     */
    protected function platformPaymentsIfPedidosYa(Sale $sale, array $data): array
    {
        if ($sale->source !== 'pedidosya') {
            return [$data['payments'] ?? [], []];
        }

        return $this->pricingService->normalizePlatformPayments($data['payments'] ?? [], (float) $sale->total_amount);
    }

    /**
     * Deja en auditoría los ajustes de pago hechos al registrar o editar una venta manual.
     */
    protected function auditPaymentIssues(array $issues, string $orderNumber, int $userId): void
    {
        if (empty($issues)) {
            return;
        }

        AuditLog::create([
            'user_id'     => $userId,
            'action'      => 'sale_sync_discrepancy',
            'module'      => 'Sales',
            'description' => "Venta Ticket #{$orderNumber} con pagos ajustados: " . implode('; ', $issues),
        ]);
    }

    /**
     * Calcula el siguiente número de orden libre del día (formato DDMMAA-NNNN).
     * Usa la secuencia máxima registrada en lugar del conteo, para no chocar
     * con números ya asignados por el POS.
     *
     * @return array{0: string, 1: int}
     */
    protected function nextOrderNumber(Carbon $createdAt): array
    {
        $dayQuery = Sale::withTrashed()->whereDate('created_at', $createdAt->toDateString());
        $dailySeq = max((int) (clone $dayQuery)->max('daily_sequence'), (clone $dayQuery)->count()) + 1;

        do {
            $orderNumber = sprintf('%s-%04d', $createdAt->format('dmy'), $dailySeq++);
        } while (Sale::withTrashed()->where('order_number', $orderNumber)->exists());

        return [$orderNumber, $dailySeq - 1];
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

            $this->auditClosedSessionChange($sale, $sale->cash_register_session_id, $userId, 'Anulación de venta');
        });
    }

    /**
     * Deja constancia cuando una venta entra, cambia o se anula en una caja ya cerrada.
     * El arqueo guardado al cierre no se modifica; el reporte de la sesión sí refleja el cambio.
     */
    protected function auditClosedSessionChange(Sale $sale, ?int $sessionId, int $userId, string $context): void
    {
        $session = $sessionId ? CashRegisterSession::find($sessionId) : null;
        if (!$session || $session->status !== 'closed') {
            return;
        }

        AuditLog::create([
            'user_id'     => $userId,
            'action'      => 'closed_session_sale_change',
            'module'      => 'Sales',
            'description' => "{$context} Ticket #{$sale->order_number} (Bs {$sale->total_amount}) afecta la caja #{$session->id}, cerrada el {$session->closed_at}. El arqueo guardado no cambia; revisa el reporte de esa sesión.",
        ]);
    }
}
