<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use Carbon\Carbon;

/**
 * Margen de contribución y punto de equilibrio del mes (docs/PLAN_COSTOS_PUNTO_EQUILIBRIO.md §3).
 *
 *  Costos variables = costo por receta de lo vendido (% de costo por canal × ventas del canal)
 *                     + costos de PedidosYa (comisión, publicidad, IVA, cargos, envíos − reintegros)
 *                     + gastos del mes en categorías variables (sin las compras de insumos, que ya están en las recetas)
 *  Costos fijos      = gastos fijos activos mensualizados + gastos del mes en categorías fijas que no salen de la lista
 *  Punto de equilibrio = costos fijos ÷ margen de contribución %
 *
 * Sin recetas, el costo de los productos sale de las compras del mes (Método A). Un mes sin ventas usa el margen
 * del mes anterior.
 */
class BreakEvenService
{
    public function __construct(
        private FinanceReportService $pnlService,
        private RecipeCostService $recipeCosts,
        private WasteReportService $waste,
    ) {}

    public function forMonth(int $year, int $month): array
    {
        $tz = config('app.timezone', 'America/La_Paz');
        $start = Carbon::create($year, $month, 1, 0, 0, 0, $tz);
        $days = $start->daysInMonth;
        $today = Carbon::now($tz);
        $elapsed = $today->lt($start) ? 0 : ($today->format('Y-m') === $start->format('Y-m') ? $today->day : $days);

        $pnl = $this->pnlService->getMonthlyPnL($year, $month);
        $categories = ExpenseCategory::all()->keyBy('id');

        $margin = $this->contribution($pnl, $year, $month, $categories);
        $basedOn = 'month';
        if ($margin['percent'] === null) {
            $prev = $start->copy()->subMonth();
            $prevMargin = $this->contribution($this->pnlService->getMonthlyPnL($prev->year, $prev->month), $prev->year, $prev->month, $categories);
            if ($prevMargin['percent'] !== null) {
                $margin = array_merge($prevMargin, ['revenue' => $margin['revenue']]);
                $basedOn = 'previous_month';
            } else {
                $basedOn = null;
            }
        }

        $fixed = $this->fixedCosts($pnl, $year, $month, $categories);

        $breakEven = null;
        $progress = null;
        if ($margin['percent'] !== null && $margin['percent'] > 0 && $fixed['total'] > 0) {
            $amount = round($fixed['total'] / ($margin['percent'] / 100), 2);
            $daily = round($amount / $days, 2);
            $unitPrice = $margin['unit_price'];
            $breakEven = [
                'amount'        => $amount,
                'daily'         => $daily,
                'unit_price'    => $unitPrice,
                'units_month'   => $unitPrice ? (int) ceil($amount / $unitPrice) : null,
                'units_per_day' => $unitPrice ? round($daily / $unitPrice, 1) : null,
            ];

            $sales = (float) $pnl['revenue']['total'];
            // Mes ya terminado: la «proyección» es el resultado real del mes
            $closed = $today->format('Y-m') > $start->format('Y-m');
            $pace = $elapsed > 0 ? $sales / $elapsed : 0.0;
            $projection = round($pace * $days, 2);
            $expected = round($daily * $elapsed, 2);
            $progress = [
                'sales_to_date'    => $sales,
                'percent'          => round($sales / $amount * 100, 1),
                'elapsed_days'     => $elapsed,
                'days'             => $days,
                'pace'             => round($pace, 2),
                'expected_to_date' => $expected,
                'projection'       => $projection,
                'projected_profit' => round($projection * $margin['percent'] / 100 - $fixed['total'], 2),
                'reach_day'        => $sales >= $amount ? null : ($pace > 0 ? (int) ceil($amount / $pace) : null),
                'closed'           => $closed,
                'status'           => match (true) {
                    $sales >= $amount  => 'reached',
                    $elapsed === 0     => 'not_started',
                    $closed            => 'missed',
                    $sales >= $expected => 'on_track',
                    default            => 'behind',
                },
            ];
        }

        return [
            'period'       => ['year' => $year, 'month' => $month, 'days' => $days],
            'revenue'      => $margin['revenue'],
            'variable'     => $margin['variable'],
            'contribution' => [
                'amount'            => $margin['amount'],
                'percent'           => $margin['percent'],
                'local_percent'     => $margin['local_percent'],
                'pedidosya_percent' => $margin['pedidosya_percent'],
                'based_on'          => $basedOn,
            ],
            'real_cost_of_sales' => [
                'total'   => (float) $pnl['cost_of_sales']['total'],
                'percent' => $pnl['revenue']['total'] > 0 ? round($pnl['cost_of_sales']['total'] / $pnl['revenue']['total'] * 100, 1) : null,
            ],
            'cost_gap'   => $this->costGap($margin, $basedOn, (float) $pnl['cost_of_sales']['total'], $year, $month),
            'fixed'      => $fixed,
            'break_even' => $breakEven,
            'progress'   => $progress,
            'warnings'   => $this->warnings($margin, $basedOn, $fixed),
        ];
    }

    /**
     * Diferencia entre lo que costó comprar (costo real) y lo que dicen las recetas, repartida en merma anotada,
     * merma oculta (la que encontró el conteo físico) y el resto: compras que quedan en el estante, cambios de
     * precio y todo lo que no se pudo explicar. Solo si el margen del mes sale de recetas.
     */
    private function costGap(array $margin, ?string $basedOn, float $purchases, int $year, int $month): ?array
    {
        if ($basedOn !== 'month' || $margin['variable']['source'] !== 'recipes') {
            return null;
        }

        $recipes = (float) $margin['variable']['product_cost'];
        $difference = round($purchases - $recipes, 2);
        $waste = $this->waste->totals($year, $month);

        return [
            'real'              => round($purchases, 2),
            'recipes'           => round($recipes, 2),
            'difference'        => $difference,
            'registered_waste'  => $waste['registered'],
            'hidden_waste'      => $waste['hidden'],
            'counts'            => $waste['counts'],
            'hidden_lines'      => $waste['hidden_lines'],
            'other'             => round($difference - $waste['registered'] - $waste['hidden'], 2),
        ];
    }

    /** Ventas, costos variables y margen de contribución de un mes. */
    private function contribution(array $pnl, int $year, int $month, $categories): array
    {
        $revenue = (float) $pnl['revenue']['total'];
        $local = (float) $pnl['revenue']['local_sales'];
        $pya = round($revenue - $local, 2);

        $recipe = $this->recipeCosts->monthCost($year, $month);
        // % de costo sin redondear (cubierto = ventas con receta completa)
        $exact = fn (array $c) => $c['covered_revenue'] > 0 ? $c['covered_cost'] / $c['covered_revenue'] : null;
        $ratio = $exact($recipe['total']);
        $source = $ratio !== null ? 'recipes' : null;
        $ratioLocal = $exact($recipe['channels']['local']) ?? $ratio;
        $ratioPya = $exact($recipe['channels']['pedidosya']) ?? $ratio;

        // Sin recetas: el costo de los productos sale de las compras del mes (Método A)
        $purchases = (float) $pnl['cost_of_sales']['total'];
        if ($source === null && $purchases > 0 && $revenue > 0) {
            $ratio = $ratioLocal = $ratioPya = $purchases / $revenue;
            $source = 'purchases';
        }

        $items = collect($pnl['operating_expenses']['items'])
            ->merge($pnl['financial_expenses']['items'])
            ->merge($pnl['taxes']['items']);

        $platform = round((float) $items->where('source', 'pedidosya_settlement')->sum('amount'), 2);
        $otherVariable = round((float) $items
            ->filter(fn ($i) => $i['source'] !== 'pedidosya_settlement' && $i['category_id'] && $categories->get($i['category_id'])?->cost_behavior === 'variable')
            ->sum('amount'), 2);

        $productLocal = $ratioLocal !== null ? round($ratioLocal * $local, 2) : 0.0;
        $productPya = $ratioPya !== null ? round($ratioPya * $pya, 2) : 0.0;
        $variable = round($productLocal + $productPya + $platform + $otherVariable, 2);

        $percent = $source !== null && $revenue > 0 ? round(($revenue - $variable) / $revenue * 100, 1) : null;

        return [
            'revenue'  => ['total' => $revenue, 'local' => $local, 'pedidosya' => $pya],
            'variable' => [
                'product_cost'      => round($productLocal + $productPya, 2),
                'product_cost_local' => $productLocal,
                'product_cost_pya'  => $productPya,
                'platform'          => $platform,
                'other'             => $otherVariable,
                'total'             => $variable,
                'source'            => $source,
                'cost_ratio'        => $ratio !== null ? round($ratio * 100, 1) : null,
                'coverage_percent'  => $recipe['total']['coverage_percent'],
                'uncovered'         => collect($recipe['uncovered'])->map(fn ($u) => ['name' => $u['name'], 'revenue' => $u['revenue'], 'status' => $u['status'], 'deleted' => $u['deleted']])->values()->all(),
            ],
            'amount'            => round($revenue - $variable, 2),
            'percent'           => $percent,
            'local_percent'     => $source !== null && $local > 0 ? round(($local - $productLocal) / $local * 100, 1) : null,
            'pedidosya_percent' => $source !== null && $pya > 0 ? round(($pya - $productPya - $platform) / $pya * 100, 1) : null,
            'unit_price'        => $recipe['unit_price'],
        ];
    }

    /** Gastos fijos mensualizados + otros gastos del mes en categorías fijas (sin contar dos veces los generados). */
    private function fixedCosts(array $pnl, int $year, int $month, $categories): array
    {
        $items = [];

        foreach (RecurringExpense::with('category')->where('is_active', true)->get() as $t) {
            if (($t->category?->cost_behavior ?? 'fixed') !== 'fixed') {
                continue;
            }
            $start = $t->start_month ?: $t->created_at?->format('Y-m');
            if ($start && $start > sprintf('%04d-%02d', $year, $month)) {
                continue;
            }
            $items[] = [
                'name'   => $t->name,
                'amount' => round((float) $t->amount / $t->intervalMonths(), 2),
                'source' => 'recurring',
                'note'   => $t->intervalMonths() > 1 ? "{$t->amount} cada {$t->intervalMonths()} meses" : null,
            ];
        }

        $monthItems = collect($pnl['cost_of_sales']['items'])
            ->merge($pnl['operating_expenses']['items'])
            ->merge($pnl['financial_expenses']['items'])
            ->merge($pnl['taxes']['items'])
            ->filter(fn ($i) => in_array($i['source'], ['direct_expense', 'cash_movement'], true))
            ->filter(function ($i) use ($categories) {
                // Un egreso de caja sin clasificar cuenta como fijo hasta que se clasifique
                if (!$i['category_id']) {
                    return $i['source'] === 'cash_movement';
                }

                return $categories->get($i['category_id'])?->cost_behavior === 'fixed';
            });

        foreach ($monthItems->groupBy('category_name') as $name => $group) {
            $items[] = ['name' => $name, 'amount' => round((float) $group->sum('amount'), 2), 'source' => 'month', 'note' => null];
        }

        $recurring = round(collect($items)->where('source', 'recurring')->sum('amount'), 2);
        $other = round(collect($items)->where('source', 'month')->sum('amount'), 2);

        return [
            'total'             => round($recurring + $other, 2),
            'recurring_monthly' => $recurring,
            'other'             => $other,
            'items'             => collect($items)->sortByDesc('amount')->values()->all(),
        ];
    }

    private function warnings(array $margin, ?string $basedOn, array $fixed): array
    {
        $warnings = [];

        if ($fixed['total'] <= 0) {
            $warnings[] = ['code' => 'no_fixed_costs', 'message' => 'Carga tus gastos fijos (alquiler, sueldos, servicios, cuota del régimen) para calcular el punto de equilibrio.', 'action' => 'recurring'];
        }
        if ($basedOn === null) {
            $warnings[] = ['code' => 'no_costs', 'message' => 'Sin recetas ni compras no se puede calcular el margen: carga las recetas base en «Costos y margen».', 'action' => 'costs'];
        } elseif ($basedOn === 'previous_month') {
            $warnings[] = ['code' => 'previous_month', 'message' => 'Todavía no hay ventas con receta este mes: el margen es el del mes anterior.', 'action' => null];
        }
        if ($margin['percent'] !== null && $margin['percent'] <= 0) {
            $warnings[] = ['code' => 'negative_margin', 'message' => 'Los costos variables igualan o superan a las ventas: no hay punto de equilibrio posible con estos precios y costos.', 'action' => 'costs'];
        }
        if ($margin['variable']['source'] === 'purchases') {
            $warnings[] = ['code' => 'purchases_based', 'message' => 'El costo de los productos sale de las compras del mes (no hay recetas): carga las recetas para un margen más estable.', 'action' => 'costs'];
        }
        $coverage = $margin['variable']['coverage_percent'];
        if ($margin['variable']['source'] === 'recipes' && $coverage !== null && $coverage < 100) {
            // Si solo faltan productos eliminados no hay nada que cargar en «Costos y margen»
            $onlyDeleted = collect($margin['variable']['uncovered'])->every(fn ($u) => $u['deleted']);
            $names = collect($margin['variable']['uncovered'])
                ->take(4)
                ->map(fn ($u) => $u['deleted'] ? "{$u['name']}, producto eliminado" : $u['name'])
                ->implode('; ');
            $warnings[] = ['code' => 'coverage', 'message' => 'El ' . number_format(100 - $coverage, 1, ',', '.') . " % de las ventas no tiene receta completa ({$names}): se calcula con el margen del resto.", 'action' => $onlyDeleted ? null : 'costs'];
        }

        return $warnings;
    }
}
