<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    use \App\Traits\Auditable;

    protected $fillable = [
        'role',
        'module',
        'access_level',
    ];
}
