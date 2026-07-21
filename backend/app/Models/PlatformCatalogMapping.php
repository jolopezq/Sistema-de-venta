<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformCatalogMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'entity_type',
        'external_id',
        'internal_id',
    ];
}
