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
        'amount',
        'description',
        'sale_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
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

    // --- Helpers ---

    public function isExpense(): bool
    {
        return $this->type === 'expense';
    }

    public function isExtraIncome(): bool
    {
        return $this->type === 'extra_income';
    }
}
