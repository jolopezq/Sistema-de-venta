<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'ci' => $this->ci,
            'phone' => $this->phone,
            'start_date' => $this->start_date,
            // Excepciones de permisos por persona {módulo: nivel} (solo si se cargaron): insignias en Usuarios
            'permission_overrides' => $this->whenLoaded(
                'permissionOverrides',
                fn () => $this->permissionOverrides->pluck('access_level', 'module')
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
