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
        $category = ExpenseCategory::create($request->validated());

        return response()->json([
            'message'  => 'Categoría creada correctamente.',
            'category' => $category,
        ], 201);
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $expenseCategory->update($request->validated());

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
