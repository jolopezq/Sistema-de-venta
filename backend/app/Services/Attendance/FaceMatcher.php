<?php

namespace App\Services\Attendance;

use App\Models\FaceEnrollment;
use App\Models\User;

/**
 * Compara el descriptor que calculó el navegador con el rostro registrado. La comparación se hace en el servidor:
 * el rostro registrado nunca sale del servidor y el porcentaje no lo calcula el equipo.
 *
 * Misma fórmula que Human (src/face/match.ts, modelo faceres): distancia euclídea al cuadrado × 25, raíz, y
 * normalización al rango 0,2–0,8. Con esa escala, 0,5 ya se considera la misma persona.
 */
class FaceMatcher
{
    private const MULTIPLIER = 25;
    private const MIN = 0.2;
    private const MAX = 0.8;

    /** Similitud 0..1 con la captura registrada más parecida, o null si la persona no tiene rostro registrado. */
    public function bestScore(User $user, array $descriptor): ?float
    {
        $best = null;
        foreach (FaceEnrollment::active()->where('user_id', $user->id)->get() as $enrollment) {
            $score = $this->similarity($descriptor, (array) $enrollment->descriptor);
            if ($best === null || $score > $best) {
                $best = $score;
            }
        }

        return $best;
    }

    public function hasEnrollment(User $user): bool
    {
        return FaceEnrollment::active()->where('user_id', $user->id)->exists();
    }

    public function similarity(array $a, array $b): float
    {
        if (count($a) === 0 || count($a) !== count($b)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($a as $i => $value) {
            $diff = (float) $value - (float) $b[$i];
            $sum += $diff * $diff;
        }
        $dist = round(100 * self::MULTIPLIER * $sum) / 100;
        if ($dist == 0.0) {
            return 1.0;
        }
        $norm = (1 - (sqrt($dist) / 100) - self::MIN) / (self::MAX - self::MIN);

        return round(100 * max(min($norm, 1), 0)) / 100;
    }
}
