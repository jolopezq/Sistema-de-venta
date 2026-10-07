<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class RecurringExpense extends Model
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'name',
        'expense_category_id',
        'amount',
        'frequency',
        'day_of_month',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'day_of_month' => 'integer',
            'is_active'    => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
