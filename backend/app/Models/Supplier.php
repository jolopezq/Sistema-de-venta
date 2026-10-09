<?php

namespace App\Models;

use App\Support\NameKey;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Proveedor de insumos (o «Mercado»): lo que tarda en entregar sirve para el punto de reorden. */
class Supplier extends Model
{
    use Auditable;

    protected $fillable = ['name', 'name_key', 'phone', 'delivery_days', 'order_days', 'notes', 'is_active'];

    protected function casts(): array
    {
        return [
            'delivery_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Supplier $supplier) {
            $supplier->name = NameKey::tidy($supplier->name);
            $supplier->name_key = NameKey::make($supplier->name);
        });
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class);
    }
}
