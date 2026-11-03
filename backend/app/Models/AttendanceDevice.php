<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Equipo autorizado para el reloj de asistencia. Se guarda la huella SHA-256 de su llave, nunca la llave. */
class AttendanceDevice extends Model
{
    protected $fillable = ['name', 'token_hash', 'authorized_by', 'last_seen_at', 'last_ip', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at'   => 'datetime',
        ];
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
