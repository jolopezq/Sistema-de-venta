<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IngredientResource extends JsonResource
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
            'unit' => $this->unit,
            'current_stock' => (float) $this->current_stock,
            'minimum_stock' => (float) $this->minimum_stock,
            'unit_cost' => (float) $this->unit_cost,
            'weighted_avg_cost' => (float) $this->weighted_avg_cost,
            'expiration_date' => $this->expiration_date?->toDateString(),
            'min_shelf_date' => $this->min_shelf_date?->toDateString(),
        ];
    }
}
