<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\EmployeeProfile;
use App\Models\FaceEnrollment;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Usuarios → editar persona → «Trabajo y asistencia» (solo Super Admin): cómo se le paga, horario, PIN y rostro
 * (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.1 y §5.4).
 */
class EmployeeProfileController extends Controller
{
    private const MODE_NAMES = ['hourly' => 'por hora', 'monthly' => 'sueldo mensual', 'per_shift' => 'por turno'];

    public function __construct(private readonly AttendanceService $attendance) {}

    public function show(User $user): JsonResponse
    {
        return response()->json($this->payload($user));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'attendance_enabled'   => ['required', 'boolean'],
            'pay_mode'             => ['required', Rule::in(EmployeeProfile::PAY_MODES)],
            'hourly_rate'          => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'monthly_salary'       => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'shift_rate'           => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'shift_hours'          => ['nullable', 'numeric', 'min:1', 'max:16'],
            'schedule'             => ['nullable', 'array'],
            'schedule.*.start'     => ['nullable', 'date_format:H:i'],
            'schedule.*.end'       => ['nullable', 'date_format:H:i'],
            'late_tolerance_min'   => ['nullable', 'integer', 'min:0', 'max:120'],
            'overtime_after_hours' => ['nullable', 'numeric', 'min:1', 'max:16'],
            'overtime_multiplier'  => ['nullable', 'numeric', 'min:1', 'max:4'],
            'sunday_multiplier'    => ['nullable', 'numeric', 'min:1', 'max:4'],
            'holiday_multiplier'   => ['nullable', 'numeric', 'min:1', 'max:4'],
        ], [
            'schedule.*.start.date_format' => 'La hora de inicio va como 14:00.',
            'schedule.*.end.date_format'   => 'La hora de fin va como 22:00.',
        ]);

        $required = ['hourly' => 'hourly_rate', 'monthly' => 'monthly_salary', 'per_shift' => 'shift_rate'][$data['pay_mode']];
        if ($data['attendance_enabled'] && !isset($data[$required])) {
            throw ValidationException::withMessages([$required => 'Falta el monto para «' . self::MODE_NAMES[$data['pay_mode']] . '».']);
        }

        $schedule = [];
        foreach (($data['schedule'] ?? []) as $day => $hours) {
            if ((int) $day >= 1 && (int) $day <= 7 && !empty($hours['start']) && !empty($hours['end'])) {
                $schedule[(string) (int) $day] = ['start' => $hours['start'], 'end' => $hours['end']];
            }
        }
        $data['schedule'] = $schedule ?: null;

        $profile = EmployeeProfile::firstOrNew(['user_id' => $user->id]);
        $before = $profile->exists ? $this->summary($profile) : 'sin perfil';
        $profile->fill(array_filter($data, fn ($v) => $v !== null) + ['schedule' => $data['schedule']]);
        $profile->user_id = $user->id;
        $profile->save();

        AuditLogger::log('employee_profile_changed', 'Attendance',
            "Perfil laboral de {$user->name} cambiado por {$request->user()->name}. Antes: {$before}. Ahora: {$this->summary($profile)}.",
            $request->user()->id);

        return response()->json($this->payload($user->fresh()) + ['message' => 'Perfil laboral guardado.']);
    }

    public function pin(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'string', 'regex:/^\d{4,6}$/']], ['pin.regex' => 'El PIN tiene de 4 a 6 dígitos.']);
        $profile = $this->profile($user);
        $profile->forceFill(['pin_hash' => Hash::make($data['pin'])])->save();
        AuditLogger::log('employee_pin_changed', 'Attendance', "PIN para marcar de {$user->name} cambiado por {$request->user()->name}.", $request->user()->id);

        return response()->json(['message' => 'PIN guardado.', 'has_pin' => true]);
    }

    /** Una captura del rostro. La primera exige el consentimiento firmado de la persona. */
    public function enrollFace(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'consent'      => ['nullable', 'boolean'],
            'descriptor'   => ['required', 'array', 'min:64', 'max:2048'],
            'descriptor.*' => ['numeric'],
            'photo'        => ['required', 'string', 'max:1200000'],
        ], ['descriptor.required' => 'No se pudo leer el rostro. Vuelve a tomar la foto.']);

        $profile = $this->profile($user);
        if (!$profile->face_consent_at) {
            if (!($data['consent'] ?? false)) {
                throw ValidationException::withMessages(['consent' => 'Primero marca que la persona firmó el consentimiento.']);
            }
            $profile->forceFill(['face_consent_at' => now(), 'face_consent_by' => $request->user()->id])->save();
            AuditLogger::log('employee_face_consent', 'Attendance', "Consentimiento del rostro de {$user->name} registrado por {$request->user()->name}.", $request->user()->id);
        }
        $active = FaceEnrollment::active()->where('user_id', $user->id)->count();
        if ($active >= config('attendance.max_enrollments')) {
            throw ValidationException::withMessages(['descriptor' => 'Ya tiene ' . config('attendance.max_enrollments') . ' fotos. Borra el rostro para registrarlo de nuevo.']);
        }

        $path = $this->attendance->storePhoto($data['photo'], $user->id, now());
        $enrollment = FaceEnrollment::create([
            'user_id'    => $user->id,
            'descriptor' => array_map('floatval', $data['descriptor']),
            'photo_path' => $this->moveToFaces($path),
            'created_by' => $request->user()->id,
        ]);
        if ($active === 0) {
            AuditLogger::log('employee_face_enrolled', 'Attendance', "Rostro de {$user->name} registrado por {$request->user()->name}.", $request->user()->id);
        }

        return response()->json(['message' => 'Foto guardada.', 'face_count' => $active + 1, 'enrollment_id' => $enrollment->id], 201);
    }

    /** Borra el rostro registrado (las capturas y sus fotos). Las marcas quedan. */
    public function clearFace(Request $request, User $user): JsonResponse
    {
        $enrollments = FaceEnrollment::active()->where('user_id', $user->id)->get();
        foreach ($enrollments as $enrollment) {
            if ($enrollment->photo_path) {
                Storage::disk('local')->delete($enrollment->photo_path);
            }
            $enrollment->forceFill(['revoked_at' => now(), 'photo_path' => null, 'descriptor' => []])->save();
        }
        if ($enrollments->isNotEmpty()) {
            AuditLogger::log('employee_face_deleted', 'Attendance', "Rostro de {$user->name} borrado por {$request->user()->name}.", $request->user()->id);
        }

        return response()->json(['message' => 'Rostro borrado.', 'face_count' => 0]);
    }

    private function moveToFaces(string $path): string
    {
        $target = config('attendance.photo_dir') . '/rostros/' . basename($path);
        Storage::disk('local')->move($path, $target);

        return $target;
    }

    private function profile(User $user): EmployeeProfile
    {
        return EmployeeProfile::firstOrCreate(['user_id' => $user->id], ['attendance_enabled' => false]);
    }

    private function summary(EmployeeProfile $p): string
    {
        $amount = match ($p->pay_mode) {
            'monthly'   => 'Bs ' . $p->monthly_salary . ' al mes',
            'per_shift' => 'Bs ' . $p->shift_rate . ' por turno de ' . $p->shift_hours . ' h',
            default     => 'Bs ' . $p->hourly_rate . ' por hora',
        };

        return ($p->attendance_enabled ? 'marca asistencia' : 'no marca') . ', ' . (self::MODE_NAMES[$p->pay_mode] ?? $p->pay_mode) . " ({$amount})";
    }

    private function payload(User $user): array
    {
        $profile = $user->employeeProfile;

        return [
            'user_id'    => $user->id,
            'profile'    => $profile ? [
                'attendance_enabled'   => $profile->attendance_enabled,
                'pay_mode'             => $profile->pay_mode,
                'hourly_rate'          => $profile->hourly_rate,
                'monthly_salary'       => $profile->monthly_salary,
                'shift_rate'           => $profile->shift_rate,
                'shift_hours'          => $profile->shift_hours,
                'schedule'             => $profile->schedule ?? (object) [],
                'late_tolerance_min'   => $profile->late_tolerance_min,
                'overtime_after_hours' => $profile->overtime_after_hours,
                'overtime_multiplier'  => $profile->overtime_multiplier,
                'sunday_multiplier'    => $profile->sunday_multiplier,
                'holiday_multiplier'   => $profile->holiday_multiplier,
            ] : null,
            'has_pin'    => (bool) $profile?->hasPin(),
            'face'       => [
                'count'      => FaceEnrollment::active()->where('user_id', $user->id)->count(),
                'max'        => config('attendance.max_enrollments'),
                'consent_at' => $profile?->face_consent_at?->format('Y-m-d H:i'),
                'consent_by' => $profile?->consentBy?->name,
            ],
            'minimum_wage' => config('attendance.minimum_wage'),
        ];
    }
}
