<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cierre anterior de una caja que se reabrió para corregir (historial: nunca se borra ni se edita a mano).
 */
class CashSessionClosing extends Model
{
    use \App\Traits\Auditable;

    protected $fillable = [
        'cash_register_session_id',
        'expected_closing',
        'actual_closing',
        'difference',
        'total_expenses',
        'cash_withdrawn',
        'cash_left_for_next',
        'bill_breakdown',
        'diff_note',
        'closed_at',
        'reopened_by',
        'reopened_at',
        'reopen_reason',
        'reclosed_by',
        'reclosed_at',
        'reclose_reason',
    ];

    protected function casts(): array
    {
        return [
            'expected_closing'   => 'decimal:2',
            'actual_closing'     => 'decimal:2',
            'difference'         => 'decimal:2',
            'total_expenses'     => 'decimal:2',
            'cash_withdrawn'     => 'decimal:2',
            'cash_left_for_next' => 'decimal:2',
            'bill_breakdown'     => 'array',
            'closed_at'          => 'datetime',
            'reopened_at'        => 'datetime',
            'reclosed_at'        => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function reclosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reclosed_by');
    }
}
