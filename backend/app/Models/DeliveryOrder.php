<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de pedido de delivery recibido desde plataformas externas.
 * Almacena el payload crudo del webhook y rastrea el estado del pedido
 * hasta que el repartidor lo retira.
 */
class DeliveryOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'external_id',
        'order_payload',
        'status',
        'sale_id',
    ];

    protected function casts(): array
    {
        return [
            'order_payload' => 'array',
        ];
    }

    // --- Relaciones ---

    /**
     * Venta interna generada a partir de este pedido de delivery.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
