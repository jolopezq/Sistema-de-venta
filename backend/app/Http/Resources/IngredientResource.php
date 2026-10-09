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
            'ingredient_category_id' => $this->ingredient_category_id,
            'type' => $this->type,
            'tracks_stock' => (bool) $this->tracks_stock,
            'is_key' => (bool) $this->is_key,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? ['id' => $this->supplier->id, 'name' => $this->supplier->name] : null),
            'purchase_unit' => $this->purchase_unit,
            'purchase_factor' => (float) ($this->purchase_factor ?: 1),
            'lot_size' => $this->lot_size !== null ? (float) $this->lot_size : null,
            'reorder_policy' => $this->reorder_policy ?: 'reorder',
            'target_days' => $this->target_days,
            'safety_days' => $this->safety_days !== null ? (float) $this->safety_days : null,
            'shelf_life_days' => $this->shelf_life_days,
            'daily_estimate' => $this->daily_estimate !== null ? (float) $this->daily_estimate : null,
            'category' => $this->whenLoaded('category'),
        ];
    }
}
