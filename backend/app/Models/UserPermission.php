<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Excepción de permiso para una persona: reemplaza el nivel de su rol en un módulo, sin superar el techo del rol.
 */
class UserPermission extends Model
{
    use \App\Traits\Auditable;

    protected $fillable = [
        'user_id',
        'module',
        'access_level',
        'granted_by',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
