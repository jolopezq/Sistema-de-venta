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
        $query = FinanceExpense::with(['category', 'user:id,name']);

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
            $query->whereBetween('date', [$start, $end]);
        } elseif ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('date', [$request->date_from, $request->date_to]);
        }

        $expenses = $query->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 50));

        return response()->json($expenses);
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
