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
        'subtotal',
        'item_note',
        'allergen_flags',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
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

    public function saleItemOptions()
    {
        return $this->hasMany(SaleItemOption::class);
    }
}
