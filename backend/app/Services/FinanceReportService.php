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

        $localSalesTotal = (float) $localSalesQuery->sum('total_amount');
        $localSalesCount = (int) $localSalesQuery->count();
        $averageTicket = $localSalesCount > 0 ? round($localSalesTotal / $localSalesCount, 2) : 0.0;

        // Total Ingresos Operativos (fase 1: solo local; fase 2 sumará liquidaciones netas de PedidosYa)
        $totalRevenue = $localSalesTotal;

        // 2. Traer categorías activas para desglose estructurado
        $allCategories = ExpenseCategory::orderBy('sort_order')->get()->keyBy('id');

        // 3. Compras y gastos registrados fuera de caja en el mes
        $directExpenses = FinanceExpense::with('category')
            ->whereBetween('date', [$startDateStr, $endDateStr])
            ->get();

        // 4. Egresos de caja registrados en los turnos del mes
        $cashExpenses = CashMovement::with('expenseCategory')
            ->where('type', 'expense')
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->get();

        // 5. Gastos fijos / recurrentes activos
        $recurringExpenses = RecurringExpense::with('category')
            ->active()
            ->get();

        // Estructura de acumulación por secciones
        $cogsItems = collect();
        $operatingItems = collect();
        $financialItems = collect();
        $taxItems = collect();

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
                'pedidosya_net'     => 0.0, // Fase 2
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
