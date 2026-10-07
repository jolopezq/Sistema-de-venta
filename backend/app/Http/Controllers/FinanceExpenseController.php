<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinanceExpenseRequest;
use App\Models\FinanceExpense;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FinanceExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FinanceExpense::with(['category', 'user:id,name', 'recurringExpense:id,name,frequency']);

        if ($request->filled('category_id')) {
            $query->where('expense_category_id', $request->category_id);
        }

        if ($request->filled('section')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('section', $request->section);
            });
        }

        if ($request->filled('year') && $request->filled('month')) {
            $start = Carbon::create((int) $request->year, (int) $request->month, 1)->startOfMonth()->toDateString();
            $end = Carbon::create((int) $request->year, (int) $request->month, 1)->endOfMonth()->toDateString();
            // `date` se guarda como 'AAAA-MM-DD 00:00:00': el último día se incluye con 23:59:59
            $query->whereBetween('date', [$start, $end . ' 23:59:59']);
        } elseif ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('date', [$request->date_from, $request->date_to . ' 23:59:59']);
        }

        // Totales del filtro completo (no solo de la página), por sección contable
        $bySection = (clone $query)
            ->join('expense_categories', 'expense_categories.id', '=', 'finance_expenses.expense_category_id')
            ->selectRaw('expense_categories.section as section, COUNT(*) as count, SUM(finance_expenses.amount) as amount')
            ->groupBy('expense_categories.section')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->section => ['count' => (int) $row->count, 'amount' => round((float) $row->amount, 2)]]);

        $perPage = min(max((int) $request->input('per_page', 50), 1), 200);
        $expenses = $query->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json(array_merge($expenses->toArray(), [
            'totals' => [
                'count'      => $bySection->sum('count'),
                'amount'     => round($bySection->sum('amount'), 2),
                'by_section' => $bySection,
            ],
        ]));
    }

    public function store(FinanceExpenseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = Auth::id();

        $expense = FinanceExpense::create($data);

        return response()->json([
            'message' => 'Gasto o compra registrado correctamente.',
            'expense' => $expense->load(['category', 'user:id,name']),
        ], 201);
    }

    public function show(FinanceExpense $financeExpense): JsonResponse
    {
        return response()->json($financeExpense->load(['category', 'user:id,name']));
    }

    public function update(FinanceExpenseRequest $request, FinanceExpense $financeExpense): JsonResponse
    {
        $financeExpense->update($request->validated());

        return response()->json([
            'message' => 'Gasto actualizado correctamente.',
            'expense' => $financeExpense->load(['category', 'user:id,name']),
        ]);
    }

    public function destroy(FinanceExpense $financeExpense): JsonResponse
    {
        $financeExpense->delete();

        return response()->json([
            'message' => 'Gasto eliminado correctamente.',
        ]);
    }
}
