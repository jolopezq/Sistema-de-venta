<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de venta / ticket.
 * Usa UUID como clave primaria generado en el frontend (Offline-First).
 * El campo sync_status permite rastrear la sincronización offline → server.
 * El campo source diferencia ventas de POS directo vs PedidosYa.
 *
 * IMPORTANTE: Las operaciones de creación de venta (insertar sale + items,
 * descontar insumos, acumular puntos) deben ejecutarse dentro de
 * DB::transaction() para garantizar integridad ACID.
 */
class Sale extends Model
{
    use \App\Traits\Auditable;

    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'order_number',
        'daily_sequence',
        'cashier_id',
        'customer_id',
        'subtotal',
        'discount_amount',
        'total_amount',
        'status',
        'preparation_status',
        'preparation_started_at',
        'ready_at',
        'delivered_at',
        'void_reason',
        'voided_by',
        'source',
        'is_takeaway',
        'notes',
        'sync_status',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'is_takeaway' => 'boolean',
            'preparation_started_at' => 'datetime',
            'ready_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    // --- Relaciones ---

    /**
     * Cajero que atendió la venta.
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * Cliente asociado (opcional — ventas anónimas permitidas).
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Admin que autorizó la anulación (si aplica).
     */
    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Líneas de detalle de la venta.
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Pagos asociados a esta venta (soporta pagos mixtos).
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /**
     * Pedido de delivery asociado (si la venta proviene de PedidosYa).
     */
    public function deliveryOrder(): HasOne
    {
        return $this->hasOne(DeliveryOrder::class);
    }

    // --- Helpers ---

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    public function isPending(): bool
    {
        return $this->sync_status === 'pending';
    }
}
