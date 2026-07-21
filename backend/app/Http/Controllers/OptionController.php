<?php

namespace App\Http\Controllers;

use App\Models\Option;
use App\Http\Requests\OptionRequest;
use App\Http\Resources\OptionResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class OptionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return OptionResource::collection(Option::with('recipes.ingredient')->get());
    }

    public function store(OptionRequest $request): OptionResource
    {
        $data = $request->validated();
        $option = Option::create($data);

        if (!empty($data['recipes'])) {
            $option->recipes()->createMany($data['recipes']);
        }

        return new OptionResource($option->load('recipes.ingredient'));
    }

    public function show(Option $option): OptionResource
    {
        return new OptionResource($option->load('recipes.ingredient'));
    }

    public function update(OptionRequest $request, Option $option): OptionResource
    {
        $data = $request->validated();
        $option->update($data);

        if (array_key_exists('recipes', $data)) {
            $option->recipes()->delete(); // Limpiar y recrear
            if (!empty($data['recipes'])) {
                $option->recipes()->createMany($data['recipes']);
            }
        }

        return new OptionResource($option->load('recipes.ingredient'));
    }

    public function destroy(Option $option): Response
    {
        $option->delete();
        return response()->noContent();
    }
}
