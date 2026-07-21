<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Option extends Model
{
    use HasFactory;

    protected $fillable = [
        'option_group_id',
        'name',
        'additional_price',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'additional_price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function optionGroup()
    {
        return $this->belongsTo(OptionGroup::class);
    }

    public function recipes()
    {
        return $this->hasMany(OptionRecipe::class);
    }
}
