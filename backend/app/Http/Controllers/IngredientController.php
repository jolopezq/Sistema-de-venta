<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Http\Requests\IngredientRequest;
use App\Http\Resources\IngredientResource;
use App\Repositories\IngredientRepository;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class IngredientController extends Controller
{
    protected IngredientRepository $ingredients;

    public function __construct(IngredientRepository $ingredients, protected InventoryService $inventory)
    {
        $this->ingredients = $ingredients;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['category_id', 'search']);
        return IngredientResource::collection($this->ingredients->all($filters));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(IngredientRequest $request): IngredientResource
    {
        // El stock inicial queda como movimiento «initial» y el CPP arranca en el costo cargado
        $ingredient = $this->inventory->createIngredient($request->validated(), $request->user()->id);
        return new IngredientResource($ingredient);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id): IngredientResource
    {
        return new IngredientResource($this->ingredients->findWithMovements($id));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(IngredientRequest $request, int $id): IngredientResource
    {
        $ingredient = $this->ingredients->find($id);
        // El stock no se edita aquí: cambia solo con ingresos, mermas, conteos y ventas
        $updated = $this->inventory->updateIngredient($ingredient, $request->validated());
        return new IngredientResource($updated);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): Response|\Illuminate\Http\JsonResponse
    {
        $ingredient = $this->ingredients->find($id);
        try {
            $this->ingredients->delete($ingredient);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->noContent();
    }
}
