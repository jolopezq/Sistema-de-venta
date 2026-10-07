<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidosYaOrder extends Model
{
    protected $table = 'pedidosya_orders';
    protected $fillable = [
        'settlement_id',
        'order_number',
        'status',
        'order_date',
        'accepted_at',
        'ready_at',
        'pickup_at',
        'delivered_at',
        'cancelled_at',
        'payment_method',
        'delivery_type',
        'subtotal',
        'commission',
        'tax',
        'vendor_discount',
        'pedidosya_discount',
        'estimated_income',
        'items_text',
        'cancellation_reason',
        'sale_id',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            // Hora de La Paz tal como viene en el CSV (sin pasar a UTC al serializar)
            'order_date'         => 'datetime:Y-m-d H:i',
            'accepted_at'        => 'datetime:Y-m-d H:i',
            'ready_at'           => 'datetime:Y-m-d H:i',
            'pickup_at'          => 'datetime:Y-m-d H:i',
            'delivered_at'       => 'datetime:Y-m-d H:i',
            'cancelled_at'       => 'datetime:Y-m-d H:i',
            'subtotal'           => 'decimal:2',
            'commission'         => 'decimal:2',
            'tax'                => 'decimal:2',
            'vendor_discount'    => 'decimal:2',
            'pedidosya_discount' => 'decimal:2',
            'estimated_income'   => 'decimal:2',
            'raw_data'           => 'array',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PedidosYaSettlement::class, 'settlement_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
