<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Red del local: una marca que llega desde aquí cuenta como hecha en el local. */
class AttendanceNetwork extends Model
{
    protected $fillable = ['label', 'cidr', 'added_by', 'confirmed_from_mark_id', 'revoked_at'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
