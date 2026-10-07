<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class FinanceExpense extends Model
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'date',
        'expense_category_id',
        'supplier',
        'amount',
        'payment_method',
        'receipt_number',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'date'   => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
