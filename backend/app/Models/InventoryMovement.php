<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de movimiento de inventario.
 * Registra toda entrada y salida de insumos.
 * quantity_changed: positivo = entrada (restock), negativo = salida (venta, merma).
 */
class InventoryMovement extends Model
{
    use \App\Traits\Auditable;

    use HasFactory;

    /** Motivos de merma (plan de inventario, F3). La clave se guarda en `waste_category`. */
    public const WASTE_CATEGORIES = [
        'expired'    => 'Vencido',
        'damaged'    => 'Dañado',
        'spillage'   => 'Derrame',
        'prep_error' => 'Error de preparación',
        'sample'     => 'Prueba o cortesía',
        'staff'      => 'Consumo del personal',
    ];

    protected $fillable = [
        'ingredient_id',
        'quantity_changed',
        'type',
        'waste_category',
        'notes',
        'performed_by',
        'reference_type',
        'reference_id',
        'unit_cost',
        'total_cost',
    ];

    /** Etiqueta del motivo de merma, para las pantallas (una sola lista de motivos, la de arriba). */
    protected $appends = ['waste_label'];

    public function getWasteLabelAttribute(): ?string
    {
        return $this->waste_category ? (self::WASTE_CATEGORIES[$this->waste_category] ?? $this->waste_category) : null;
    }

    protected function casts(): array
    {
        return [
            'quantity_changed' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
        ];
    }

    // --- Relaciones ---

    public function reference()
    {
        return $this->morphTo();
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * Usuario que realizó el movimiento.
     */
    public function performedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
