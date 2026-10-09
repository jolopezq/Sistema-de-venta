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
        'tracks_stock',
        'is_key',
        'supplier_id',
        'purchase_unit',
        'purchase_factor',
        'lot_size',
        'reorder_policy',
        'target_days',
        'safety_days',
        'shelf_life_days',
        'daily_estimate',
    ];

    /** Cómo se controla la reposición: por punto de reorden, compra diaria (fruta) o sin control. */
    public const REORDER_POLICIES = ['reorder', 'daily', 'none'];

    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:4',
            'minimum_stock' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'weighted_avg_cost' => 'decimal:4',
            'tracks_stock' => 'boolean',
            'is_key' => 'boolean',
            'purchase_factor' => 'decimal:4',
            'lot_size' => 'decimal:4',
            'target_days' => 'integer',
            'safety_days' => 'decimal:2',
            'shelf_life_days' => 'integer',
            'daily_estimate' => 'decimal:4',
            'expiration_date' => 'date',
            'min_shelf_date' => 'date',
        ];
    }

    // --- Relaciones ---

    public function category(): BelongsTo
    {
        return $this->belongsTo(IngredientCategory::class, 'ingredient_category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function optionRecipes(): HasMany
    {
        return $this->hasMany(OptionRecipe::class);
    }

    /** Insumos reales que consume una unidad de este componente de costeo (vacía si no tiene composición). */
    public function composition(): HasMany
    {
        return $this->hasMany(IngredientComposition::class, 'component_id');
    }

    /** Composiciones de componentes que usan este insumo. */
    public function usedInCompositions(): HasMany
    {
        return $this->hasMany(IngredientComposition::class, 'ingredient_id');
    }

    /**
     * Costo de una unidad (kg, litro o unidad) para costear recetas: el costo promedio (CPP) si hubo compras
     * registradas; si no, el último costo cargado.
     */
    public function costPerUnit(): float
    {
        $cpp = (float) $this->weighted_avg_cost;

        return $cpp > 0 ? $cpp : (float) $this->unit_cost;
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
