<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\SalePayment;
use Illuminate\Support\Collection;

/**
 * Efectivo de la gaveta en un período (docs/PLAN_CONCILIACION_CAJA.md, A y B).
 *
 * Responde «¿cuánto efectivo me tenía que llegar a la mano?», que no es lo cobrado: con la plata de la gaveta se
 * pagan compras, una parte queda de cambio y un cierre puede tener faltante. Por cada caja cerrada
 * (cierre: retirado + queda = contado):
 *
 *   retirado + queda = fondo + ventas en efectivo − egresos en efectivo + ingresos en efectivo + diferencia
 *
 * Sumando las cajas cerradas en orden:
 *
 *   retirado = ventas − egresos + ingresos + diferencias − (queda de la última − fondo de la primera) + saltos
 *
 * «Saltos» son los fondos que no coinciden con lo que dejó la caja cerrada anterior. La diferencia de cada caja
 * se calcula con sus movimientos de ahora (contado − esperado actual), así la cuenta cierra aunque se haya
 * corregido algo después del cierre. Las cajas abiertas no entran: su efectivo sigue en la gaveta.
 */
class CashDrawerService
{
    /**
     * @param string $startBound 'AAAA-MM-DD 00:00:00' (hora de La Paz)
     * @param string $endBound   'AAAA-MM-DD 23:59:59'
     * @param float  $collected  Efectivo cobrado en ventas del local del período (por fecha de venta)
     */
    public function forRange(string $startBound, string $endBound, float $collected): array
    {
        $sessions = CashRegisterSession::with('cashier:id,name')
            ->whereBetween('opened_at', [$startBound, $endBound])
            ->orderBy('opened_at')
            ->orderBy('id')
            ->get();

        $rows = $this->sessionRows($sessions);
        $closed = $rows->where('closed', true)->values();
        $open = $rows->where('closed', false)->values();

        // Fondo con el que terminó la última caja cerrada antes del período: la primera caja del período se compara
        // con él para mostrar el salto, pero la cuenta empieza en su propio fondo
        $before = CashRegisterSession::where('status', 'closed')
            ->whereNotNull('cash_left_for_next')
            ->where('opened_at', '<', $startBound)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->first();

        $jumps = [];
        $previousLeft = $before ? (float) $before->cash_left_for_next : null;
        foreach ($closed as $i => $row) {
            if ($previousLeft !== null && abs($row['opening'] - $previousLeft) > 0.005) {
                $jumps[] = [
                    'session_id'    => $row['id'],
                    'date'          => $row['date'],
                    'cashier'       => $row['cashier'],
                    'opening'       => $row['opening'],
                    'previous_left' => $previousLeft,
                    'amount'        => round($row['opening'] - $previousLeft, 2),
                    'in_total'      => $i > 0,
                ];
            }
            $previousLeft = $row['left'];
        }

        $sum = fn (Collection $c, string $key) => round((float) $c->sum($key), 2);
        $closedSales = $sum($closed, 'cash_sales');
        $openSales = $sum($open, 'cash_sales');
        $expenses = $sum($closed, 'cash_expenses');
        $income = $sum($closed, 'cash_income');
        $differences = $sum($closed, 'difference');
        $fundStart = $closed->isNotEmpty() ? (float) $closed->first()['opening'] : 0.0;
        $fundEnd = $closed->isNotEmpty() ? (float) $closed->last()['left'] : 0.0;
        $jumpTotal = round(collect($jumps)->where('in_total', true)->sum('amount'), 2);
        $withdrawn = $sum($closed, 'withdrawn');

        // Lo que la cuenta dice que se debió retirar; si no coincide con lo retirado, se avisa (datos viejos sin la
        // regla retirado + queda = contado)
        $computed = round($closedSales - $expenses + $income + $differences - ($fundEnd - $fundStart) + $jumpTotal, 2);

        return [
            'collected'           => round($collected, 2),
            'in_open_sessions'    => $openSales,
            'outside_sessions'    => round($collected - $closedSales - $openSales, 2),
            'closed_sales'        => $closedSales,
            'drawer_expenses'     => $expenses,
            'extra_income'        => $income,
            'differences'         => $differences,
            'fund_start'          => round($fundStart, 2),
            'fund_end'            => round($fundEnd, 2),
            'fund_change'         => round($fundEnd - $fundStart, 2),
            'fund_jumps'          => $jumpTotal,
            'fund_jump_items'     => array_values($jumps),
            'withdrawn'           => $withdrawn,
            'unexplained'         => round($withdrawn - $computed, 2),
            'closed_count'        => $closed->count(),
            'open_sessions'       => $open->map(fn ($r) => [
                'session_id' => $r['id'],
                'date'       => $r['date'],
                'cashier'    => $r['cashier'],
                'cash_sales' => $r['cash_sales'],
            ])->all(),
            'difference_items'    => $closed->filter(fn ($r) => abs($r['difference']) > 0.005)->map(fn ($r) => [
                'session_id' => $r['id'],
                'date'       => $r['date'],
                'cashier'    => $r['cashier'],
                'amount'     => $r['difference'],
            ])->values()->all(),
        ];
    }

    /**
     * Diferencia de cada caja cerrada del período (contado − esperado con sus movimientos de ahora).
     * Negativa = faltante.
     *
     * @return Collection<int, array{id: int, date: string, cashier: ?string, difference: float}>
     */
    public function differences(string $startBound, string $endBound): Collection
    {
        $sessions = CashRegisterSession::with('cashier:id,name')
            ->where('status', 'closed')
            ->whereBetween('opened_at', [$startBound, $endBound])
            ->orderBy('opened_at')
            ->get();

        return $this->sessionRows($sessions)->filter(fn ($r) => abs($r['difference']) > 0.005)->values();
    }

    /**
     * Cifras de efectivo de cada caja, con las mismas reglas que el arqueo (CashRegisterSessionController):
     * ventas completadas del local (sin PedidosYa); egresos en efectivo, o solo la parte en efectivo de un gasto
     * dividido; ingresos extra en efectivo.
     */
    private function sessionRows(Collection $sessions): Collection
    {
        if ($sessions->isEmpty()) {
            return collect();
        }
        $ids = $sessions->pluck('id');

        $sales = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->whereIn('sales.cash_register_session_id', $ids)
            ->where('sales.status', 'completed')
            ->where(fn ($q) => $q->whereNull('sales.source')->orWhere('sales.source', '!=', 'pedidosya'))
            ->where('sale_payments.method', 'cash')
            ->whereNull('sales.deleted_at')
            ->groupBy('sales.cash_register_session_id')
            ->selectRaw('sales.cash_register_session_id AS session_id, SUM(sale_payments.amount) AS total')
            ->pluck('total', 'session_id');

        $expenses = CashMovement::whereIn('cash_register_session_id', $ids)
            ->where('type', 'expense')
            ->groupBy('cash_register_session_id')
            ->selectRaw("cash_register_session_id, COALESCE(SUM(CASE WHEN payment_method IS NULL OR payment_method = 'cash' THEN amount WHEN payment_method = 'mixed' THEN cash_amount ELSE 0 END), 0) AS total")
            ->pluck('total', 'cash_register_session_id');

        $income = CashMovement::whereIn('cash_register_session_id', $ids)
            ->where('type', 'extra_income')
            ->where(fn ($q) => $q->whereNull('payment_method')->orWhere('payment_method', 'cash'))
            ->groupBy('cash_register_session_id')
            ->selectRaw('cash_register_session_id, SUM(amount) AS total')
            ->pluck('total', 'cash_register_session_id');

        return $sessions->map(function (CashRegisterSession $s) use ($sales, $expenses, $income) {
            $opening = (float) $s->opening_amount;
            $cashSales = round((float) ($sales[$s->id] ?? 0), 2);
            $cashExpenses = round((float) ($expenses[$s->id] ?? 0), 2);
            $cashIncome = round((float) ($income[$s->id] ?? 0), 2);
            $expected = round($opening + $cashSales - $cashExpenses + $cashIncome, 2);
            $closed = $s->status === 'closed' && $s->actual_closing !== null;

            return [
                'id'            => $s->id,
                'date'          => $s->opened_at->toDateString(),
                'cashier'       => $s->cashier?->name,
                'closed'        => $closed,
                'opening'       => $opening,
                'cash_sales'    => $cashSales,
                'cash_expenses' => $cashExpenses,
                'cash_income'   => $cashIncome,
                'expected'      => $expected,
                'difference'    => $closed ? round((float) $s->actual_closing - $expected, 2) : 0.0,
                'withdrawn'     => $closed ? (float) $s->cash_withdrawn : 0.0,
                'left'          => $closed ? (float) $s->cash_left_for_next : 0.0,
            ];
        })->values();
    }
}
