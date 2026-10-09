<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseRequest;
use App\Models\ExpenseCategory;
use App\Models\FinanceExpense;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compras de insumos (admin y super_admin): un solo formulario que usan Finanzas e Inventario.
 * Crea el gasto, sube el stock y actualiza el costo promedio.
 */
class PurchaseController extends Controller
{
    public function __construct(private readonly PurchaseService $purchases) {}

    /** Datos para armar el formulario: categorías de costo de ventas, proveedores e insumos con sus datos de compra. */
    public function options(): JsonResponse
    {
        return response()->json([
            'categories' => ExpenseCategory::active()->bySection('cost_of_sales')->orderBy('sort_order')->get(['id', 'name']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'delivery_days']),
            'ingredients' => Ingredient::where('tracks_stock', true)->orderBy('name')
                ->get(['id', 'name', 'unit', 'purchase_unit', 'purchase_factor', 'supplier_id', 'unit_cost', 'weighted_avg_cost']),
        ]);
    }

    public function show(FinanceExpense $financeExpense): JsonResponse
    {
        return response()->json($financeExpense->load(['category', 'user:id,name', 'items.ingredient:id,name,unit']));
    }

    public function store(PurchaseRequest $request): JsonResponse
    {
        $expense = $this->purchases->create($request->validated(), $request->user()->id);

        return response()->json(['message' => 'Compra registrada.', 'expense' => $expense], 201);
    }

    public function update(PurchaseRequest $request, FinanceExpense $financeExpense): JsonResponse
    {
        if ($financeExpense->recurring_expense_id) {
            return response()->json(['message' => 'Un gasto fijo generado no es una compra.'], 422);
        }
        [$expense, $warnings] = $this->purchases->update($financeExpense, $request->validated(), $request->user()->id);

        return response()->json(['message' => 'Compra actualizada.', 'expense' => $expense, 'warnings' => $warnings]);
    }

    public function destroy(Request $request, FinanceExpense $financeExpense): JsonResponse
    {
        $warnings = $this->purchases->delete($financeExpense, $request->user()->id);

        return response()->json(['message' => 'Compra eliminada y stock devuelto.', 'warnings' => $warnings]);
    }
}
