<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Punto de reorden y compras sugeridas (plan de inventario, F4, §2.2 a §2.5).
 *
 * Para cada insumo con stock calcula, con el consumo real de las ventas:
 *
 *  - **d**, el consumo diario: promedio de los últimos 28 días con ventas (ventas menos reversos). Con 8 semanas o
 *    más de historia se proyecta por día de la semana, porque el fin de semana vende distinto;
 *  - **SS**, el stock de seguridad: `1,65 × desvío × √L` con 28 días de datos; con menos, `días de seguridad × d`;
 *  - **PR**, el punto de reorden: `consumo durante L días + SS` (L = días que tarda el proveedor);
 *  - la **cantidad sugerida**: `consumo durante T días + SS − stock`, redondeada hacia arriba a unidades de
 *    compra y al pedido mínimo, y limitada a lo que se consume dentro de la vida útil en los perecederos.
 *
 * Mientras no hay 28 días de ventas usa el consumo estimado por el dueño (o, con 3 a 27 días, el promedio de
 * lo vendido) y el `minimum_stock` del insumo hace de punto de reorden manual (nunca baja el calculado).
 * Nada se guarda ni se envía: el estado se calcula al consultar.
 *
 * Las ventas y sus reversos: una venta anulada o editada deja un movimiento `adjustment` con la misma referencia
 * que el original (H19). Aquí se resta del día del original, así que anular una venta de ayer baja el consumo de
 * ayer y no el de hoy. La fecha de cada consumo es la de la venta (una venta cargada tarde o sincronizada después
 * no desplaza el consumo al día en que se cargó).
 */
class ReorderService
{
    /** Factor z del desvío: cubre el 95 % de los días. */
    public const Z = 1.65;

    /** Días con ventas que entran al promedio y al desvío. */
    public const WINDOW_DAYS = 28;

    /** Cuánto se mira hacia atrás para encontrar esos días y las semanas completas (12 semanas). */
    public const LOOKBACK_DAYS = 84;

    /** Historia mínima (días entre la primera venta y ayer) para proyectar por día de la semana. */
    public const WEEKDAY_MIN_SPAN = 56;

    /** Con menos días con ventas que estos no se calcula nada desde las ventas. */
    public const MIN_PARTIAL_DAYS = 3;

    public const DEFAULT_TARGET_DAYS = 7;
    public const DEFAULT_SAFETY_DAYS = 2.0;
    public const DEFAULT_LEAD_DAYS = 1;

    /** Un vencimiento se avisa dentro de este número de días o del 20 % de la vida útil, lo que sea mayor. */
    public const EXPIRY_WARNING_DAYS = 2;
    public const EXPIRY_WARNING_SHARE = 0.20;

    // ── Fórmulas puras (las usan los cálculos y las pruebas con las cifras del plan §2.3) ─────────────────────────

    /** Stock de seguridad: con datos `z × desvío × √L`; con pocos, `días de seguridad × d`. */
    public static function safetyStock(float $dailyUse, float $sigma, int $leadDays, float $safetyDays, bool $enoughData): float
    {
        return $enoughData
            ? self::Z * $sigma * sqrt(max($leadDays, 0))
            : $safetyDays * $dailyUse;
    }

    /** Punto de reorden: lo que se consume mientras llega el pedido más el colchón. */
    public static function reorderPoint(float $demandDuringLead, float $safetyStock): float
    {
        return $demandDuringLead + $safetyStock;
    }

    /** Cantidad a pedir (en la unidad del insumo) para volver a `T` días de cobertura. Nunca negativa. */
    public static function suggestedQuantity(float $demandDuringTarget, float $safetyStock, float $stock, float $incoming = 0.0): float
    {
        return max(0.0, $demandDuringTarget + $safetyStock - $stock - $incoming);
    }

    /**
     * Pasa una cantidad en la unidad del insumo a unidades de compra enteras (bolsa, caja…) y respeta el pedido
     * mínimo del proveedor.
     *
     * @return array{units: float, quantity: float} unidades de compra y su equivalente en la unidad del insumo
     */
    public static function toPurchaseUnits(float $quantity, float $factor, ?float $minimumOrder): array
    {
        $factor = $factor > 0 ? $factor : 1.0;
        // La resta evita que 3,0000000001 por error de coma flotante pida una unidad de más
        $units = ceil(round($quantity / $factor, 6));
        if ($minimumOrder !== null && $minimumOrder > 0) {
            $units = max($units, $minimumOrder);
        }
        $units = max($units, 0.0);

        return ['units' => $units, 'quantity' => round($units * $factor, 4)];
    }

    // ── Consumo real ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Consumo neto por insumo y día (ventas menos reversos), con la fecha de la venta.
     *
     * @return array{days: array<int, string>, by_ingredient: array<int, array<string, float>>} días con ventas (de
     *         más antiguo a más reciente, sin hoy) y consumo de cada insumo en cada uno
     */
    public function consumption(Carbon $today): array
    {
        $start = $today->copy()->subDays(self::LOOKBACK_DAYS)->startOfDay();
        $todayKey = $today->toDateString();

        // Los movimientos nunca son anteriores a su venta: con este piso se encuentran también las ventas cargadas tarde
        $rows = DB::table('inventory_movements')
            ->where('created_at', '>=', $start->toDateTimeString())
            ->where(function ($q) {
                $q->where('type', 'sale')
                    ->orWhere(function ($q2) {
                        $q2->where('type', 'adjustment')->whereIn('reference_type', ['sale_item', 'sale_item_option']);
                    });
            })
            ->get(['ingredient_id', 'quantity_changed', 'type', 'reference_type', 'reference_id', 'created_at']);

        $saleDates = $this->saleDates($rows);

        $byIngredient = [];
        $dayOfSource = []; // clave de la venta original → día al que pertenece, para ubicar sus reversos
        $sales = $rows->where('type', 'sale');
        foreach ($sales as $row) {
            $key = $this->sourceKey($row);
            $day = $saleDates[$row->reference_type . '|' . $row->reference_id] ?? substr((string) $row->created_at, 0, 10);
            $dayOfSource[$key] = $day;
            $byIngredient[$row->ingredient_id][$day] = ($byIngredient[$row->ingredient_id][$day] ?? 0.0) - (float) $row->quantity_changed;
        }
        foreach ($rows->where('type', 'adjustment') as $row) {
            $day = $dayOfSource[$this->sourceKey($row)] ?? null;
            if ($day === null) {
                continue; // reverso de una venta anterior a la ventana: su original tampoco cuenta
            }
            $byIngredient[$row->ingredient_id][$day] = ($byIngredient[$row->ingredient_id][$day] ?? 0.0) - (float) $row->quantity_changed;
        }

        // Solo cuentan los días completos de la ventana; los que quedaron sin consumo neto (todo anulado) no son días de venta
        $totalByDay = [];
        foreach ($byIngredient as $id => $days) {
            foreach ($days as $day => $quantity) {
                if ($day < $start->toDateString() || $day >= $todayKey) {
                    unset($byIngredient[$id][$day]);
                    continue;
                }
                $totalByDay[$day] = ($totalByDay[$day] ?? 0.0) + max($quantity, 0.0);
            }
        }
        $days = array_keys(array_filter($totalByDay, fn ($total) => $total > InventoryService::EPSILON));
        sort($days);

        return ['days' => $days, 'by_ingredient' => $byIngredient];
    }

    private function sourceKey(object $row): string
    {
        return $row->ingredient_id . '|' . $row->reference_type . '|' . $row->reference_id;
    }

    /**
     * Fecha de la venta de cada línea movida. Una línea eliminada (venta editada) ya no se encuentra: su consumo
     * y el reverso quedan en el día del movimiento, que es donde se anulan entre sí.
     *
     * @return array<string, string> "sale_item|12" → "2026-10-05"
     */
    private function saleDates($rows): array
    {
        $itemIds = $rows->where('reference_type', 'sale_item')->pluck('reference_id')->unique()->all();
        $optionIds = $rows->where('reference_type', 'sale_item_option')->pluck('reference_id')->unique()->all();

        $optionItem = $optionIds ? DB::table('sale_item_options')->whereIn('id', $optionIds)->pluck('sale_item_id', 'id')->all() : [];
        $allItems = array_values(array_unique(array_merge($itemIds, array_values($optionItem))));
        $itemDate = [];
        foreach (array_chunk($allItems, 500) as $chunk) {
            foreach (DB::table('sale_items')->whereIn('id', $chunk)->pluck('created_at', 'id') as $id => $createdAt) {
                $itemDate[$id] = substr((string) $createdAt, 0, 10);
            }
        }

        $dates = [];
        foreach ($itemIds as $id) {
            if (isset($itemDate[$id])) {
                $dates['sale_item|' . $id] = $itemDate[$id];
            }
        }
        foreach ($optionItem as $optionId => $itemId) {
            if (isset($itemDate[$itemId])) {
                $dates['sale_item_option|' . $optionId] = $itemDate[$itemId];
            }
        }

        return $dates;
    }

    /**
     * Estadísticas de consumo de un insumo a partir de la serie de todos los días con ventas.
     *
     * @return array{days: int, mean: float, sigma: float, weekday: ?array<int, float>}
     */
    public function stats(array $operatingDays, array $series): array
    {
        $recent = array_slice($operatingDays, -self::WINDOW_DAYS);
        $values = array_map(fn ($day) => (float) ($series[$day] ?? 0.0), $recent);
        $n = count($values);
        $mean = $n ? array_sum($values) / $n : 0.0;
        $variance = $n > 1 ? array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1) : 0.0;

        $weekday = null;
        if ($n >= self::WINDOW_DAYS && $operatingDays !== []) {
            $span = Carbon::parse($operatingDays[0])->diffInDays(Carbon::parse(end($operatingDays))) + 1;
            if ($span >= self::WEEKDAY_MIN_SPAN) {
                $byDow = [];
                foreach ($operatingDays as $day) {
                    $byDow[Carbon::parse($day)->dayOfWeek][] = (float) ($series[$day] ?? 0.0);
                }
                // Un día de la semana sin ventas (el local cierra) proyecta cero
                $weekday = [];
                for ($dow = 0; $dow < 7; $dow++) {
                    $weekday[$dow] = isset($byDow[$dow]) ? array_sum($byDow[$dow]) / count($byDow[$dow]) : 0.0;
                }
            }
        }

        return ['days' => $n, 'mean' => $mean, 'sigma' => sqrt($variance), 'weekday' => $weekday];
    }

    /** Consumo esperado en los próximos `$days` días contando hoy: por día de la semana si hay base, si no d × días. */
    public static function demandOver(float $dailyUse, ?array $weekday, int $days, Carbon $today): float
    {
        if ($days <= 0) {
            return 0.0;
        }
        if ($weekday === null) {
            return $dailyUse * $days;
        }
        $total = 0.0;
        for ($i = 0; $i < $days; $i++) {
            $total += $weekday[$today->copy()->addDays($i)->dayOfWeek];
        }

        return $total;
    }

    // ── Informe ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Estado de reposición de cada insumo con control de stock.
     *
     * @return array{generated_at: string, summary: array, items: array<int, array>}
     */
    public function report(?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now(config('app.timezone')))->copy();
        $today = $now->copy()->startOfDay();

        $consumption = $this->consumption($today);
        $suppliers = Supplier::all()->keyBy('id');
        $ingredients = Ingredient::where('tracks_stock', true)->orderBy('name')->get();

        $items = [];
        foreach ($ingredients as $ingredient) {
            $items[] = $this->item($ingredient, $consumption, $suppliers, $today);
        }

        // Lo que hay que atender primero: agotado, crítico, por reordenar, vence pronto
        $rank = ['out' => 0, 'critical' => 1, 'reorder' => 2, 'daily' => 3, 'nodata' => 4, 'ok' => 5, 'none' => 6];
        usort($items, fn ($a, $b) => [$rank[$a['status']], $a['name']] <=> [$rank[$b['status']], $b['name']]);

        return [
            'generated_at' => $now->toIso8601String(),
            'operating_days' => count($consumption['days']),
            'summary' => $this->summarize($items),
            'items' => $items,
        ];
    }

    /** Solo los conteos y los insumos urgentes: para la marca del menú, la tarjeta de Finanzas y el aviso al abrir caja. */
    public function summary(?Carbon $now = null): array
    {
        $report = $this->report($now);
        $urgent = array_values(array_filter($report['items'], fn ($i) => in_array($i['status'], ['out', 'critical'], true) || $i['expiry']['level'] === 'expired'));

        return $report['summary'] + [
            'urgent' => array_map(fn ($i) => [
                'id' => $i['id'], 'name' => $i['name'], 'unit' => $i['unit'], 'status' => $i['status'],
                'stock' => $i['stock'], 'days_of_stock' => $i['days_of_stock'],
            ], array_slice($urgent, 0, 8)),
            'expiring_items' => array_map(fn ($i) => [
                'id' => $i['id'], 'name' => $i['name'], 'unit' => $i['unit'], 'stock' => $i['stock'], 'expiry' => $i['expiry'],
            ], array_slice(array_values(array_filter($report['items'], fn ($i) => $i['expiry']['level'] !== null)), 0, 8)),
        ];
    }

    private function summarize(array $items): array
    {
        $count = fn (string $status) => count(array_filter($items, fn ($i) => $i['status'] === $status));
        $toBuy = array_filter($items, fn ($i) => in_array($i['status'], ['out', 'critical', 'reorder'], true));

        return [
            'to_reorder' => count($toBuy),
            'critical'   => $count('critical'),
            'out'        => $count('out'),
            'daily'      => count(array_filter($items, fn ($i) => $i['status'] === 'daily' && ($i['suggested']['units'] ?? 0) > 0)),
            'no_data'    => $count('nodata'),
            'expiring'   => count(array_filter($items, fn ($i) => $i['expiry']['level'] !== null)),
            'estimated_cost' => round(array_sum(array_map(fn ($i) => $i['suggested']['cost'] ?? 0, $toBuy)), 2),
        ];
    }

    private function item(Ingredient $ingredient, array $consumption, $suppliers, Carbon $today): array
    {
        $policy = $ingredient->reorder_policy ?: 'reorder';
        $supplier = $ingredient->supplier_id ? $suppliers->get($ingredient->supplier_id) : null;
        $lead = max((int) ($supplier?->delivery_days ?? self::DEFAULT_LEAD_DAYS), 0);
        $stock = (float) $ingredient->current_stock;
        $manualPoint = (float) $ingredient->minimum_stock;
        $estimate = $ingredient->daily_estimate !== null ? (float) $ingredient->daily_estimate : null;
        $safetyDays = $ingredient->safety_days !== null ? (float) $ingredient->safety_days : self::DEFAULT_SAFETY_DAYS;
        $isDaily = $policy === 'daily';
        $target = $isDaily ? 1 : (int) ($ingredient->target_days ?: self::DEFAULT_TARGET_DAYS);
        $target = max($target, $isDaily ? 1 : $lead);

        // Los días cuentan desde que el insumo se usó por primera vez: uno que entró a una receta hace una semana no
        // se promedia con las semanas anteriores en que nadie lo descontaba
        $series = $consumption['by_ingredient'][$ingredient->id] ?? [];
        $firstUse = null;
        foreach ($series as $day => $quantity) {
            if ($quantity > InventoryService::EPSILON && ($firstUse === null || $day < $firstUse)) {
                $firstUse = $day;
            }
        }
        $operatingDays = $firstUse === null ? [] : array_values(array_filter($consumption['days'], fn ($day) => $day >= $firstUse));
        $stats = $this->stats($operatingDays, $series);

        // De dónde sale el consumo diario
        if ($stats['days'] >= self::WINDOW_DAYS) {
            $basis = 'real';
        } elseif ($estimate !== null && $estimate > 0) {
            $basis = 'estimate';
        } elseif ($stats['days'] >= self::MIN_PARTIAL_DAYS && $stats['mean'] > 0) {
            $basis = 'partial';
        } elseif ($manualPoint > 0) {
            $basis = 'manual';
        } else {
            $basis = 'none';
        }

        $weekday = null;
        $sigma = 0.0;
        $daily = 0.0;
        if ($basis === 'real') {
            $daily = $stats['mean'];
            $sigma = $stats['sigma'];
            $weekday = $stats['weekday'];
        } elseif ($basis === 'estimate') {
            $daily = $estimate;
        } elseif ($basis === 'partial') {
            $daily = $stats['mean'];
        }

        $demandLead = self::demandOver($daily, $weekday, $lead, $today);
        $demandTarget = self::demandOver($daily, $weekday, $target, $today);
        $safety = $isDaily ? 0.0 : self::safetyStock($daily, $sigma, $lead, $safetyDays, $basis === 'real');
        $computedPoint = $daily > 0 ? self::reorderPoint($demandLead, $safety) : 0.0;
        $point = max($computedPoint, $manualPoint);

        $purchaseFactor = (float) ($ingredient->purchase_factor ?: 1);
        $purchaseUnit = $ingredient->purchase_unit ?: $ingredient->unit;
        $lot = $ingredient->lot_size !== null ? (float) $ingredient->lot_size : null;

        $status = $this->status($policy, $stock, $daily, $point, $demandLead);
        $suggested = $this->suggestion($ingredient, $status, $basis, $daily, $demandTarget, $safety, $stock, $point, $manualPoint, $purchaseFactor, $lot, $weekday, $today);

        return [
            'id'            => $ingredient->id,
            'name'          => $ingredient->name,
            'unit'          => $ingredient->unit,
            'policy'        => $policy,
            'status'        => $status,
            'basis'         => $basis,
            'is_key'        => (bool) $ingredient->is_key,
            'stock'         => round($stock, 4),
            'daily_use'     => round($daily, 4),
            'sigma'         => round($sigma, 4),
            'days_of_data'  => $stats['days'],
            'by_weekday'    => $weekday !== null,
            'lead_days'     => $lead,
            'target_days'   => $target,
            'safety_stock'  => round($safety, 4),
            'reorder_point' => round($point, 4),
            'manual_point'  => $manualPoint > 0 ? round($manualPoint, 4) : null,
            'days_of_stock' => $daily > 0 ? round(max($stock, 0.0) / $daily, 1) : null,
            'supplier'      => $supplier ? ['id' => $supplier->id, 'name' => $supplier->name, 'phone' => $supplier->phone, 'delivery_days' => (int) $supplier->delivery_days, 'order_days' => $supplier->order_days] : null,
            'purchase_unit' => $purchaseUnit,
            'purchase_factor' => $purchaseFactor,
            'lot_size'      => $lot,
            'unit_cost'     => round($ingredient->costPerUnit(), 4),
            'suggested'     => $suggested,
            'expiry'        => $this->expiry($ingredient, $today),
        ];
    }

    /** Estado de reposición (§2.5). El agotado exige alguna señal de uso: un insumo sin movimiento ni consumo no es un quiebre. */
    private function status(string $policy, float $stock, float $daily, float $point, float $demandLead): string
    {
        if ($policy === 'none') {
            return 'none';
        }
        $hasSignal = $daily > 0 || $point > 0;
        if ($stock < -InventoryService::EPSILON || ($stock <= InventoryService::EPSILON && $hasSignal)) {
            return $policy === 'daily' ? 'daily' : 'out';
        }
        if ($policy === 'daily') {
            return 'daily';
        }
        if (!$hasSignal) {
            return 'nodata';
        }
        if ($daily > 0 && $stock <= $demandLead) {
            return 'critical';
        }

        return $stock <= $point ? 'reorder' : 'ok';
    }

    /**
     * Cuánto pedir. Solo hay sugerencia cuando hay algo que reponer (reordenar, crítico, agotado) o es compra diaria.
     *
     * @return array{quantity: float, units: float, purchase_unit: string, cost: float, limited_by_shelf_life: bool, basis_note: ?string}|null
     */
    private function suggestion(Ingredient $ingredient, string $status, string $basis, float $daily, float $demandTarget, float $safety, float $stock, float $point, float $manualPoint, float $factor, ?float $lot, ?array $weekday, Carbon $today): ?array
    {
        if (!in_array($status, ['out', 'critical', 'reorder', 'daily'], true)) {
            return null;
        }

        if ($daily > 0) {
            $quantity = self::suggestedQuantity($demandTarget, $safety, $stock);
        } else {
            // Solo hay un punto de reorden manual: se repone hasta el doble de ese punto
            $quantity = max(0.0, $manualPoint * 2 - $stock);
        }

        $limited = false;
        $shelfLife = $ingredient->shelf_life_days ? (int) $ingredient->shelf_life_days : null;
        if ($shelfLife !== null && $daily > 0) {
            // Lo que ya hay más lo que se compra no debe pasar de lo que se consume antes de que venza
            $cap = max(0.0, self::demandOver($daily, $weekday, $shelfLife, $today) - max($stock, 0.0));
            if ($quantity > $cap + InventoryService::EPSILON) {
                $quantity = $cap;
                $limited = true;
            }
        }

        $isDaily = $status === 'daily';
        if ($quantity <= InventoryService::EPSILON && !$isDaily) {
            // Hay que reponer pero la cuenta da cero (T muy corto): al menos un pedido mínimo
            $quantity = $factor * max($lot ?? 1.0, 1.0);
        }
        if ($quantity <= InventoryService::EPSILON) {
            return ['quantity' => 0.0, 'units' => 0.0, 'purchase_unit' => $ingredient->purchase_unit ?: $ingredient->unit, 'cost' => 0.0, 'limited_by_shelf_life' => $limited];
        }

        $purchase = self::toPurchaseUnits($quantity, $factor, $lot);

        return [
            'quantity'      => $purchase['quantity'],
            'units'         => $purchase['units'],
            'purchase_unit' => $ingredient->purchase_unit ?: $ingredient->unit,
            'cost'          => round($purchase['quantity'] * $ingredient->costPerUnit(), 2),
            'limited_by_shelf_life' => $limited,
        ];
    }

    /**
     * Aviso de vencimiento: vence dentro de 2 días o del 20 % de la vida útil. `level` es null, `soon` o `expired`.
     *
     * @return array{level: ?string, date: ?string, days_left: ?int}
     */
    private function expiry(Ingredient $ingredient, Carbon $today): array
    {
        if (!$ingredient->expiration_date) {
            return ['level' => null, 'date' => null, 'days_left' => null];
        }

        $daysLeft = (int) $today->diffInDays($ingredient->expiration_date->copy()->startOfDay(), false);
        $window = self::EXPIRY_WARNING_DAYS;
        if ($ingredient->shelf_life_days) {
            $window = max($window, (int) ceil($ingredient->shelf_life_days * self::EXPIRY_WARNING_SHARE));
        }

        $level = null;
        if ((float) $ingredient->current_stock > 0) {
            $level = $daysLeft < 0 ? 'expired' : ($daysLeft <= $window ? 'soon' : null);
        }

        return ['level' => $level, 'date' => $ingredient->expiration_date->toDateString(), 'days_left' => $daysLeft];
    }
}
