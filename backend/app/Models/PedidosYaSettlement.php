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
        'csv_pedidosya_discount',
        'csv_vendor_discount',
        'zip_path',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            // Se serializan como 'AAAA-MM-DD' (sin hora ni zona: un día de calendario)
            'start_date'           => 'date:Y-m-d',
            'end_date'             => 'date:Y-m-d',
            'payout_date'          => 'date:Y-m-d',
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
            'csv_pedidosya_discount' => 'decimal:2',
            'csv_vendor_discount'  => 'decimal:2',
            'orders_count'         => 'integer',
            'delivered_count'      => 'integer',
            'cancelled_count'      => 'integer',
        ];
    }

    /** Diferencia tolerada en la conciliación (plan §6.7: se muestra cada diferencia mayor a Bs 1). */
    public const TOLERANCE = 1.00;

    protected $appends = ['reconciliation'];

    /**
     * Conciliación de la semana (plan §6.7, auditoría M3):
     *  - total liquidado = ventas netas − envíos − servicios − cargos − publicidad + reintegros − impuestos
     *  - comisión del CSV = servicios PedidosYa del estado de cuenta
     *  - ventas brutas − descuentos PedidosYa = ventas netas (informativa: PedidosYa no siempre descuenta
     *    el monto exacto del CSV)
     */
    public function getReconciliationAttribute(): array
    {
        $check = function (string $key, string $label, float $expected, float $actual, bool $informative = false) {
            $difference = round($actual - $expected, 2);

            return [
                'key'         => $key,
                'label'       => $label,
                'expected'    => round($expected, 2),
                'actual'      => round($actual, 2),
                'difference'  => $difference,
                'ok'          => abs($difference) <= self::TOLERANCE,
                'informative' => $informative,
            ];
        };

        $checks = [
            $check('payout', 'Total liquidado según la fórmula', (float) $this->calculated_payout, (float) $this->total_payout),
            $check('commission', 'Comisión del CSV = servicios PedidosYa', (float) $this->csv_commission, (float) $this->service_fees),
            $check(
                'net_sales',
                'Ventas brutas − descuentos PedidosYa = ventas netas',
                (float) $this->csv_gross_sales - (float) $this->csv_pedidosya_discount,
                (float) $this->net_sales,
                true
            ),
        ];

        $failed = array_filter($checks, fn ($c) => !$c['ok'] && !$c['informative']);

        return [
            'status' => count($failed) === 0 ? 'ok' : 'difference',
            'checks' => $checks,
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
