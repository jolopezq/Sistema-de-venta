<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecurringExpenseRequest;
use App\Models\RecurringExpense;
use App\Services\RecurringExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RecurringExpenseController extends Controller
{
    public function __construct(
        protected RecurringExpenseService $service
    ) {}

    /**
     * Gastos fijos que corresponden al mes, con su estado (pendiente, generado, descartado).
     */
    public function pending(Request $request): JsonResponse
    {
        [$year, $month] = $this->period($request);
        $items = $this->service->forPeriod($year, $month);
        $pending = $items->where('status', 'pending');

        return response()->json([
            'period'        => RecurringExpenseService::period($year, $month),
            'items'         => $items->all(),
            'pending_count' => $pending->count(),
            'pending_total' => round($pending->sum('amount'), 2),
        ]);
    }

    /**
     * Genera los gastos reales del mes (idempotente).
     */
    public function generate(Request $request): JsonResponse
    {
        [$year, $month] = $this->period($request);
        $request->validate([
            'ids'   => ['nullable', 'array'],
            'ids.*' => ['integer', 'exists:recurring_expenses,id'],
        ]);

        $result = $this->service->generate($year, $month, Auth::id(), $request->input('ids'));

        $total = $result['created'] + $result['restored'];

        return response()->json([
            'message'  => $total > 0
                ? "Se generaron {$total} gastos fijos del mes."
                : 'No había gastos fijos pendientes en el mes.',
            'created'  => $result['created'],
            'restored' => $result['restored'],
            'expenses' => $result['expenses'],
        ], $total > 0 ? 201 : 200);
    }

    private function period(Request $request): array
    {
        $request->validate([
            'year'  => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        return [(int) $request->input('year'), (int) $request->input('month')];
    }

    public function index(Request $request): JsonResponse
    {
        $query = RecurringExpense::with('category');

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $expenses = $query->orderBy('day_of_month')
            ->orderBy('name')
            ->get();

        return response()->json($expenses);
    }

    public function store(RecurringExpenseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['start_month'] = $data['start_month'] ?? now()->format('Y-m');
        $data['payment_method'] = $data['payment_method'] ?? 'bank_transfer';

        $expense = RecurringExpense::create($data);

        return response()->json([
            'message' => 'Gasto fijo registrado correctamente.',
            'expense' => $expense->load('category'),
        ], 201);
    }

    public function update(RecurringExpenseRequest $request, RecurringExpense $recurringExpense): JsonResponse
    {
        // Sin mes de inicio o forma de pago en la petición se conservan los actuales.
        // Cambiar el monto no modifica los gastos ya generados de meses anteriores.
        $data = array_filter(
            $request->validated(),
            fn ($value, $key) => !in_array($key, ['start_month', 'payment_method'], true) || $value !== null,
            ARRAY_FILTER_USE_BOTH
        );

        $recurringExpense->update($data);

        return response()->json([
            'message' => 'Gasto fijo actualizado correctamente.',
            'expense' => $recurringExpense->load('category'),
        ]);
    }

    public function toggleActive(RecurringExpense $recurringExpense): JsonResponse
    {
        $recurringExpense->is_active = !$recurringExpense->is_active;
        $recurringExpense->save();

        return response()->json([
            'message' => $recurringExpense->is_active ? 'Gasto fijo activado.' : 'Gasto fijo desactivado.',
            'expense' => $recurringExpense->load('category'),
        ]);
    }

    public function destroy(RecurringExpense $recurringExpense): JsonResponse
    {
        $recurringExpense->delete();

        return response()->json([
            'message' => 'Gasto fijo eliminado correctamente.',
        ]);
    }
}
