<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conteo físico de inventario (plan de inventario, F3): lo que se contó de cada insumo frente a lo que el
 * sistema decía. Al confirmarse genera un ajuste ligado por cada diferencia.
 */
class InventoryCount extends Model
{
    use \App\Traits\Auditable;

    public const SCOPES = ['key', 'all', 'partial'];

    protected $fillable = ['scope', 'notes', 'counted_by', 'counted_at', 'lines_count', 'difference_cost'];

    protected function casts(): array
    {
        return [
            'counted_at' => 'datetime',
            'difference_cost' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryCountLine::class);
    }

    public function countedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
