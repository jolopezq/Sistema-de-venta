<?php

namespace App\Http\Controllers;

use App\Models\OptionRecipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestiona las recetas de insumos asociadas a una Opción (OptionRecipe).
 *
 * Cada Opción puede afectar el inventario de distintos insumos al
 * ser seleccionada en una venta. quantity_required puede ser negativo
 * para opciones del tipo "Sin Granola" (devuelve insumos de la receta base).
 */
class OptionRecipeController extends Controller
{
    /**
     * Crea o actualiza un insumo en la receta de una opción.
     * Valida la unicidad del par (option_id, ingredient_id) a nivel DB.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'option_id'         => ['required', 'exists:options,id'],
            'ingredient_id'     => ['required', 'exists:ingredients,id'],
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
        ], [
            'option_id.exists'         => 'La opción seleccionada no existe.',
            'ingredient_id.exists'     => 'El insumo seleccionado no existe.',
            'quantity_delta.not_in'    => 'La cantidad no puede ser cero.',
        ]);

        // updateOrCreate para evitar duplicados y actuar como idempotente
        $recipe = OptionRecipe::updateOrCreate(
            [
                'option_id'     => $validated['option_id'],
                'ingredient_id' => $validated['ingredient_id'],
            ],
            [
                'quantity_delta' => $validated['quantity_delta'],
            ]
        );

        // Cargar el insumo para devolver nombre y unidad al frontend
        $recipe->load('ingredient');

        return response()->json([
            'message' => 'Receta guardada correctamente.',
            'data'    => $recipe,
        ], 201);
    }

    /**
     * Elimina un insumo de la receta de una opción (soft delete no aplica aquí,
     * ya que OptionRecipe es una tabla de configuración, no de historial).
     */
    public function destroy(OptionRecipe $optionRecipe): JsonResponse
    {
        $optionRecipe->delete();

        return response()->json([
            'message' => 'Insumo eliminado de la receta correctamente.',
        ]);
    }
}
