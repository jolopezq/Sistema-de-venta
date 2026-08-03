<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'price' => (float) $this->price,
            'vip_price' => $this->vip_price !== null ? (float) $this->vip_price : null,
            'delivery_price' => $this->delivery_price !== null ? (float) $this->delivery_price : null,
            'is_weight_based' => (bool) $this->is_weight_based,
            'price_per_gram' => $this->price_per_gram !== null ? (float) $this->price_per_gram : null,
            'category_id' => $this->category_id,
            'printer_target' => $this->printer_target,
            'is_active' => (bool) $this->is_active,
            'reactivate_at' => $this->reactivate_at ? $this->reactivate_at->toIso8601String() : null,
            'tag' => $this->tag,
            'option_groups' => OptionGroupResource::collection($this->whenLoaded('optionGroups')),
            'excluded_options' => $this->whenLoaded('excludedOptions', function () {
                return $this->excludedOptions->pluck('id');
            }),
            'recipes' => $this->whenLoaded('recipes'),
        ];
    }
}
