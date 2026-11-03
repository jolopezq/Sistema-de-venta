<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\FaceMatcher;
use App\Services\Attendance\LocalPresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Reloj de asistencia en el equipo autorizado. No usa la sesión de nadie: la persona se identifica con su PIN.
 * Las respuestas solo llevan lo que puede ver un trabajador: su nombre, si le toca entrada o salida y sus horas
 * (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.3 y §5.8).
 */
class AttendanceKioskController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly FaceMatcher $faces,
        private readonly LocalPresenceService $presence,
    ) {}

    /** Quiénes marcan: nombre, iniciales y si les toca entrada o salida. Nunca horas, tarifas ni montos. */
    public function roster(Request $request): JsonResponse
    {
        $presence = $this->presence->evaluate($request->ip(), $this->device($request));

        return response()->json([
            'device'     => ['name' => $this->device($request)->name],
            'network_ok' => $presence['network_ok'],
            'server_time' => now()->toIso8601String(),
            'people'     => $this->attendance->roster()->map(fn (User $user) => [
                'id'           => $user->id,
                'name'         => $user->name,
                'initials'     => $this->initials($user->name),
                'next'         => $this->attendance->nextType($user),
                'has_pin'      => (bool) $user->employeeProfile?->hasPin(),
                'face_enrolled' => $this->faces->hasEnrollment($user),
            ])->values(),
        ]);
    }

    /** Comprueba el PIN antes de abrir la cámara. */
    public function pin(Request $request): JsonResponse
    {
        $user = $this->person($request);
        $this->attendance->verifyPin($user, $user->employeeProfile, (string) $request->input('pin'), (string) $this->device($request)->id);

        return response()->json([
            'next'          => $this->attendance->nextType($user),
            'face_enrolled' => $this->faces->hasEnrollment($user),
        ]);
    }

    public function clock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'      => ['required', 'integer'],
            'pin'          => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'photo'        => ['required', 'string', 'max:1200000'],
            'descriptor'   => ['nullable', 'array', 'max:2048'],
            'descriptor.*' => ['numeric'],
            'liveness_ok'  => ['nullable', 'boolean'],
            'offline'      => ['nullable', 'boolean'],
            'type'         => ['nullable', 'in:in,out'],
            'device_time'  => ['nullable', 'date'],
            'local_uuid'   => ['nullable', 'uuid'],
        ], [
            'pin.regex'      => 'El PIN tiene de 4 a 6 dígitos.',
            'photo.required' => 'Falta la foto de la marca.',
        ]);

        $user = $this->person($request);
        $device = $this->device($request);
        $this->attendance->verifyPin($user, $user->employeeProfile, $data['pin'], (string) $device->id);

        ['mark' => $mark, 'duplicate' => $duplicate] = $this->attendance->clock($user, $data, $device, $request->ip());

        return response()->json([
            'type'      => $mark->type,
            'time'      => $mark->marked_at->format('H:i'),
            'date'      => $mark->marked_at->toDateString(),
            'status'    => $this->attendance->publicStatus($mark),
            'duplicate' => $duplicate,
            'name'      => $user->name,
        ], $duplicate ? 200 : 201);
    }

    /** «Mis marcas»: día, entrada y salida de quien puso su PIN. */
    public function myMarks(Request $request): JsonResponse
    {
        $user = $this->person($request);
        $this->attendance->verifyPin($user, $user->employeeProfile, (string) $request->input('pin'), (string) $this->device($request)->id);

        return response()->json([
            'name' => $user->name,
            'days' => $this->attendance->myMarks($user),
        ]);
    }

    private function device(Request $request): AttendanceDevice
    {
        return $request->attributes->get('attendance_device');
    }

    private function person(Request $request): User
    {
        $request->validate([
            'user_id' => ['required', 'integer'],
            'pin'     => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ], ['pin.regex' => 'El PIN tiene de 4 a 6 dígitos.', 'pin.required' => 'Escribe tu PIN.']);

        $user = User::with('employeeProfile')->find($request->integer('user_id'));
        if (!$user || !$this->attendance->profileFor($user)) {
            throw ValidationException::withMessages(['user_id' => 'Esta persona no marca asistencia.']);
        }

        return $user;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));

        return implode('', $letters) ?: '?';
    }
}
