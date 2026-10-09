<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class FinanceExpense extends Model
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'date',
        'expense_category_id',
        'supplier',
        'supplier_id',
        'amount',
        'payment_method',
        'receipt_number',
        'notes',
        'user_id',
        'recurring_expense_id',
        'period',
    ];

    protected function casts(): array
    {
        return [
            // Se serializa como 'AAAA-MM-DD' (antes '…T04:00:00.000000Z' rompía la lista y el formulario)
            'date'   => 'date:Y-m-d',
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

    /** Líneas de la compra (vacío en un gasto simple). */
    public function items(): HasMany
    {
        return $this->hasMany(FinanceExpenseItem::class);
    }

    public function supplierRecord(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /** Gasto fijo que generó este gasto (vacío si se registró a mano). */
    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class)->withTrashed();
    }
}
