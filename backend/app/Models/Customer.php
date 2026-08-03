<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de cliente del negocio.
 * Identificado por CI o celular para búsqueda rápida en caja.
 * Incluye puntos de fidelización y segmentación dinámica.
 */
class Customer extends Model
{
    use \App\Traits\Auditable;

    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ci_or_phone',
        'name',
        'loyalty_points',
        'segment',
        'is_vip_pricing',
    ];

    protected function casts(): array
    {
        return [
            'loyalty_points' => 'decimal:2',
            'is_vip_pricing' => 'boolean',
        ];
    }

    // --- Relaciones ---

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    // --- Helpers ---

    public function isVip(): bool
    {
        return $this->segment === 'vip';
    }
}
