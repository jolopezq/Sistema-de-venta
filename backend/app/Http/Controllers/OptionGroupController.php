<?php

namespace App\Http\Controllers;

use App\Models\OptionGroup;
use App\Http\Requests\OptionGroupRequest;
use App\Http\Resources\OptionGroupResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OptionGroupController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return OptionGroupResource::collection(OptionGroup::with('options.recipes.ingredient')->get());
    }

    public function store(OptionGroupRequest $request): OptionGroupResource
    {
        return DB::transaction(function () use ($request) {
            $group = OptionGroup::create($request->safe()->except('options'));
            
            if ($request->has('options')) {
                foreach ($request->input('options') as $optionData) {
                    $group->options()->create($optionData);
                }
            }
            
            return new OptionGroupResource($group->load('options'));
        });
    }

    public function show(OptionGroup $optionGroup): OptionGroupResource
    {
        return new OptionGroupResource($optionGroup->load('options.recipes.ingredient'));
    }

    public function update(OptionGroupRequest $request, OptionGroup $optionGroup): OptionGroupResource
    {
        return DB::transaction(function () use ($request, $optionGroup) {
            $optionGroup->update($request->safe()->except('options'));
            
            if ($request->has('options')) {
                $optionsData = collect($request->input('options'));
                $existingIds = $optionsData->pluck('id')->filter()->toArray();
                
                // Delete options not present in the payload
                $optionGroup->options()->whereNotIn('id', $existingIds)->delete();
                
                foreach ($optionsData as $optionData) {
                    if (isset($optionData['id'])) {
                        $optionGroup->options()->where('id', $optionData['id'])->update([
                            'name' => $optionData['name'],
                            'additional_price' => $optionData['additional_price'],
                            'is_active' => $optionData['is_active'] ?? true,
                        ]);
                    } else {
                        $optionGroup->options()->create($optionData);
                    }
                }
            }
            
            return new OptionGroupResource($optionGroup->fresh('options'));
        });
    }

    public function destroy(OptionGroup $optionGroup): Response
    {
        $optionGroup->delete();
        return response()->noContent();
    }

    public function attachProducts(Request $request, OptionGroup $optionGroup): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['exists:products,id']
        ]);

        $optionGroup->products()->sync($request->product_ids);

        return response()->json(['message' => 'Products attached successfully']);
    }
}
