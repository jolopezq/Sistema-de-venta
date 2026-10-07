<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\CashMovement;
use App\Models\FinanceExpense;
use App\Models\RecurringExpense;
use App\Models\ExpenseCategory;
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

        $startUtc = (clone $startLocal)->setTimezone('UTC');
        $endUtc = (clone $endLocal)->setTimezone('UTC');

        $startDateStr = $startLocal->toDateString();
        $endDateStr = $endLocal->toDateString();

        // 1. Ingresos por ventas del local (excluye pedidosya porque en P&L se toman de liquidaciones en fase 2)
        $localSalesQuery = Sale::where('status', 'completed')
            ->where(function ($q) {
                $q->whereNull('source')->orWhere('source', '!=', 'pedidosya');
            })
            ->whereBetween('created_at', [$startUtc, $endUtc]);

        // 1. Ingresos por ventas del local (excluye pedidosya porque en P&L se toman de liquidaciones en fase 2)
        $localSalesQuery = Sale::where('status', 'completed')
            ->where(function ($q) {
                $q->whereNull('source')->orWhere('source', '!=', 'pedidosya');
            })
            ->whereBetween('created_at', [$startUtc, $endUtc]);

        $localSalesTotal = (float) $localSalesQuery->sum('total_amount');
        $localSalesCount = (int) $localSalesQuery->count();
        $averageTicket = $localSalesCount > 0 ? round($localSalesTotal / $localSalesCount, 2) : 0.0;

        // 2. Liquidaciones semanales de PedidosYa que finalizan en el mes seleccionado (§7.1)
        $pedidosyaSettlements = \App\Models\PedidosYaSettlement::whereBetween('end_date', [$startDateStr, $endDateStr])->get();
        $pedidosyaNetSales = (float) $pedidosyaSettlements->sum('net_sales');
        $pedidosyaTotalPayout = (float) $pedidosyaSettlements->sum('total_payout');
        $pedidosyaGrossSales = (float) $pedidosyaSettlements->sum('csv_gross_sales');
        $pedidosyaDeliveredCount = (int) $pedidosyaSettlements->sum('delivered_count');
        $pedidosyaCancelledCount = (int) $pedidosyaSettlements->sum('cancelled_count');

        // Total Ingresos Operativos (Ventas del local + Ventas netas PedidosYa)
        $totalRevenue = round($localSalesTotal + $pedidosyaNetSales, 2);

        // 3. Traer categorías activas para desglose estructurado
        $allCategories = ExpenseCategory::orderBy('sort_order')->get()->keyBy('id');

        // 4. Compras y gastos registrados fuera de caja en el mes
        $directExpenses = FinanceExpense::with('category')
            ->whereBetween('date', [$startDateStr, $endDateStr])
            ->get();

        // 5. Egresos de caja registrados en los turnos del mes
        $cashExpenses = CashMovement::with('expenseCategory')
            ->where('type', 'expense')
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->get();

        // 6. Gastos fijos / recurrentes activos
        $recurringExpenses = RecurringExpense::with('category')
            ->active()
            ->get();

        // Estructura de acumulación por secciones
        $cogsItems = collect();
        $operatingItems = collect();
        $financialItems = collect();
        $taxItems = collect();

        // Agregar costos operativos de PedidosYa (comisiones, publicidad, IVA, reintegros)
        foreach ($pedidosyaSettlements as $st) {
            if ($st->service_fees > 0) {
                $operatingItems->push([
                    'id'            => 'pya_srv_' . $st->id,
                    'source'        => 'pedidosya_settlement',
                    'category_id'   => null,
                    'category_name' => 'Comisión PedidosYa',
                    'group_name'    => 'Plataforma Delivery',
                    'description'   => "Comisión liquidación semana {$st->start_date->format('d/m')} al {$st->end_date->format('d/m')}",
                    'amount'        => (float) $st->service_fees,
                    'date'          => $st->end_date->toDateString(),
                ]);
            }
            if ($st->advertising > 0) {
                $operatingItems->push([
                    'id'            => 'pya_adv_' . $st->id,
                    'source'        => 'pedidosya_settlement',
                    'category_id'   => null,
                    'category_name' => 'Publicidad PedidosYa',
                    'group_name'    => 'Marketing Delivery',
                    'description'   => "Publicidad in-app semana {$st->start_date->format('d/m')} al {$st->end_date->format('d/m')}",
                    'amount'        => (float) $st->advertising,
                    'date'          => $st->end_date->toDateString(),
                ]);
            }
            if ($st->taxes > 0) {
                $operatingItems->push([
                    'id'            => 'pya_tax_' . $st->id,
                    'source'        => 'pedidosya_settlement',
                    'category_id'   => null,
                    'category_name' => 'IVA en comisiones PedidosYa',
                    'group_name'    => 'Impuestos Delivery',
                    'description'   => "IVA s/comisiones semana {$st->start_date->format('d/m')} al {$st->end_date->format('d/m')}",
                    'amount'        => (float) $st->taxes,
                    'date'          => $st->end_date->toDateString(),
                ]);
            }
            if ($st->refunds > 0) {
                // Los reintegros reducen el costo de la plataforma
                $operatingItems->push([
                    'id'            => 'pya_ref_' . $st->id,
                    'source'        => 'pedidosya_settlement',
                    'category_id'   => null,
                    'category_name' => 'Reintegros PedidosYa',
                    'group_name'    => 'Plataforma Delivery',
                    'description'   => "Reintegros semana {$st->start_date->format('d/m')} al {$st->end_date->format('d/m')}",
                    'amount'        => -(float) $st->refunds,
                    'date'          => $st->end_date->toDateString(),
                ]);
            }
        }

        // Procesar FinanceExpenses (compras y pagos fuera de caja)
        foreach ($directExpenses as $expense) {
            $cat = $expense->category;
            $section = $cat ? $cat->section : 'operating';
            $item = [
                'id'            => 'fe_' . $expense->id,
                'source'        => 'direct_expense',
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

        // Procesar RecurringExpenses (gastos fijos del mes)
        foreach ($recurringExpenses as $rec) {
            $cat = $rec->category;
            $section = $cat ? $cat->section : 'operating';
            
            // Si la frecuencia es mensual, entra directamente.
            // Para bimestral/anual se calcula el valor equivalente o se prorratea
            $amount = (float) $rec->amount;
            if ($rec->frequency === 'bimonthly') {
                $amount = round($amount / 2, 2);
            } elseif ($rec->frequency === 'quarterly') {
                $amount = round($amount / 3, 2);
            } elseif ($rec->frequency === 'annual') {
                $amount = round($amount / 12, 2);
            }

            $item = [
                'id'            => 'rec_' . $rec->id,
                'source'        => 'recurring_expense',
                'category_id'   => $cat?->id,
                'category_name' => $cat?->name ?? 'Gasto fijo',
                'group_name'    => $cat?->group_name ?? 'Gastos fijos',
                'description'   => $rec->name . ' (' . ucfirst($rec->frequency) . ')',
                'amount'        => $amount,
                'date'          => $startDateStr,
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
            ],
        ];
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
