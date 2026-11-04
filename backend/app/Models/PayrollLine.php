<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una persona en una planilla aprobada, con las tarifas que se usaron. */
class PayrollLine extends Model
{
    protected $fillable = [
        'payroll_period_id', 'user_id', 'pay_mode', 'days_worked', 'normal_minutes', 'overtime_minutes', 'late_minutes',
        'absences', 'gross', 'advances', 'others', 'others_reason', 'net', 'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'gross'    => 'decimal:2',
            'advances' => 'decimal:2',
            'others'   => 'decimal:2',
            'net'      => 'decimal:2',
            'snapshot' => 'array',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
