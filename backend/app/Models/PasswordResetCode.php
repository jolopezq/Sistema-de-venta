<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pedido de recuperación de contraseña: el código enviado por correo y, una vez verificado,
 * el permiso de un solo uso para poner la contraseña nueva. Solo guarda huellas, nunca el código.
 */
class PasswordResetCode extends Model
{
    protected $fillable = [
        'user_id', 'code_hash', 'attempts', 'expires_at', 'reset_token_hash', 'reset_expires_at', 'verified_at', 'used_at', 'ip',
    ];

    protected $hidden = ['code_hash', 'reset_token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'reset_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Sin usar (ni verificado ni descartado). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('used_at');
    }
}
