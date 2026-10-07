<?php

namespace App\Services;

use App\Models\FinanceExpense;
use App\Models\RecurringExpense;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gastos fijos por período (plan de Finanzas §8, auditoría A2).
 *
 * Cada mes se proponen los gastos fijos que corresponden y el dueño los genera: se crea un gasto real
 * (`finance_expenses`) por plantilla y mes. El Estado de Resultados solo lee gastos reales, de modo que
 * una plantilla nueva no afecta meses pasados y cambiar su monto no reescribe la historia.
 */
class RecurringExpenseService
{
    public static function period(int $year, int $month): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }

    /**
     * Gastos fijos del mes con su estado:
     *  - pending:   corresponde y todavía no se generó
     *  - generated: ya existe el gasto del mes
     *  - dismissed: se generó y luego se eliminó (no se vuelve a proponer)
     */
    public function forPeriod(int $year, int $month): Collection
    {
        $period = self::period($year, $month);

        $existing = FinanceExpense::withTrashed()
            ->where('period', $period)
            ->whereNotNull('recurring_expense_id')
            ->get()
            ->keyBy('recurring_expense_id');

        $templates = RecurringExpense::with('category')
            ->where(function ($q) use ($existing) {
                $q->where('is_active', true)->orWhereIn('id', $existing->keys());
            })
            ->orderBy('day_of_month')
            ->orderBy('name')
            ->get();

        return $templates
            ->filter(fn (RecurringExpense $t) => $existing->has($t->id) || $t->isDueIn($year, $month))
            ->map(function (RecurringExpense $t) use ($existing) {
                $expense = $existing->get($t->id);
                $status = $expense === null ? 'pending' : ($expense->trashed() ? 'dismissed' : 'generated');

                return [
                    'id'             => $t->id,
                    'name'           => $t->name,
                    'category'       => $t->category ? ['id' => $t->category->id, 'name' => $t->category->name, 'section' => $t->category->section] : null,
                    'amount'         => (float) $t->amount,
                    'frequency'      => $t->frequency,
                    'day_of_month'   => $t->day_of_month,
                    'payment_method' => $t->payment_method,
                    'is_active'      => $t->is_active,
                    'status'         => $status,
                    'expense'        => $expense ? [
                        'id'     => $expense->id,
                        'date'   => $expense->date->toDateString(),
                        'amount' => (float) $expense->amount,
                    ] : null,
                ];
            })
            ->values();
    }

    /**
     * Genera los gastos del mes. Es idempotente: lo ya generado no se duplica.
     * Sin `$ids` genera todos los pendientes; con `$ids` genera solo esos y recupera los descartados.
     *
     * @return array{created: int, restored: int, expenses: array}
     */
    public function generate(int $year, int $month, int $userId, ?array $ids = null): array
    {
        $period = self::period($year, $month);
        $items = $this->forPeriod($year, $month);

        if ($ids !== null) {
            $ids = array_map('intval', $ids);
            $items = $items->filter(fn ($i) => in_array($i['id'], $ids, true));
        } else {
            $items = $items->where('status', 'pending');
        }

        $created = 0;
        $restored = 0;
        $expenses = [];

        DB::transaction(function () use ($items, $year, $month, $period, $userId, &$created, &$restored, &$expenses) {
            foreach ($items as $item) {
                if ($item['status'] === 'generated') {
                    continue;
                }

                if ($item['status'] === 'dismissed') {
                    $expense = FinanceExpense::withTrashed()->find($item['expense']['id']);
                    $expense->restore();
                    $restored++;
                    $expenses[] = $expense;
                    continue;
                }

                $template = RecurringExpense::find($item['id']);
                $day = min(max((int) $template->day_of_month, 1), Carbon::create($year, $month, 1)->daysInMonth);

                $expenses[] = FinanceExpense::firstOrCreate(
                    ['recurring_expense_id' => $template->id, 'period' => $period],
                    [
                        'date'                => Carbon::create($year, $month, $day)->toDateString(),
                        'expense_category_id' => $template->expense_category_id,
                        'supplier'            => $template->name,
                        'amount'              => $template->amount,
                        'payment_method'      => $template->payment_method ?: 'bank_transfer',
                        'notes'               => 'Gasto fijo ' . $period,
                        'user_id'             => $userId,
                    ]
                );
                $created++;
            }
        });

        return [
            'created'  => $created,
            'restored' => $restored,
            'expenses' => collect($expenses)->map(fn ($e) => $e->load('category'))->all(),
        ];
    }
}
