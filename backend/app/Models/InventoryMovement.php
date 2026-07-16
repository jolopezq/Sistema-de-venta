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
    use HasFactory;

    protected $fillable = [
        'ingredient_id',
        'quantity_changed',
        'type',
        'waste_category',
        'notes',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity_changed' => 'decimal:4',
        ];
    }

    // --- Relaciones ---

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
