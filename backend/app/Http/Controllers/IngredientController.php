<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Http\Requests\IngredientRequest;
use App\Http\Resources\IngredientResource;
use App\Repositories\IngredientRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class IngredientController extends Controller
{
    protected IngredientRepository $ingredients;

    public function __construct(IngredientRepository $ingredients)
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
        $ingredient = $this->ingredients->create($request->validated());
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
        $updated = $this->ingredients->update($ingredient, $request->validated());
        return new IngredientResource($updated);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): Response
    {
        $ingredient = $this->ingredients->find($id);
        $this->ingredients->delete($ingredient);
        return response()->noContent();
    }
}
