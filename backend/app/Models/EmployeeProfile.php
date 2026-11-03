<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Datos laborales de una persona: cómo se le paga, su horario, su PIN para marcar y el consentimiento del rostro.
 * Solo los administradores ven estos datos (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.8).
 */
class EmployeeProfile extends Model
{
    public const PAY_MODES = ['hourly', 'monthly', 'per_shift'];

    protected $fillable = [
        'user_id',
        'attendance_enabled',
        'pay_mode',
        'hourly_rate',
        'monthly_salary',
        'shift_rate',
        'shift_hours',
        'schedule',
        'late_tolerance_min',
        'overtime_after_hours',
        'overtime_multiplier',
        'sunday_multiplier',
        'holiday_multiplier',
    ];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'attendance_enabled'   => 'boolean',
            'hourly_rate'          => 'decimal:2',
            'monthly_salary'       => 'decimal:2',
            'shift_rate'           => 'decimal:2',
            'shift_hours'          => 'decimal:2',
            'schedule'             => 'array',
            'late_tolerance_min'   => 'integer',
            'overtime_after_hours' => 'decimal:2',
            'overtime_multiplier'  => 'decimal:2',
            'sunday_multiplier'    => 'decimal:2',
            'holiday_multiplier'   => 'decimal:2',
            'face_consent_at'      => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'face_consent_by');
    }

    public function hasPin(): bool
    {
        return !empty($this->pin_hash);
    }

    /**
     * Horario de un día ISO (1 = lunes … 7 = domingo), o null si ese día no trabaja.
     *
     * @return array{start: string, end: string}|null
     */
    public function scheduleFor(int $isoDay): ?array
    {
        $day = $this->schedule[(string) $isoDay] ?? $this->schedule[$isoDay] ?? null;
        if (!is_array($day) || empty($day['start']) || empty($day['end'])) {
            return null;
        }

        return ['start' => $day['start'], 'end' => $day['end']];
    }
}
