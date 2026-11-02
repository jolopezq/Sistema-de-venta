<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de movimiento de efectivo durante un turno de caja.
 *
 * Registra egresos (compra de insumos, pago de delivery en efectivo, etc.)
 * e ingresos extraordinarios no relacionados a una venta formal.
 *
 * La ecuación del arqueo descuenta estos egresos del efectivo esperado:
 *   expected_cash = opening_amount + cash_sales - total_expenses
 *
 * Cualquier cajero con turno abierto puede registrar un movimiento;
 * el campo user_id y created_at quedan en el registro de auditoría.
 */
class CashMovement extends Model
{
    use SoftDeletes, \App\Traits\Auditable;

    protected $fillable = [
        'cash_register_session_id',
        'user_id',
        'expense_category_id',
        'type',
        'payment_method',
        'cash_amount',
        'amount',
        'description',
        'sale_id',
        'occurred_at',
        'edited_by',
        'edited_at',
        'edit_reason',
        'edit_previous',
        'edit_count',
        'voided_by',
        'void_reason',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'        => 'decimal:2',
            'cash_amount'   => 'decimal:2',
            'occurred_at'   => 'datetime',
            'edited_at'     => 'datetime',
            'edit_previous' => 'array',
            'edit_count'    => 'integer',
        ];
    }

    /** Cuándo ocurrió el egreso o ingreso: por defecto, al registrarlo. Un egreso de un día pasado lo trae explícito. */
    protected static function booted(): void
    {
        static::creating(function (CashMovement $movement) {
            $movement->occurred_at ??= $movement->created_at ?? now();
        });
    }

    // --- Relaciones ---

    /**
     * Categoría contable asignada al movimiento.
     */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * Sesión de caja a la que pertenece este movimiento.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    /**
     * Usuario que registró el movimiento (cajero, admin o super_admin).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Venta asociada opcionalemente al movimiento
     * (ej: pago de delivery en efectivo de una venta cobrada por QR).
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Usuario que corrigió el movimiento por última vez.
     */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /**
     * Usuario que anuló el movimiento.
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    // --- Helpers ---

    /**
     * Parte que salió o entró por la gaveta: todo si es en efectivo, `cash_amount` si es un gasto dividido
     * (efectivo + QR, docs/PLAN_GASTO_PAGO_DIVIDIDO.md), nada si es QR o tarjeta.
     */
    public function cashPart(): float
    {
        return match ($this->payment_method ?? 'cash') {
            'cash'  => (float) $this->amount,
            'mixed' => (float) $this->cash_amount,
            default => 0.0,
        };
    }

    /**
     * Método en palabras, para la auditoría: «Efectivo», «QR», «Tarjeta» o, si está dividido,
     * «Efectivo + QR (Bs 40.00 efectivo + Bs 60.00 QR)».
     */
    public static function methodText(?string $method, float $amount, ?float $cashAmount = null): string
    {
        return match ($method ?? 'cash') {
            'cash'  => 'Efectivo',
            'qr'    => 'QR',
            'card'  => 'Tarjeta',
            'mixed' => sprintf(
                'Efectivo + QR (Bs %s efectivo + Bs %s QR)',
                number_format((float) $cashAmount, 2, '.', ''),
                number_format($amount - (float) $cashAmount, 2, '.', '')
            ),
            default => (string) $method,
        };
    }

    public function isExpense(): bool
    {
        return $this->type === 'expense';
    }

    public function isExtraIncome(): bool
    {
        return $this->type === 'extra_income';
    }

    public function isVoided(): bool
    {
        return $this->trashed();
    }

    public function isEdited(): bool
    {
        return (int) ($this->edit_count ?? 0) > 0;
    }
}
