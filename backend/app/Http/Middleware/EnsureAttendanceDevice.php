<?php

namespace App\Http\Middleware;

use App\Models\AttendanceDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El reloj de asistencia solo funciona en un equipo autorizado: la llave viaja en X-Attendance-Device y el servidor
 * compara su huella SHA-256 (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.2).
 */
class EnsureAttendanceDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->header('X-Attendance-Device', '');
        $device = strlen($token) >= 32
            ? AttendanceDevice::active()->where('token_hash', AttendanceDevice::hashToken($token))->first()
            : null;

        if (!$device) {
            return response()->json([
                'message' => 'Este equipo no está autorizado para marcar asistencia.',
                'reason'  => 'device_not_authorized',
            ], 403);
        }

        if (!$device->last_seen_at || $device->last_seen_at->lt(now()->subMinutes(5)) || $device->last_ip !== $request->ip()) {
            $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();
        }
        $request->attributes->set('attendance_device', $device);

        return $next($request);
    }
}
