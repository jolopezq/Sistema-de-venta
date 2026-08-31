<?php

namespace App\Http\Controllers;

use App\Repositories\CategoryRepository;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryRepository $categories
    ) {}

    /**
     * Lista todas las categorías en orden de presentación.
     */
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection($this->categories->allOrdered());
    }

    /**
     * Crea una nueva categoría.
     */
    public function store(CategoryRequest $request): CategoryResource
    {
        $category = $this->categories->create($request->validated());
        return new CategoryResource($category);
    }

    /**
     * Muestra una categoría específica.
     */
    public function show(int $id): CategoryResource
    {
        return new CategoryResource(\App\Models\Category::findOrFail($id));
    }

    /**
     * Actualiza una categoría existente.
     */
    public function update(CategoryRequest $request, \App\Models\Category $category): CategoryResource
    {
        return new CategoryResource($this->categories->update($category, $request->validated()));
    }

    /**
     * Soft-delete de una categoría.
     * Rechaza la eliminación si la categoría contiene productos asociados.
     */
    public function destroy(\App\Models\Category $category): Response|\Illuminate\Http\JsonResponse
    {
        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la categoría porque contiene productos asociados. Elimina o reasigna los productos primero.'
            ], 422);
        }

        $this->categories->delete($category);
        return response()->noContent();
    }
}
