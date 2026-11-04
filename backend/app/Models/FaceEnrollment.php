<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una captura del rostro registrado de una persona (descriptor del navegador y foto de referencia). */
class FaceEnrollment extends Model
{
    protected $fillable = ['user_id', 'descriptor', 'model_version', 'photo_path', 'created_by', 'revoked_at'];

    protected $hidden = ['descriptor'];

    protected function casts(): array
    {
        return [
            'descriptor' => 'array',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
