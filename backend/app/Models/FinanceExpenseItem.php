<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de una compra: un insumo (sube el stock) o un concepto suelto (solo gasto). */
class FinanceExpenseItem extends Model
{
    protected $fillable = [
        'finance_expense_id', 'ingredient_id', 'description', 'quantity', 'purchase_unit', 'factor',
        'stock_quantity', 'unit_cost', 'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'factor' => 'decimal:4',
            'stock_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total' => 'decimal:2',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(FinanceExpense::class, 'finance_expense_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withTrashed();
    }
}
