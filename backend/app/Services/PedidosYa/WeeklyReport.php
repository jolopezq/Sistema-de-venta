<?php

namespace App\Services\PedidosYa;

use App\Models\PedidosYaSettlement;
use App\Services\FinanceReportService;
use App\Services\PedidosYaImportService;

/**
 * Informe de una semana de PedidosYa (plan de Finanzas §6.6): ventas, descuentos, gastos de la plataforma,
 * reintegros, resultado (cuánto se queda PedidosYa y ganancia estimada) y controles.
 */
class WeeklyReport
{
    public function __construct(
        private ItemsParser $parser,
        private FinanceReportService $finance,
    ) {}

    public function build(PedidosYaSettlement $st): array
    {
        $orders = $st->orders()->with('sale:id,order_number,status')->orderBy('order_date')->get();
        $delivered = $orders->filter(fn ($o) => PedidosYaImportService::isDelivered($o->status));
        $cancelled = $orders->reject(fn ($o) => PedidosYaImportService::isDelivered($o->status));

        $gross = round((float) $delivered->sum('subtotal'), 2);
        $pct = fn (float $amount) => $gross > 0 ? round($amount / $gross * 100, 1) : 0.0;

        // Artículos más vendidos (nombres de PedidosYa)
        $items = [];
        foreach ($delivered as $o) {
            foreach ($this->parser->parse($o->items_text) as $item) {
                $items[$item['name']] = ($items[$item['name']] ?? 0) + $item['quantity'];
            }
        }
        arsort($items);

        $byPayment = $delivered->groupBy(fn ($o) => $o->payment_method ?: 'Sin dato')
            ->map(fn ($group, $method) => ['method' => $method, 'count' => $group->count(), 'amount' => round((float) $group->sum('subtotal'), 2)])
            ->values()
            ->all();

        $costs = [
            'commission'        => (float) $st->service_fees,
            'advertising'       => (float) $st->advertising,
            'taxes'             => (float) $st->taxes,
            'operating_charges' => (float) $st->operating_charges,
            'delivery_fees'     => (float) $st->delivery_fees,
        ];
        $costsTotal = round(array_sum($costs), 2);

        // Costo de los productos estimado con el % de costo de ventas del mes en que termina la semana
        $pnl = $this->finance->getMonthlyPnL((int) $st->end_date->format('Y'), (int) $st->end_date->format('n'));
        $foodCostPercent = $pnl['revenue']['total'] > 0 && $pnl['cost_of_sales']['total'] > 0
            ? round($pnl['cost_of_sales']['total'] / $pnl['revenue']['total'] * 100, 1)
            : null;
        $productCost = $foodCostPercent !== null ? round($gross * $foodCostPercent / 100, 2) : null;
        $payout = (float) $st->total_payout;
        $retained = round($gross - $payout, 2);

        $priceDifferences = $delivered
            ->filter(fn ($o) => $o->catalog_total !== null && abs((float) $o->catalog_total - (float) $o->subtotal) > 0.01)
            ->map(fn ($o) => [
                'order_number'  => $o->order_number,
                'items_text'    => $o->items_text,
                'total'         => (float) $o->subtotal,
                'catalog_total' => (float) $o->catalog_total,
                'difference'    => round((float) $o->subtotal - (float) $o->catalog_total, 2),
            ])
            ->values()
            ->all();

        return [
            'settlement' => $st,
            'sales' => [
                'delivered_count' => $delivered->count(),
                'cancelled_count' => $cancelled->count(),
                'gross_sales'     => $gross,
                'average_ticket'  => $delivered->count() ? round($gross / $delivered->count(), 2) : 0.0,
                'by_payment'      => $byPayment,
                'top_items'       => collect($items)->take(5)->map(fn ($q, $name) => ['name' => $name, 'quantity' => $q])->values()->all(),
            ],
            'discounts' => [
                'vendor'    => (float) $st->csv_vendor_discount,
                'pedidosya' => (float) $st->csv_pedidosya_discount,
            ],
            'costs' => array_merge($costs, [
                'total'              => $costsTotal,
                'commission_percent' => $pct($costs['commission']),
                'advertising_percent'=> $pct($costs['advertising']),
                'taxes_percent'      => $pct($costs['taxes']),
                'total_percent'      => $pct($costsTotal),
            ]),
            'refunds' => [
                'total'            => (float) $st->refunds,
                'cancelled_count'  => $cancelled->count(),
                'cancelled_amount' => round((float) $cancelled->sum('subtotal'), 2),
            ],
            'result' => [
                'net_sales'          => (float) $st->net_sales,
                'total_payout'       => $payout,
                'payout_date'        => $st->payout_date?->toDateString(),
                'retained_amount'    => $retained,
                'retained_percent'   => $pct($retained),
                'food_cost_percent'  => $foodCostPercent,
                'product_cost'       => $productCost,
                'estimated_profit'   => $productCost !== null ? round($payout - $productCost, 2) : null,
            ],
            'control' => [
                'reconciliation'    => $st->reconciliation,
                'price_differences' => $priceDifferences,
                'sales_created'     => $orders->where('sale_link', 'created')->count(),
                'sales_linked'      => $orders->where('sale_link', 'linked')->count(),
                'without_sale'      => $delivered->whereNull('sale_id')->count(),
            ],
            'orders' => $orders,
        ];
    }
}
