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
            'is_active' => $this->is_active,
            'options' => OptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
