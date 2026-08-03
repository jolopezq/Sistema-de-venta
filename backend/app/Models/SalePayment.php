<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de pago asociado a una venta.
 * Permite pagos mixtos: una venta puede tener múltiples registros
 * (ej: 50 Bs efectivo + 30 Bs QR = 80 Bs total).
 */
class SalePayment extends Model
{
    use \App\Traits\Auditable;

    use HasFactory;

    protected $fillable = [
        'sale_id',
        'method',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    // --- Relaciones ---

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
