<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OptionGroup extends Model
{
    use \App\Traits\Auditable;

    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'min_selections',
        'max_selections',
        'pedidosya_pricing',
        'is_active',
    ];

    protected $casts = [
        'min_selections' => 'integer',
        'max_selections' => 'integer',
        'pedidosya_pricing' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function options()
    {
        return $this->hasMany(Option::class)->orderBy('sort_order');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'option_group_product')
                    ->withPivot('sort_order')
                    ->withTimestamps();
    }
}
