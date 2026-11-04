<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Planilla aprobada de un período (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.6). */
class PayrollPeriod extends Model
{
    protected $fillable = [
        'period_start', 'period_end', 'status', 'gross_total', 'advances_total', 'others_total', 'net_total',
        'payment_method', 'finance_expense_id', 'approved_by', 'approved_at', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_start'   => 'date:Y-m-d',
            'period_end'     => 'date:Y-m-d',
            'gross_total'    => 'decimal:2',
            'advances_total' => 'decimal:2',
            'others_total'   => 'decimal:2',
            'net_total'      => 'decimal:2',
            'approved_at'    => 'datetime',
            'voided_at'      => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(FinanceExpense::class, 'finance_expense_id')->withTrashed();
    }
}
