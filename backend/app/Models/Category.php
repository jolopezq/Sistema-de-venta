<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de categoría de producto con soporte para subcategorías (autorreferencial).
 * Permite jerarquía ilimitada para clasificar productos en el POS.
 */
class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'parent_id',
        'sort_order',
    ];

    // --- Scopes ---

    /**
     * Ordena las categorías según el campo `sort_order` (para el menú del POS).
     * Uso: Category::ordered()->get()
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    // --- Relaciones ---

    /**
     * Categoría padre (nullable para categorías raíz).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Subcategorías directas.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Productos clasificados en esta categoría.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
