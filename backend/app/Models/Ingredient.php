<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de insumo / materia prima.
 * Cada insumo mantiene su stock actual, stock mínimo para alertas,
 * y su Costo Promedio Ponderado (CPP) precalculado.
 */
class Ingredient extends Model
{
    use \App\Traits\Auditable;

    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'unit',
        'current_stock',
        'minimum_stock',
        'unit_cost',
        'weighted_avg_cost',
        'expiration_date',
        'min_shelf_date',
        'ingredient_category_id',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:4',
            'minimum_stock' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'weighted_avg_cost' => 'decimal:4',
            'expiration_date' => 'date',
            'min_shelf_date' => 'date',
        ];
    }

    // --- Relaciones ---

    public function category(): BelongsTo
    {
        return $this->belongsTo(IngredientCategory::class, 'ingredient_category_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    // --- Helpers ---

    /**
     * Verifica si el stock actual está por debajo del mínimo configurado.
     */
    public function isBelowMinimumStock(): bool
    {
        return $this->current_stock <= $this->minimum_stock;
    }
}
