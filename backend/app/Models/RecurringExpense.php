<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'start_month',
        'payment_method',
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

    /** Gastos reales generados desde esta plantilla (uno por período). */
    public function generatedExpenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'recurring_expense_id');
    }

    /** Meses entre pagos según la frecuencia. */
    public function intervalMonths(): int
    {
        return match ($this->frequency) {
            'bimonthly' => 2,
            'quarterly' => 3,
            'annual'    => 12,
            default     => 1,
        };
    }

    /**
     * ¿Corresponde pagar este gasto fijo en el mes indicado?
     * Mensual: todos los meses desde `start_month`. Bimestral, trimestral y anual: cada 2, 3 o 12
     * meses contando desde `start_month` (el monto entra completo en ese mes, sin dividirlo).
     */
    public function isDueIn(int $year, int $month): bool
    {
        $start = $this->start_month ?: ($this->created_at?->format('Y-m') ?? sprintf('%04d-%02d', $year, $month));
        [$startYear, $startMonth] = array_map('intval', explode('-', $start));

        $diff = ($year * 12 + $month) - ($startYear * 12 + $startMonth);

        return $diff >= 0 && $diff % $this->intervalMonths() === 0;
    }
}
