<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de receta: tabla pivote entre Producto e Insumo.
 * Define cuánta cantidad de un insumo se requiere para producir
 * una unidad de un producto final.
 */
class Recipe extends Model
{
    use \App\Traits\Auditable;

    use HasFactory;

    protected $fillable = [
        'product_id',
        'ingredient_id',
        'quantity_required',
    ];

    protected function casts(): array
    {
        return [
            'quantity_required' => 'decimal:4',
        ];
    }

    // --- Relaciones ---

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
