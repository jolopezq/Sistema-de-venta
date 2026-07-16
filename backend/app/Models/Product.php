<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de producto final (lo que se vende al cliente).
 * No tiene stock propio — se compone de insumos vía recetas.
 * Soporta venta por peso (gramos), precios VIP, y routing de comandas.
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'image_url',
        'price',
        'vip_price',
        'is_weight_based',
        'price_per_gram',
        'category_id',
        'printer_target',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'vip_price' => 'decimal:2',
            'price_per_gram' => 'decimal:4',
            'is_weight_based' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // --- Scopes ---

    /**
     * Filtra solo los productos activos (visibles en el POS).
     * Uso: Product::active()->get()
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // --- Relaciones ---

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Recetas (composición de insumos) de este producto.
     */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
