<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryCountLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores de inventario del mes (plan de inventario, F5, §5): lo que dice si el inventario está bien llevado.
 *
 *  - **Valor del inventario:** Σ stock × costo promedio (CPP), hoy.
 *  - **Días de cobertura:** stock ÷ consumo diario de cada insumo clave (hoy).
 *  - **Quiebres:** días de venta en que un insumo clave llegó a 0 o menos. Se reconstruye con los movimientos
 *    contando hacia atrás desde el stock actual, así no depende de que el stock coincida con la suma histórica.
 *  - **% de merma:** merma anotada en Bs ÷ consumo en Bs de las ventas del mes, en total y por insumo.
 *  - **Merma oculta:** la que encontraron los conteos (la calcula `WasteReportService`).
 *  - **Diferencia de costo:** costo real (compras) menos costo por recetas, en puntos de las ventas.
 *  - **Exactitud del conteo:** insumos contados con diferencia de hasta 2 % ÷ insumos contados (sin los primeros
 *    conteos, que solo ordenan el stock de partida).
 *
 * Las metas son iniciales y viven en `TARGETS`; cada indicador devuelve su estado (`ok`, `warn`, `err` o null si aún no
 * se puede medir). Los de «hoy» (valor y cobertura) no cambian con el mes elegido.
 */
class InventoryIndicatorsService
{
    public const TARGETS = [
        'waste_percent'            => 5.0,   // merma ≤ 5 % del consumo (insumos en general)
        'waste_percent_perishable' => 10.0,  // fruta y otros perecederos ≤ 10 %
        'cost_gap_points'          => 5.0,   // diferencia de costo ≤ 5 puntos de las ventas
        'accuracy_percent'         => 90.0,  // conteos exactos ≥ 90 %
        'accuracy_tolerance'       => 2.0,   // «exacto» = diferencia de hasta 2 %
        'stockout_days'            => 0,     // 0 quiebres por mes
    ];

    public function __construct(
        private WasteReportService $waste,
        private BreakEvenService $breakEven,
        private ReorderService $reorder,
    ) {}

    public function forMonth(int $year, int $month): array
    {
        $tz = config('app.timezone', 'America/La_Paz');
        $start = Carbon::create($year, $month, 1, 0, 0, 0, $tz);
        $range = [$start->toDateString() . ' 00:00:00', $start->copy()->endOfMonth()->toDateString() . ' 23:59:59'];
        $report = $this->waste->month($year, $month);

        $previous = $start->copy()->subMonth();
        $prevRange = [$previous->toDateString() . ' 00:00:00', $previous->copy()->endOfMonth()->toDateString() . ' 23:59:59'];
        $prevReport = $this->waste->month($previous->year, $previous->month);
        $prevWaste = $this->wasteBlock($prevRange, $prevReport);
        $prevAccuracy = $this->accuracy($prevRange);
        $prevStockouts = $this->stockouts($previous->year, $previous->month, $prevRange);

        return [
            'period'    => ['year' => $year, 'month' => $month, 'days' => $start->daysInMonth],
            'targets'   => self::TARGETS,
            'value'     => $this->inventoryValue(),
            'coverage'  => $this->coverage(),
            'stockouts' => $this->stockouts($year, $month, $range),
            'waste'     => $this->wasteBlock($range, $report),
            'hidden'    => [
                'net_loss'   => $report['hidden']['net_loss'],
                'lines'      => $report['hidden']['lines'],
                'counts'     => $report['counts']['count'],
                'unpriced'   => $report['hidden']['unpriced'],
                'previous'   => $prevReport['hidden']['lines'] > 0 ? $prevReport['hidden']['net_loss'] : null,
            ],
            'cost_gap'  => $this->costGap($year, $month),
            'accuracy'  => $this->accuracy($range),
            'previous'  => [
                'label'          => sprintf('%02d/%d', $previous->month, $previous->year),
                'waste_percent'  => $prevWaste['percent'],
                'accuracy_percent' => $prevAccuracy['percent'],
                'stockout_days'  => $prevStockouts['days'],
            ],
        ];
    }

    /** Estado frente a una meta de «cuanto menos, mejor»: ok hasta la meta, aviso hasta el doble, error por encima. */
    public static function lowerIsBetter(?float $value, float $target): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value <= $target ? 'ok' : ($value <= $target * 2 ? 'warn' : 'err');
    }

    // ── Valor del inventario ──────────────────────────────────────────────────────────────────────────────────────

    private function inventoryValue(): array
    {
        $ingredients = Ingredient::where('tracks_stock', true)->get();
        $rows = [];
        $unpriced = 0;
        $negative = 0;
        foreach ($ingredients as $ingredient) {
            $stock = (float) $ingredient->current_stock;
            if ($stock < -InventoryService::EPSILON) {
                $negative++;
                continue;
            }
            if ($stock <= InventoryService::EPSILON) {
                continue;
            }
            $cost = $ingredient->costPerUnit();
            if ($cost <= 0) {
                $unpriced++;
                continue;
            }
            $rows[] = ['id' => $ingredient->id, 'name' => $ingredient->name, 'unit' => $ingredient->unit, 'stock' => round($stock, 4), 'unit_cost' => round($cost, 4), 'value' => round($stock * $cost, 2)];
        }
        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);
        $total = round(array_sum(array_column($rows, 'value')), 2);

        return [
            'total'    => $total,
            'items'    => count($rows),
            'unpriced' => $unpriced,
            'negative' => $negative,
            'top'      => array_map(fn ($r) => $r + ['share' => $total > 0 ? round($r['value'] / $total * 100, 1) : 0.0], array_slice($rows, 0, 8)),
        ];
    }

    // ── Días de cobertura ─────────────────────────────────────────────────────────────────────────────────────────

    /** Cobertura de los insumos clave (o de todos los que se consumen, si no hay clave marcados). */
    private function coverage(): array
    {
        $items = collect($this->reorder->report()['items'])->filter(fn ($i) => $i['policy'] !== 'none');
        $key = $items->where('is_key', true);
        $keyOnly = $key->isNotEmpty();
        $list = ($keyOnly ? $key : $items)->filter(fn ($i) => $i['days_of_stock'] !== null)
            ->sortBy('days_of_stock')->values();

        $worst = $list->contains(fn ($i) => in_array($i['status'], ['out', 'critical'], true)) ? 'err'
            : ($list->contains(fn ($i) => $i['status'] === 'reorder') ? 'warn' : ($list->isNotEmpty() ? 'ok' : null));

        return [
            'key_only' => $keyOnly,
            'status'   => $worst,
            'min_days' => $list->first()['days_of_stock'] ?? null,
            'low'      => $list->filter(fn ($i) => in_array($i['status'], ['out', 'critical', 'reorder'], true))->count(),
            'items'    => $list->take(12)->map(fn ($i) => [
                'id' => $i['id'], 'name' => $i['name'], 'unit' => $i['unit'], 'stock' => $i['stock'], 'daily_use' => $i['daily_use'],
                'days' => $i['days_of_stock'], 'lead_days' => $i['lead_days'], 'status' => $i['status'], 'basis' => $i['basis'],
            ])->all(),
            'without_data' => $items->filter(fn ($i) => $i['status'] === 'nodata' && (!$keyOnly || $i['is_key']))->count(),
        ];
    }

    // ── Quiebres ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Días de venta en que un insumo clave llegó a cero o menos. Un insumo cuenta mientras tuvo ventas en el mes.
     * El saldo se reconstruye hacia atrás desde el stock actual: saldo tras un movimiento = stock actual − lo que
     * se movió después. Un día sin movimientos sigue en quiebre si el día anterior terminó en cero o menos.
     */
    private function stockouts(int $year, int $month, array $range): array
    {
        $tracked = Ingredient::where('tracks_stock', true)->get();
        $keyOnly = $tracked->contains(fn ($i) => $i->is_key);
        $candidates = ($keyOnly ? $tracked->where('is_key', true) : $tracked)->keyBy('id');

        $monthStart = substr($range[0], 0, 10);
        $monthEnd = substr($range[1], 0, 10);
        $today = Carbon::now(config('app.timezone', 'America/La_Paz'))->toDateString();
        $lastDay = min($monthEnd, $today);

        $movements = DB::table('inventory_movements')
            ->where('created_at', '>=', $range[0])
            ->whereIn('ingredient_id', $candidates->keys()->all())
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'ingredient_id', 'quantity_changed', 'type', 'created_at'])
            ->groupBy('ingredient_id');

        $operating = DB::table('inventory_movements')
            ->where('type', 'sale')->whereBetween('created_at', $range)
            ->selectRaw('substr(created_at, 1, 10) as day')->distinct()->pluck('day')->flip()->all();

        $events = [];
        $stockoutDays = [];
        foreach ($movements as $id => $list) {
            $inMonth = $list->filter(fn ($m) => substr((string) $m->created_at, 0, 10) <= $monthEnd);
            // Sin ventas en el mes no se considera un insumo en uso
            if (!$inMonth->contains(fn ($m) => $m->type === 'sale')) {
                continue;
            }

            // Saldo inicial del mes: stock actual − todo lo movido desde el inicio del mes
            $balance = (float) $candidates[$id]->current_stock - (float) $list->sum('quantity_changed');
            $byDay = $inMonth->groupBy(fn ($m) => substr((string) $m->created_at, 0, 10));
            // Un insumo cargado dentro del mes (movimiento «initial») no existía antes: no hay quiebre en esos días
            $loaded = $inMonth->firstWhere('type', 'initial');
            $loadedDay = $loaded ? substr((string) $loaded->created_at, 0, 10) : null;
            $days = [];
            for ($d = Carbon::parse($monthStart); $d->toDateString() <= $lastDay; $d->addDay()) {
                $day = $d->toDateString();
                $existed = $loadedDay === null || $day >= $loadedDay;
                // Empezó el día sin stock (salvo el día de la carga inicial, que parte de cero por definición)
                $out = $existed && $day !== $loadedDay && $balance <= InventoryService::EPSILON;
                foreach ($byDay->get($day, collect()) as $m) {
                    $balance += (float) $m->quantity_changed;
                    if ($balance <= InventoryService::EPSILON) {
                        $out = true;
                    }
                }
                if ($existed && $out && isset($operating[$day])) {
                    $days[] = $day;
                    $stockoutDays[$day] = true;
                }
            }
            if ($days) {
                $events[] = [
                    'ingredient_id' => (int) $id,
                    'name'          => $candidates[$id]->name,
                    'unit'          => $candidates[$id]->unit,
                    'days'          => count($days),
                    'dates'         => array_slice($days, 0, 6),
                ];
            }
        }
        usort($events, fn ($a, $b) => $b['days'] <=> $a['days'] ?: strcmp($a['name'], $b['name']));

        return [
            'days'               => count($stockoutDays),
            'events'             => $events,
            'ingredients_checked' => $candidates->count(),
            'key_only'           => $keyOnly,
            'operating_days'     => count(array_filter(array_keys($operating), fn ($d) => $d <= $lastDay)),
            // Meta: ninguno. Hasta 2 días es un aviso; más es un problema
            'status'             => count($stockoutDays) === self::TARGETS['stockout_days'] ? 'ok' : (count($stockoutDays) <= 2 ? 'warn' : 'err'),
        ];
    }

    // ── % de merma ────────────────────────────────────────────────────────────────────────────────────────────────

    /** Consumo valorado de las ventas del mes por insumo (ventas menos reversos). */
    private function consumption(array $range): array
    {
        return DB::table('inventory_movements')
            ->whereBetween('created_at', $range)
            ->where(function ($q) {
                $q->where('type', 'sale')->orWhere(function ($q2) {
                    $q2->where('type', 'adjustment')->whereIn('reference_type', ['sale_item', 'sale_item_option']);
                });
            })
            ->groupBy('ingredient_id')
            ->selectRaw('ingredient_id, SUM(-quantity_changed * COALESCE(unit_cost, 0)) as cost')
            ->pluck('cost', 'ingredient_id')
            ->map(fn ($c) => round((float) $c, 2))
            ->all();
    }

    private function wasteBlock(array $range, array $report): array
    {
        $consumption = $this->consumption($range);
        $consumed = round(array_sum(array_filter($consumption, fn ($c) => $c > 0)), 2);
        $registered = (float) $report['registered']['total_cost'];
        $types = Ingredient::withTrashed()->whereIn('id', array_column($report['registered']['by_ingredient'], 'ingredient_id'))->pluck('type', 'id');

        $rows = [];
        foreach ($report['registered']['by_ingredient'] as $row) {
            $base = (float) ($consumption[$row['ingredient_id']] ?? 0);
            $target = ($types[$row['ingredient_id']] ?? null) === 'perecedero' ? self::TARGETS['waste_percent_perishable'] : self::TARGETS['waste_percent'];
            $percent = $base > 0 ? round($row['cost'] / $base * 100, 1) : null;
            $rows[] = $row + [
                'consumption_cost' => $base,
                'percent'          => $percent,
                'target'           => $target,
                'status'           => $percent !== null ? self::lowerIsBetter($percent, $target) : ($row['cost'] > 0 ? 'err' : null),
            ];
        }
        usort($rows, fn ($a, $b) => $b['cost'] <=> $a['cost']);

        $percent = $consumed > 0 ? round($registered / $consumed * 100, 1) : null;

        return [
            'registered_cost'  => round($registered, 2),
            'consumption_cost' => $consumed,
            'percent'          => $percent,
            'status'           => $percent !== null ? self::lowerIsBetter($percent, self::TARGETS['waste_percent']) : null,
            'unpriced'         => $report['registered']['unpriced'],
            'by_ingredient'    => array_slice($rows, 0, 10),
        ];
    }

    // ── Diferencia de costo ───────────────────────────────────────────────────────────────────────────────────────

    /** Costo real (compras) frente al de las recetas, en puntos de las ventas. Solo si el mes tiene recetas. */
    private function costGap(int $year, int $month): ?array
    {
        $be = $this->breakEven->forMonth($year, $month);
        $gap = $be['cost_gap'];
        $sales = (float) $be['revenue']['total'];
        if ($gap === null || $sales <= 0) {
            return null;
        }

        $points = round($gap['difference'] / $sales * 100, 1);

        return [
            'sales'          => round($sales, 2),
            'real'           => $gap['real'],
            'recipes'        => $gap['recipes'],
            'difference'     => $gap['difference'],
            'real_percent'   => round($gap['real'] / $sales * 100, 1),
            'recipes_percent' => round($gap['recipes'] / $sales * 100, 1),
            'points'         => $points,
            'status'         => self::lowerIsBetter(abs($points), self::TARGETS['cost_gap_points']),
        ];
    }

    // ── Exactitud del conteo ──────────────────────────────────────────────────────────────────────────────────────

    private function accuracy(array $range): array
    {
        $lines = InventoryCountLine::with('ingredient:id,name,unit')
            ->whereHas('inventoryCount', fn ($q) => $q->whereBetween('counted_at', $range))
            ->get();
        $real = $lines->where('is_baseline', false);
        $tolerance = self::TARGETS['accuracy_tolerance'];

        $gap = function ($l) {
            $expected = (float) $l->expected;

            return $expected > InventoryService::EPSILON
                ? abs((float) $l->difference) / $expected * 100
                : (abs((float) $l->counted) <= InventoryService::EPSILON ? 0.0 : 100.0);
        };

        $exact = $real->filter(fn ($l) => $gap($l) <= $tolerance + 1e-9);
        $percent = $real->count() ? round($exact->count() / $real->count() * 100, 1) : null;
        $target = self::TARGETS['accuracy_percent'];

        return [
            'compared'       => $real->count(),
            'exact'          => $exact->count(),
            'percent'        => $percent,
            'baseline_lines' => $lines->where('is_baseline', true)->count(),
            'status'         => $percent === null ? null : ($percent >= $target ? 'ok' : ($percent >= $target - 15 ? 'warn' : 'err')),
            'worst'          => $real->map(fn ($l) => [
                'ingredient_id' => $l->ingredient_id,
                'name'          => $l->ingredient?->name ?? 'Insumo eliminado',
                'unit'          => $l->ingredient?->unit,
                'expected'      => (float) $l->expected,
                'counted'       => (float) $l->counted,
                'gap_percent'   => round($gap($l), 1),
            ])->filter(fn ($r) => $r['gap_percent'] > $tolerance)->sortByDesc('gap_percent')->take(5)->values()->all(),
        ];
    }
}
