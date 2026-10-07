<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OptionGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'min_selections' => $this->min_selections,
            'max_selections' => $this->max_selections,
            'pedidosya_pricing' => (bool) $this->pedidosya_pricing,
            'is_active' => $this->is_active,
            'options' => $this->whenLoaded('options', function () {
                return OptionResource::collection(
                    $this->options->each(function ($opt) {
                        if (!$opt->relationLoaded('optionGroup')) {
                            $opt->setRelation('optionGroup', $this->resource);
                        }
                    })
                );
            }),
            'products' => ProductResource::collection($this->whenLoaded('products')),
        ];
    }
}
