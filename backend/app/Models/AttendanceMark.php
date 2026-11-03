<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrada o una salida. Nunca se borra ni se edita en su lugar: una corrección crea otra marca con
 * source = manual y corrected_from_id, y la original pasa a «corrected».
 */
class AttendanceMark extends Model
{
    public const STATUS_VALID = 'valid';
    public const STATUS_PENDING_NETWORK = 'pending_network';
    public const STATUS_FACE_REVIEW = 'face_review';
    public const STATUS_OFFLINE_REVIEW = 'offline_review';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CORRECTED = 'corrected';

    /** Marcas a revisar: no cuentan para la planilla hasta que un administrador las aprueba. */
    public const PENDING = [self::STATUS_PENDING_NETWORK, self::STATUS_FACE_REVIEW, self::STATUS_OFFLINE_REVIEW];

    /** Marcas que existen para el trabajador (las rechazadas y las reemplazadas no). */
    public const LIVE = [self::STATUS_VALID, self::STATUS_PENDING_NETWORK, self::STATUS_FACE_REVIEW, self::STATUS_OFFLINE_REVIEW];

    protected $fillable = [
        'user_id', 'type', 'marked_at', 'device_time', 'received_at', 'ip', 'device_id', 'network_ok', 'device_ok',
        'face_score', 'liveness_ok', 'photo_path', 'status', 'source', 'reason', 'corrected_from_id', 'created_by',
        'reviewed_by', 'reviewed_at', 'review_note', 'local_uuid',
    ];

    protected $hidden = ['photo_path'];

    protected function casts(): array
    {
        return [
            'marked_at'   => 'datetime',
            'device_time' => 'datetime',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'network_ok'  => 'boolean',
            'device_ok'   => 'boolean',
            'liveness_ok' => 'boolean',
            'face_score'  => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeLive($query)
    {
        return $query->whereIn('status', self::LIVE);
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING, true);
    }
}
