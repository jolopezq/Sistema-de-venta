<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\CashMovement;
use App\Models\FinanceExpense;
use App\Models\ExpenseCategory;
use App\Models\PedidosYaOrder;
use App\Models\PedidosYaSettlement;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FinanceReportService
{
    /**
     * Calcula el Estado de Resultados (P&L) para un año y mes especificados.
     * Zona horaria oficial: America/La_Paz.
     */
    public function getMonthlyPnL(int $year, int $month): array
    {
        $timezone = config('app.timezone', 'America/La_Paz');

        $startLocal = Carbon::create($year, $month, 1, 0, 0, 0, $timezone)->startOfMonth();
        $endLocal = (clone $startLocal)->endOfMonth();

        // Las fechas (`created_at`) se guardan en la hora de la app (La Paz): se filtra con los
        // límites locales del mes, sin convertir a UTC.
        $startDateStr = $startLocal->toDateString();
        $endDateStr = $endLocal->toDateString();
        $startBound = $startDateStr . ' 00:00:00';
        $endBound = $endDateStr . ' 23:59:59';

        // 1. Ventas del local (las de PedidosYa salen de las liquidaciones semanales)
        $localSalesQuery = Sale::where('status', 'completed')
            ->where(function ($q) {
                $q->whereNull('source')->orWhere('source', '!=', 'pedidosya');
            })
            ->whereBetween('created_at', [$startBound, $endBound]);

        $localSalesTotal = (float) $localSalesQuery->sum('total_amount');
        $localSalesCount = (int) $localSalesQuery->count();
        $averageTicket = $localSalesCount > 0 ? round($localSalesTotal / $localSalesCount, 2) : 0.0;

        // 2. Semanas de PedidosYa que tocan el mes. Las que cruzan dos meses se prorratean por la fecha
        //    de cada pedido entregado (plan §7.1, auditoría M2).
        $monthKey = $startLocal->format('Y-m');
        $pedidosyaSettlements = PedidosYaSettlement::with('orders:id,settlement_id,status,order_date,subtotal')
            ->where('start_date', '<=', $endBound)
            ->where('end_date', '>=', $startDateStr)
            ->orderBy('start_date')
            ->get();

        $pyaMonth = $pedidosyaSettlements->map(fn (PedidosYaSettlement $st) => $this->settlementShare($st, $monthKey));

        $pedidosyaNetSales = round($pyaMonth->sum('net_sales'), 2);
        $pedidosyaTotalPayout = round($pyaMonth->sum('total_payout'), 2);
        $pedidosyaGrossSales = round($pyaMonth->sum('gross_sales'), 2);
        $pedidosyaDeliveredCount = (int) $pyaMonth->sum('delivered_count');
        $pedidosyaCancelledCount = (int) $pyaMonth->sum('cancelled_count');

        // 2b. Ventas PedidosYa del POS en días sin semana importada: se usan mientras tanto (plan §7.1)
        //     y el resumen avisa que están sin conciliar. En días con semana importada manda la liquidación.
        $coveredDays = [];
        foreach ($pedidosyaSettlements as $st) {
            for ($d = $st->start_date->copy(); $d->lte($st->end_date); $d->addDay()) {
                $coveredDays[$d->toDateString()] = true;
            }
        }

        $posPedidosYaSales = Sale::where('status', 'completed')
            ->where('source', 'pedidosya')
            ->whereBetween('created_at', [$startBound, $endBound])
            // Una venta vinculada a un pedido de una semana importada ya está en sus ventas netas,
            // aunque se haya registrado un día fuera de esa semana
            ->whereNotIn('id', PedidosYaOrder::select('sale_id')->whereNotNull('sale_id')->whereHas('settlement'))
            ->get(['id', 'total_amount', 'created_at']);

        $unreconciledPyaSales = $posPedidosYaSales->filter(
            fn ($sale) => !isset($coveredDays[$sale->created_at->toDateString()])
        );
        $unreconciledPyaTotal = round((float) $unreconciledPyaSales->sum('total_amount'), 2);
        $unreconciledPyaCount = $unreconciledPyaSales->count();

        // Total de ingresos: ventas del local + ventas netas PedidosYa (+ PedidosYa del POS sin conciliar)
        $totalRevenue = round($localSalesTotal + $pedidosyaNetSales + $unreconciledPyaTotal, 2);

        // 3. Traer categorías activas para desglose estructurado
        $allCategories = ExpenseCategory::orderBy('sort_order')->get()->keyBy('id');

        // 4. Compras y gastos registrados fuera de caja en el mes
        $directExpenses = FinanceExpense::with('category')
            // Las columnas `date` se guardan como 'AAAA-MM-DD 00:00:00': el último día se incluye con 23:59:59
            ->whereBetween('date', [$startDateStr, $endBound])
            ->get();

        // 5. Egresos de caja registrados en los turnos del mes
        $cashExpenses = CashMovement::with('expenseCategory')
            ->where('type', 'expense')
            ->whereBetween('created_at', [$startBound, $endBound])
            ->get();

        // 6. Los gastos fijos ya no se suman aquí: se generan como gastos reales del mes
        //    (RecurringExpenseService) y entran con los demás gastos (auditoría A2).

        // Estructura de acumulación por secciones
        $cogsItems = collect();
        $operatingItems = collect();
        $financialItems = collect();
        $taxItems = collect();

        // Costos de PedidosYa (servicios, publicidad, IVA, cargos, envíos y reintegros), en la parte del mes
        foreach ($pedidosyaSettlements as $i => $st) {
            $share = $pyaMonth[$i];
            $week = $st->start_date->format('d/m') . ' al ' . $st->end_date->format('d/m');
            $partial = $share['fraction'] < 1 ? ' (' . round($share['fraction'] * 100) . ' % en el mes)' : '';

            $lines = [
                ['srv', 'service_fees', 'Comisión PedidosYa', 'Plataforma Delivery', 'Comisión semana', 1],
                ['adv', 'advertising', 'Publicidad PedidosYa', 'Marketing Delivery', 'Publicidad en la app semana', 1],
                ['tax', 'taxes', 'IVA en comisiones PedidosYa', 'Impuestos Delivery', 'IVA s/comisiones semana', 1],
                ['ops', 'operating_charges', 'Cargos operativos PedidosYa', 'Plataforma Delivery', 'Cargos operativos semana', 1],
                ['dlv', 'delivery_fees', 'Envíos PedidosYa', 'Plataforma Delivery', 'Envíos a cargo del local semana', 1],
                // Los reintegros reducen el costo de la plataforma
                ['ref', 'refunds', 'Reintegros PedidosYa', 'Plataforma Delivery', 'Reintegros semana', -1],
            ];

            foreach ($lines as [$code, $field, $name, $group, $label, $sign]) {
                if ($share[$field] == 0.0) {
                    continue;
                }

                $operatingItems->push([
                    'id'            => "pya_{$code}_{$st->id}",
                    'source'        => 'pedidosya_settlement',
                    'category_id'   => null,
                    'category_name' => $name,
                    'group_name'    => $group,
                    'description'   => "{$label} {$week}{$partial}",
                    'amount'        => $sign * $share[$field],
                    'date'          => min($st->end_date->toDateString(), $endDateStr),
                ]);
            }
        }

        // Procesar FinanceExpenses (compras y pagos fuera de caja)
        foreach ($directExpenses as $expense) {
            $cat = $expense->category;
            $section = $cat ? $cat->section : 'operating';
            $item = [
                'id'            => 'fe_' . $expense->id,
                'source'        => $expense->recurring_expense_id ? 'recurring_expense' : 'direct_expense',
                'category_id'   => $cat?->id,
                'category_name' => $cat?->name ?? 'Sin categoría',
                'group_name'    => $cat?->group_name ?? 'General',
                'description'   => $expense->supplier ? ($expense->supplier . ($expense->notes ? ' - ' . $expense->notes : '')) : ($expense->notes ?: 'Gasto registrado'),
                'amount'        => (float) $expense->amount,
                'date'          => $expense->date->toDateString(),
            ];

            $this->assignToSection($section, $item, $cogsItems, $operatingItems, $financialItems, $taxItems);
        }

        // Procesar CashMovements (egresos desde la caja física)
        foreach ($cashExpenses as $cm) {
            $cat = $cm->expenseCategory;
            $section = $cat ? $cat->section : 'operating';
            $item = [
                'id'            => 'cm_' . $cm->id,
                'source'        => 'cash_movement',
                'category_id'   => $cat?->id,
                'category_name' => $cat?->name ?? 'Egreso de caja (sin clasificar)',
                'group_name'    => $cat?->group_name ?? 'Caja chica / Turno',
                'description'   => $cm->description,
                'amount'        => (float) $cm->amount,
                'date'          => $cm->created_at->setTimezone($timezone)->toDateString(),
            ];

            $this->assignToSection($section, $item, $cogsItems, $operatingItems, $financialItems, $taxItems);
        }

        // Totales de costos y gastos
        $totalCogs = round($cogsItems->sum('amount'), 2);
        $grossProfit = round($totalRevenue - $totalCogs, 2);
        $grossMarginPercent = $totalRevenue > 0 ? round(($grossProfit / $totalRevenue) * 100, 2) : 0.0;

        $totalOperating = round($operatingItems->sum('amount'), 2);
        $operatingProfit = round($grossProfit - $totalOperating, 2);
        $operatingMarginPercent = $totalRevenue > 0 ? round(($operatingProfit / $totalRevenue) * 100, 2) : 0.0;

        $totalFinancial = round($financialItems->sum('amount'), 2);
        $totalTaxes = round($taxItems->sum('amount'), 2);

        $netProfit = round($operatingProfit - $totalFinancial - $totalTaxes, 2);
        $netMarginPercent = $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 2) : 0.0;

        $warnings = $this->warnings(
            $year,
            $month,
            $totalRevenue,
            $totalCogs,
            $cashExpenses,
            $pedidosyaSettlements,
            $coveredDays,
            $unreconciledPyaCount,
            $unreconciledPyaTotal,
            $startLocal,
            $endLocal
        );

        return [
            'period' => [
                'year'       => $year,
                'month'      => $month,
                'start_date' => $startDateStr,
                'end_date'   => $endDateStr,
                'month_name' => $startLocal->translatedFormat('F Y'),
            ],
            'revenue' => [
                'local_sales'       => $localSalesTotal,
                'local_sales_count' => $localSalesCount,
                'average_ticket'    => $averageTicket,
                'pedidosya_net'     => $pedidosyaNetSales,
                'pedidosya_pos_unreconciled'       => $unreconciledPyaTotal,
                'pedidosya_pos_unreconciled_count' => $unreconciledPyaCount,
                'total'             => $totalRevenue,
            ],
            'cost_of_sales' => [
                'total'        => $totalCogs,
                'percentage'   => $totalRevenue > 0 ? round(($totalCogs / $totalRevenue) * 100, 2) : 0.0,
                'by_category'  => $this->groupByCategory($cogsItems),
                'items'        => $cogsItems->values()->all(),
            ],
            'gross_profit' => [
                'amount'     => $grossProfit,
                'percentage' => $grossMarginPercent,
            ],
            'operating_expenses' => [
                'total'        => $totalOperating,
                'percentage'   => $totalRevenue > 0 ? round(($totalOperating / $totalRevenue) * 100, 2) : 0.0,
                'by_category'  => $this->groupByCategory($operatingItems),
                'items'        => $operatingItems->values()->all(),
            ],
            'operating_profit' => [
                'amount'     => $operatingProfit,
                'percentage' => $operatingMarginPercent,
            ],
            'financial_expenses' => [
                'total'        => $totalFinancial,
                'by_category'  => $this->groupByCategory($financialItems),
                'items'        => $financialItems->values()->all(),
            ],
            'taxes' => [
                'total'        => $totalTaxes,
                'by_category'  => $this->groupByCategory($taxItems),
                'items'        => $taxItems->values()->all(),
            ],
            'net_profit' => [
                'amount'     => $netProfit,
                'percentage' => $netMarginPercent,
            ],
            'pedidosya_analysis' => [
                'gross_sales'      => $pedidosyaGrossSales,
                'net_sales'        => $pedidosyaNetSales,
                'total_payout'     => $pedidosyaTotalPayout,
                'retention_amount' => round($pedidosyaGrossSales - $pedidosyaTotalPayout, 2),
                'retention_percent'=> $pedidosyaGrossSales > 0 ? round((($pedidosyaGrossSales - $pedidosyaTotalPayout) / $pedidosyaGrossSales) * 100, 1) : 0.0,
                'delivered_count'  => $pedidosyaDeliveredCount,
                'cancelled_count'  => $pedidosyaCancelledCount,
                'average_ticket'   => $pedidosyaDeliveredCount > 0 ? round($pedidosyaGrossSales / $pedidosyaDeliveredCount, 2) : 0.0,
                'settlements_count'=> $pedidosyaSettlements->count(),
                'prorated'         => $pyaMonth->contains(fn ($share) => $share['fraction'] < 1),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Parte de una semana PedidosYa que corresponde al mes.
     * Peso de cada mes = ventas brutas de sus pedidos entregados (fecha del pedido); sin pedidos, por días.
     * El último mes de la semana recibe el resto, para que la suma de los meses dé el total exacto.
     */
    public function settlementShare(PedidosYaSettlement $st, string $monthKey): array
    {
        $firstKey = $st->start_date->format('Y-m');
        $lastKey = $st->end_date->format('Y-m');
        $clamp = fn (string $key) => $key < $firstKey ? $firstKey : ($key > $lastKey ? $lastKey : $key);

        $weights = [];
        $grossByMonth = [];
        $deliveredByMonth = [];
        $cancelledByMonth = [];

        foreach ($st->orders as $order) {
            $key = $clamp($order->order_date->format('Y-m'));
            if (PedidosYaImportService::isDelivered($order->status)) {
                $weights[$key] = ($weights[$key] ?? 0) + (float) $order->subtotal;
                $grossByMonth[$key] = ($grossByMonth[$key] ?? 0) + (float) $order->subtotal;
                $deliveredByMonth[$key] = ($deliveredByMonth[$key] ?? 0) + 1;
            } elseif (mb_strtolower(trim((string) $order->status)) === 'cancelado') {
                $cancelledByMonth[$key] = ($cancelledByMonth[$key] ?? 0) + 1;
            }
        }

        $byOrders = array_sum($weights) > 0;
        if (!$byOrders) {
            $weights = [];
            for ($d = $st->start_date->copy(); $d->lte($st->end_date); $d->addDay()) {
                $weights[$d->format('Y-m')] = ($weights[$d->format('Y-m')] ?? 0) + 1;
            }
        }
        ksort($weights);

        $total = array_sum($weights);
        $fraction = $total > 0 ? ($weights[$monthKey] ?? 0) / $total : 0.0;

        $allocate = function (float $amount, int $precision = 2) use ($weights, $total, $monthKey) {
            if (!isset($weights[$monthKey]) || $total <= 0) {
                return 0.0;
            }
            $keys = array_keys($weights);
            $last = end($keys);
            if ($monthKey !== $last) {
                return round($amount * $weights[$monthKey] / $total, $precision);
            }
            $others = 0.0;
            foreach ($weights as $key => $weight) {
                if ($key !== $last) {
                    $others += round($amount * $weight / $total, $precision);
                }
            }
            return round($amount - $others, $precision);
        };

        $share = ['fraction' => $fraction];
        foreach (['net_sales', 'delivery_fees', 'service_fees', 'operating_charges', 'advertising', 'refunds', 'taxes', 'total_payout'] as $field) {
            $share[$field] = $allocate((float) $st->{$field});
        }

        if ($byOrders) {
            $share['gross_sales'] = round($grossByMonth[$monthKey] ?? 0, 2);
            $share['delivered_count'] = $deliveredByMonth[$monthKey] ?? 0;
            $share['cancelled_count'] = $cancelledByMonth[$monthKey] ?? 0;
        } else {
            $share['gross_sales'] = $allocate((float) $st->csv_gross_sales);
            $share['delivered_count'] = (int) $allocate((float) $st->delivered_count, 0);
            $share['cancelled_count'] = (int) $allocate((float) $st->cancelled_count, 0);
        }

        return $share;
    }

    /**
     * Avisos de datos faltantes o sin conciliar (auditoría M4): sin ellos el resumen parece completo
     * aunque falten compras, egresos por clasificar, gastos fijos o semanas de PedidosYa.
     */
    private function warnings(
        int $year,
        int $month,
        float $totalRevenue,
        float $totalCogs,
        Collection $cashExpenses,
        Collection $settlements,
        array $coveredDays,
        int $unreconciledPyaCount,
        float $unreconciledPyaTotal,
        Carbon $startLocal,
        Carbon $endLocal
    ): array {
        $warnings = [];
        $money = fn (float $v) => number_format($v, 2, ',', '.');

        if ($totalRevenue > 0 && $totalCogs == 0.0) {
            $warnings[] = [
                'code'    => 'no_purchases',
                'level'   => 'warning',
                'message' => 'No hay compras registradas este mes: la utilidad bruta aparece como el 100 % de las ventas.',
                'action'  => 'add_purchase',
            ];
        }

        $unclassified = $cashExpenses->whereNull('expense_category_id');
        if ($unclassified->isNotEmpty()) {
            $amount = round((float) $unclassified->sum('amount'), 2);
            $warnings[] = [
                'code'    => 'unclassified_cash_movements',
                'level'   => 'warning',
                'message' => "{$unclassified->count()} egreso(s) de caja sin clasificar (Bs {$money($amount)}): se cuentan como gastos operativos.",
                'count'   => $unclassified->count(),
                'amount'  => $amount,
                'action'  => 'classify_cash',
            ];
        }

        $pending = app(RecurringExpenseService::class)->forPeriod($year, $month)->where('status', 'pending');
        if ($pending->isNotEmpty()) {
            $amount = round((float) $pending->sum('amount'), 2);
            $warnings[] = [
                'code'    => 'pending_recurring',
                'level'   => 'warning',
                'message' => "{$pending->count()} gasto(s) fijo(s) del mes sin generar (Bs {$money($amount)}).",
                'count'   => $pending->count(),
                'amount'  => $amount,
                'action'  => 'generate_recurring',
            ];
        }

        // Semanas (lunes a domingo) del mes ya terminadas que no tienen liquidación importada
        $today = Carbon::now(config('app.timezone', 'America/La_Paz'))->toDateString();
        $missingWeeks = [];
        for ($monday = $startLocal->copy()->startOfWeek(Carbon::MONDAY); $monday->lte($endLocal); $monday->addWeek()) {
            $sunday = $monday->copy()->endOfWeek(Carbon::SUNDAY);
            if ($sunday->toDateString() >= $today) {
                break;
            }
            for ($d = $monday->copy(); $d->lte($sunday); $d->addDay()) {
                $inMonth = $d->betweenIncluded($startLocal->copy()->startOfDay(), $endLocal);
                if ($inMonth && !isset($coveredDays[$d->toDateString()])) {
                    $missingWeeks[] = $monday->format('d/m') . '–' . $sunday->format('d/m');
                    break;
                }
            }
        }

        $usesImport = $settlements->isNotEmpty() || PedidosYaSettlement::exists();
        if (count($missingWeeks) > 0 && ($usesImport || $unreconciledPyaCount > 0)) {
            $message = 'Semanas PedidosYa sin cargar: ' . implode(', ', $missingWeeks) . '.';
            if ($unreconciledPyaCount > 0) {
                $message .= " Mientras tanto se suman {$unreconciledPyaCount} venta(s) PedidosYa del POS (Bs {$money($unreconciledPyaTotal)}) sin descontar comisión ni publicidad.";
            }
            $warnings[] = [
                'code'    => 'pedidosya_missing_weeks',
                'level'   => 'warning',
                'message' => $message,
                'weeks'   => $missingWeeks,
                'action'  => 'import_pedidosya',
            ];
        } elseif ($unreconciledPyaCount > 0) {
            $warnings[] = [
                'code'    => 'pedidosya_unreconciled_sales',
                'level'   => 'info',
                'message' => "{$unreconciledPyaCount} venta(s) PedidosYa del POS (Bs {$money($unreconciledPyaTotal)}) aún sin semana importada.",
                'action'  => 'import_pedidosya',
            ];
        }

        foreach ($settlements as $st) {
            if ($st->reconciliation['status'] !== 'ok') {
                $warnings[] = [
                    'code'          => 'pedidosya_reconciliation',
                    'level'         => 'warning',
                    'message'       => 'La semana PedidosYa ' . $st->start_date->format('d/m') . '–' . $st->end_date->format('d/m') . ' tiene diferencias de conciliación.',
                    'settlement_id' => $st->id,
                    'action'        => 'view_pedidosya',
                ];
            }
        }

        return $warnings;
    }

    private function assignToSection(
        string $section,
        array $item,
        Collection $cogs,
        Collection $operating,
        Collection $financial,
        Collection $taxes
    ): void {
        match ($section) {
            'cost_of_sales' => $cogs->push($item),
            'operating'     => $operating->push($item),
            'financial'     => $financial->push($item),
            'taxes'         => $taxes->push($item),
            default         => $operating->push($item),
        };
    }

    private function groupByCategory(Collection $items): array
    {
        return $items->groupBy('category_name')
            ->map(function ($group, $name) {
                return [
                    'name'   => $name,
                    'group'  => $group->first()['group_name'] ?? 'General',
                    'amount' => round($group->sum('amount'), 2),
                    'count'  => $group->count(),
                ];
            })
            ->values()
            ->sortByDesc('amount')
            ->values()
            ->all();
    }
}
