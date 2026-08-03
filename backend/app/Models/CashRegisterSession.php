<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'status',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_amount' => 'decimal:2',
            'expected_closing' => 'decimal:2',
            'actual_closing' => 'decimal:2',
            'difference' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    // --- Relaciones ---

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    // --- Helpers ---

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
