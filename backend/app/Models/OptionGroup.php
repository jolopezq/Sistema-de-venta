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
        'is_active',
    ];

    public function options()
    {
        return $this->hasMany(Option::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'option_group_product')
                    ->withPivot('sort_order')
                    ->withTimestamps();
    }
}
