<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngredientCategory extends Model
{
    use \App\Traits\Auditable;

    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * Insumos que pertenecen a esta categoría.
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }
}
