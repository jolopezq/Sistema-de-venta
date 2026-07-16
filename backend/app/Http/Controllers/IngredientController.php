<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Http\Requests\IngredientRequest;
use App\Http\Resources\IngredientResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class IngredientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        return IngredientResource::collection(Ingredient::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(IngredientRequest $request): IngredientResource
    {
        $ingredient = Ingredient::create($request->validated());
        return new IngredientResource($ingredient);
    }

    /**
     * Display the specified resource.
     */
    public function show(Ingredient $ingredient): IngredientResource
    {
        return new IngredientResource($ingredient);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(IngredientRequest $request, Ingredient $ingredient): IngredientResource
    {
        $ingredient->update($request->validated());
        return new IngredientResource($ingredient);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Ingredient $ingredient): Response
    {
        $ingredient->delete();
        return response()->noContent();
    }
}
