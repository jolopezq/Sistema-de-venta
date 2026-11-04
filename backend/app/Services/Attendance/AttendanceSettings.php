<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\DB;

/**
 * Ajustes del reloj guardados en app_settings. Por ahora uno: si abrir la caja exige haber marcado la entrada
 * (las dos primeras semanas solo se avisa; docs/PLAN_ASISTENCIA_Y_SUELDOS.md, D4).
 */
class AttendanceSettings
{
    private const PREFIX = 'attendance.';
    private const DEFAULTS = ['require_entry_to_open_register' => false];

    public function get(string $key): mixed
    {
        $value = DB::table('app_settings')->where('key', self::PREFIX . $key)->value('value');

        return $value === null ? (self::DEFAULTS[$key] ?? null) : json_decode($value, true);
    }

    public function set(string $key, mixed $value): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::PREFIX . $key],
            ['value' => json_encode($value), 'updated_at' => now()]
        );
    }

    public function all(): array
    {
        $all = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $all[$key] = $this->get($key);
        }

        return $all;
    }
}
