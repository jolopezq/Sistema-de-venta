<?php

namespace App\Http\Controllers;

use App\Models\IngredientCategory;
use App\Http\Requests\IngredientCategoryRequest;
use App\Repositories\IngredientCategoryRepository;
use Illuminate\Http\JsonResponse;

class IngredientCategoryController extends Controller
{
    public function __construct(private IngredientCategoryRepository $repository)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->repository->all());
    }

    public function store(IngredientCategoryRequest $request): JsonResponse
    {
        $category = $this->repository->create($request->validated());
        return response()->json($category, 201);
    }

    public function show(IngredientCategory $ingredientCategory): JsonResponse
    {
        return response()->json($ingredientCategory);
    }

    public function update(IngredientCategoryRequest $request, IngredientCategory $ingredientCategory): JsonResponse
    {
        $category = $this->repository->update($ingredientCategory, $request->validated());
        return response()->json($category);
    }

    public function destroy(IngredientCategory $ingredientCategory): JsonResponse
    {
        if ($ingredientCategory->ingredients()->count() > 0) {
            return response()->json(['message' => 'No se puede eliminar la categoría porque tiene insumos asociados.'], 400);
        }

        $this->repository->delete($ingredientCategory);
        return response()->json(null, 204);
    }
}
