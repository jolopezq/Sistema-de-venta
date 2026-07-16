<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de configuración del motor de fidelización (cashback).
 * Actúa como tabla de configuración singleton.
 * Define la tasa de acumulación, valor de redención,
 * días de expiración de puntos y categorías excluidas.
 */
class LoyaltyConfig extends Model
{
    protected $table = 'loyalty_config';

    protected $fillable = [
        'accumulation_rate',
        'redemption_value',
        'points_expiration_days',
        'excluded_categories',
    ];

    protected function casts(): array
    {
        return [
            'accumulation_rate' => 'decimal:2',
            'redemption_value' => 'decimal:2',
            'points_expiration_days' => 'integer',
            'excluded_categories' => 'array',
        ];
    }

    /**
     * Obtiene la configuración activa (singleton pattern a nivel de consulta).
     */
    public static function active(): ?self
    {
        return static::latest()->first();
    }
}
