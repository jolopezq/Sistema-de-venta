<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OptionRecipe extends Model
{
    use \App\Traits\Auditable;

    use HasFactory;

    protected $fillable = [
        'option_id',
        'ingredient_id',
        'quantity_delta',
    ];

    protected $casts = [
        'quantity_delta' => 'decimal:4',
    ];

    public function option()
    {
        return $this->belongsTo(Option::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
