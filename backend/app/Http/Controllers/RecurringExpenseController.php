<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecurringExpenseRequest;
use App\Models\RecurringExpense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecurringExpenseController extends Controller
{
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
        $expense = RecurringExpense::create($request->validated());

        return response()->json([
            'message' => 'Gasto fijo registrado correctamente.',
            'expense' => $expense->load('category'),
        ], 201);
    }

    public function update(RecurringExpenseRequest $request, RecurringExpense $recurringExpense): JsonResponse
    {
        $recurringExpense->update($request->validated());

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
