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
        'sale_link',
        'catalog_total',
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
            'catalog_total'      => 'decimal:2',
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

    /**
     * Texto que la primera versión de la importación por carpeta guardaba en los pedidos que solo venían del Excel.
     * Igual que `items_text` nulo: el pedido no tiene artículos y no puede crear su venta.
     */
    public const LEGACY_PLACEHOLDER = 'Pedido PedidosYa #';

    /** ¿El pedido trae sus artículos (del CSV)? */
    public static function hasDetail(?string $itemsText): bool
    {
        return $itemsText !== null && !str_starts_with($itemsText, self::LEGACY_PLACEHOLDER);
    }

    public function scopeWithDetail($query)
    {
        return $query->whereNotNull('items_text')->where('items_text', 'not like', self::LEGACY_PLACEHOLDER . '%');
    }
}
