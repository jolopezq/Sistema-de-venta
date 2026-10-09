<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountLine extends Model
{
    protected $fillable = [
        'inventory_count_id', 'ingredient_id', 'expected', 'counted', 'difference', 'unit_cost', 'cost', 'is_baseline',
    ];

    protected function casts(): array
    {
        return [
            'expected' => 'decimal:4',
            'counted' => 'decimal:4',
            'difference' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'cost' => 'decimal:2',
            'is_baseline' => 'boolean',
        ];
    }

    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class, 'inventory_count_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withTrashed();
    }
}
