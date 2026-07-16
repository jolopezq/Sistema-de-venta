<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
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
            'ci_or_phone' => $this->ci_or_phone,
            'name' => $this->name,
            'loyalty_points' => (float) $this->loyalty_points,
            'segment' => $this->segment,
            'is_vip_pricing' => (bool) $this->is_vip_pricing,
        ];
    }
}
