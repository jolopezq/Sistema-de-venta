<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'option_group_id' => $this->option_group_id,
            'name' => $this->name,
            'additional_price' => $this->additional_price,
            'delivery_price' => $this->delivery_price,
            'pedidosya_pricing' => $this->relationLoaded('optionGroup')
                ? (bool) $this->optionGroup?->pedidosya_pricing
                : (isset($this->pedidosya_pricing) ? (bool) $this->pedidosya_pricing : ($this->optionGroup ? (bool) $this->optionGroup->pedidosya_pricing : false)),
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'sort_order' => $this->sort_order,
            'recipes' => $this->whenLoaded('recipes'),
        ];
    }
}
