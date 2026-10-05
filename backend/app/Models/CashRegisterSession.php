<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo de sesión de caja (arqueo).
 * Cada turno de cajero abre una sesión con un fondo inicial.
 * Al cerrar turno, el sistema calcula el monto esperado vs el declarado.
 */
class CashRegisterSession extends Model
{
    use \App\Traits\Auditable;

    use HasFactory;

    protected $fillable = [
        'cashier_id',
        'opening_amount',
        'expected_closing',
        'actual_closing',
        'difference',
        'total_expenses',
        'cash_withdrawn',
        'cash_left_for_next',
        'bill_breakdown',
        'diff_note',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_amount'     => 'decimal:2',
            'expected_closing'   => 'decimal:2',
            'actual_closing'     => 'decimal:2',
            'difference'         => 'decimal:2',
            'total_expenses'     => 'decimal:2',
            'cash_withdrawn'     => 'decimal:2',
            'cash_left_for_next' => 'decimal:2',
            'bill_breakdown'     => 'array',
            'opened_at'          => 'datetime',
            'closed_at'          => 'datetime',
        ];
    }

    // --- Relaciones ---

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * Movimientos de efectivo (egresos e ingresos extraordinarios) del turno.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * Ventas cobradas en este turno.
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    // --- Helpers ---

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
