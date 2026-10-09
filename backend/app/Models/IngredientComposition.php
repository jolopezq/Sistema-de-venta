<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de la composición de un componente de costeo: cuánto de un insumo real consume 1 unidad del componente.
 * Solo un nivel: el destino siempre es un insumo con stock, nunca otro componente.
 */
class IngredientComposition extends Model
{
    use \App\Traits\Auditable;

    protected $fillable = ['component_id', 'ingredient_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'component_id')->withTrashed();
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withTrashed();
    }
}
