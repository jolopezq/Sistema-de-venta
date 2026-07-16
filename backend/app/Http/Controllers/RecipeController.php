<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Http\Requests\RecipeRequest;
use Illuminate\Http\JsonResponse;

class RecipeController extends Controller
{
    /**
     * Asigna un insumo a un producto (crea una receta).
     */
    public function store(RecipeRequest $request): JsonResponse
    {
        // Usa updateOrCreate para no duplicar la misma relación, sino actualizar la cantidad
        $recipe = Recipe::updateOrCreate(
            [
                'product_id' => $request->product_id,
                'ingredient_id' => $request->ingredient_id,
            ],
            [
                'quantity_required' => $request->quantity_required,
            ]
        );

        return response()->json($recipe, 201);
    }

    /**
     * Elimina un insumo de la receta de un producto.
     */
    public function destroy(Recipe $recipe): JsonResponse
    {
        $recipe->delete();
        return response()->json(null, 204);
    }
}
