<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de ítem individual dentro de una venta.
 * Registra producto, cantidad (unidades o gramos), precio unitario
 * y modificaciones de toppings para bowls personalizables.
 */
class SaleItem extends Model
{
    use \App\Traits\Auditable;

    use HasFactory;

    protected $fillable = [
        'sale_id',
        'product_id',
        'quantity',
        'unit_price',
        'price_override_reason',
        'subtotal',
        'weight_grams',
        'is_takeaway',
        'item_note',
        'allergen_flags',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'weight_grams' => 'decimal:2',
            'is_takeaway' => 'boolean',
            'allergen_flags' => 'array',
        ];
    }

    // --- Relaciones ---

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Gramos vendidos de un producto por peso (null si el producto se vende por unidad).
     * Usa los gramos guardados; en ventas anteriores a octubre de 2026 los deduce del precio cobrado.
     */
    public function soldGrams(?Product $product = null): ?float
    {
        $product ??= $this->product;
        if (!$product || !$product->is_weight_based) {
            return null;
        }
        if ($this->weight_grams !== null) {
            return (float) $this->weight_grams;
        }

        return (float) $this->unit_price / ((float) $product->price_per_gram ?: 0.08);
    }

    public function saleItemOptions()
    {
        return $this->hasMany(SaleItemOption::class);
    }
}
