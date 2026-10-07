<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class PedidosYaSettlement extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'pedidosya_settlements';

    protected $fillable = [
        'start_date',
        'end_date',
        'payout_date',
        'net_sales',
        'delivery_fees',
        'service_fees',
        'operating_charges',
        'advertising',
        'refunds',
        'taxes',
        'total_payout',
        'calculated_payout',
        'discrepancy',
        'orders_count',
        'delivered_count',
        'cancelled_count',
        'csv_gross_sales',
        'csv_commission',
        'csv_estimated_income',
        'zip_path',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'start_date'           => 'date',
            'end_date'             => 'date',
            'payout_date'          => 'date',
            'net_sales'            => 'decimal:2',
            'delivery_fees'        => 'decimal:2',
            'service_fees'         => 'decimal:2',
            'operating_charges'    => 'decimal:2',
            'advertising'          => 'decimal:2',
            'refunds'              => 'decimal:2',
            'taxes'                => 'decimal:2',
            'total_payout'         => 'decimal:2',
            'calculated_payout'    => 'decimal:2',
            'discrepancy'          => 'decimal:2',
            'csv_gross_sales'      => 'decimal:2',
            'csv_commission'       => 'decimal:2',
            'csv_estimated_income' => 'decimal:2',
            'orders_count'         => 'integer',
            'delivered_count'      => 'integer',
            'cancelled_count'      => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PedidosYaOrder::class, 'settlement_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
