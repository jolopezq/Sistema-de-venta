<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ExpenseCategory::query();

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        if ($request->filled('section')) {
            $query->bySection($request->section);
        }

        $categories = $query->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    public function store(ExpenseCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        // Una categoría nueva va al final de su sección
        $data['sort_order'] = $data['sort_order'] ?? ((int) ExpenseCategory::where('section', $data['section'])->max('sort_order') + 1);
        $data['is_active'] = $data['is_active'] ?? true;
        // Las compras de insumos varían con lo vendido; el resto es fijo salvo que se indique otra cosa
        $data['cost_behavior'] = $data['cost_behavior'] ?? ($data['section'] === 'cost_of_sales' ? 'variable' : 'fixed');

        $category = ExpenseCategory::create($data);

        return response()->json([
            'message'  => 'Categoría creada correctamente.',
            'category' => $category,
        ], 201);
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('cost_behavior', $data) && $data['cost_behavior'] === null) {
            unset($data['cost_behavior']);
        }
        $expenseCategory->update($data);

        return response()->json([
            'message'  => 'Categoría actualizada correctamente.',
            'category' => $expenseCategory,
        ]);
    }

    public function toggleActive(ExpenseCategory $expenseCategory): JsonResponse
    {
        $expenseCategory->is_active = !$expenseCategory->is_active;
        $expenseCategory->save();

        return response()->json([
            'message'  => $expenseCategory->is_active ? 'Categoría activada.' : 'Categoría desactivada.',
            'category' => $expenseCategory,
        ]);
    }
}
