<?php

namespace App\Http\Controllers;

use App\Models\CashMovement;
use App\Models\ExpenseCategory;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Egresos de caja vistos desde Finanzas: el dueño clasifica los que el cajero dejó sin categoría
 * para que el Estado de Resultados los ubique en la sección correcta (auditoría M4).
 */
class FinanceCashMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'year'         => ['required', 'integer', 'min:2020', 'max:2100'],
            'month'        => ['required', 'integer', 'min:1', 'max:12'],
            'unclassified' => ['nullable', 'boolean'],
        ]);

        $start = Carbon::create((int) $request->year, (int) $request->month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // `created_at` está en hora de La Paz: límites locales del mes, sin convertir
        $query = CashMovement::with(['expenseCategory', 'user:id,name'])
            ->where('type', 'expense')
            ->whereBetween('created_at', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59']);

        if ($request->boolean('unclassified')) {
            $query->whereNull('expense_category_id');
        }

        $movements = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data'  => $movements,
            'count' => $movements->count(),
            'total' => round((float) $movements->sum('amount'), 2),
        ]);
    }

    public function classify(Request $request, CashMovement $cashMovement): JsonResponse
    {
        $validated = $request->validate([
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
        ], [
            'expense_category_id.required' => 'Elige una categoría.',
            'expense_category_id.exists'   => 'La categoría seleccionada no existe.',
        ]);

        if (!$cashMovement->isExpense()) {
            return response()->json([
                'message' => 'Solo los egresos de caja se clasifican en una categoría de gasto.',
            ], 422);
        }

        $category = ExpenseCategory::find($validated['expense_category_id']);
        if (!$category->is_active) {
            return response()->json([
                'message' => "La categoría \"{$category->name}\" está desactivada.",
            ], 422);
        }

        $cashMovement->expense_category_id = $category->id;
        $cashMovement->save();

        return response()->json([
            'message'  => 'Egreso clasificado.',
            'movement' => $cashMovement->load(['expenseCategory', 'user:id,name']),
        ]);
    }
}
