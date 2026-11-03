<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\AttendanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que ve de sí misma la persona con sesión abierta (la caja): solo su entrada y su salida de hoy
 * (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.8).
 */
class AttendanceSelfController extends Controller
{
    public function today(Request $request, AttendanceService $attendance, AttendanceSettings $settings): JsonResponse
    {
        return response()->json($attendance->today($request->user()) + [
            'require_entry_to_open_register' => (bool) $settings->get('require_entry_to_open_register'),
        ]);
    }
}
