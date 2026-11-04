<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDevice;
use App\Models\AttendanceMark;
use App\Models\AttendanceNetwork;
use App\Models\EmployeeProfile;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reloj de asistencia: quién marca, si toca entrada o salida, cómo queda cada marca y cómo se arman los turnos
 * (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.2–§5.5).
 *
 * Lo que ve el trabajador sale de aquí recortado: solo día, tipo y hora de sus propias marcas (§5.8).
 */
class AttendanceService
{
    private const DAYS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function __construct(
        private readonly LocalPresenceService $presence,
        private readonly FaceMatcher $faces,
    ) {}

    /** Personas que marcan asistencia, por nombre. */
    public function roster(): Collection
    {
        return User::query()
            ->whereHas('employeeProfile', fn ($q) => $q->where('attendance_enabled', true))
            ->with('employeeProfile')
            ->orderBy('name')
            ->get();
    }

    public function profileFor(User $user): ?EmployeeProfile
    {
        $profile = $user->employeeProfile;

        return $profile && $profile->attendance_enabled ? $profile : null;
    }

    public function lastLiveMark(User $user, ?CarbonInterface $before = null): ?AttendanceMark
    {
        return AttendanceMark::live()
            ->where('user_id', $user->id)
            ->when($before, fn ($q) => $q->where('marked_at', '<=', $before))
            ->orderByDesc('marked_at')
            ->orderByDesc('id')
            ->first();
    }

    /** «out» si la última marca es una entrada de hace menos de open_shift_hours; si no, «in». */
    public function nextType(User $user, ?CarbonInterface $at = null): string
    {
        $at = $at ? Carbon::instance($at) : now();
        $last = $this->lastLiveMark($user, $at);
        if ($last && $last->type === 'in' && $last->marked_at->diffInHours($at, true) < config('attendance.open_shift_hours')) {
            return 'out';
        }

        return 'in';
    }

    /**
     * Valida el PIN de la persona en este equipo. Después de pin_max_attempts fallos queda bloqueada pin_lock_minutes.
     */
    public function verifyPin(User $user, ?EmployeeProfile $profile, string $pin, string $deviceKey): void
    {
        $key = "attendance-pin:{$deviceKey}:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, config('attendance.pin_max_attempts'))) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw new HttpResponseException(response()->json([
                'message' => "Demasiados intentos con el PIN. Espera {$minutes} min y vuelve a intentar.",
                'reason'  => 'pin_locked',
            ], 429));
        }
        if (!$profile || !$profile->hasPin() || !Hash::check($pin, $profile->pin_hash)) {
            RateLimiter::hit($key, config('attendance.pin_lock_minutes') * 60);
            throw ValidationException::withMessages(['pin' => 'El PIN no es correcto.']);
        }
        RateLimiter::clear($key);
    }

    /**
     * Estado de una marca nueva. Prioridad: sin conexión → red nueva → rostro → válida.
     * Sin rostro registrado no se compara (la foto queda igual como respaldo).
     */
    public function statusFor(bool $offline, bool $networkOk, ?float $faceScore, ?bool $liveness): string
    {
        if ($offline) {
            return AttendanceMark::STATUS_OFFLINE_REVIEW;
        }
        if (!$networkOk) {
            return AttendanceMark::STATUS_PENDING_NETWORK;
        }

        return $this->faceStatus($faceScore, $liveness);
    }

    public function faceStatus(?float $faceScore, ?bool $liveness): string
    {
        if ($liveness === false) {
            return AttendanceMark::STATUS_FACE_REVIEW;
        }
        if ($faceScore !== null && $faceScore < config('attendance.face_threshold')) {
            return AttendanceMark::STATUS_FACE_REVIEW;
        }

        return AttendanceMark::STATUS_VALID;
    }

    /**
     * Registra una marca del reloj.
     *
     * @param array{type?: ?string, photo: string, descriptor?: ?array, liveness_ok?: ?bool, offline?: bool,
     *              device_time?: ?string, local_uuid?: ?string} $data
     * @return array{mark: AttendanceMark, duplicate: bool}
     */
    public function clock(User $user, array $data, ?AttendanceDevice $device, ?string $ip): array
    {
        if (!empty($data['local_uuid'])) {
            $existing = AttendanceMark::where('local_uuid', $data['local_uuid'])->first();
            if ($existing) {
                return ['mark' => $existing, 'duplicate' => true];
            }
        }

        $offline = (bool) ($data['offline'] ?? false);
        $now = now();
        $deviceTime = !empty($data['device_time'])
            ? Carbon::parse($data['device_time'])->setTimezone(config('app.timezone'))
            : null;

        if ($offline) {
            if (!$deviceTime) {
                throw ValidationException::withMessages(['device_time' => 'Una marca sin conexión necesita la hora del equipo.']);
            }
            if ($deviceTime->gt($now->copy()->addMinutes(5)) || $deviceTime->lt($now->copy()->subDays(config('attendance.offline_max_days')))) {
                throw ValidationException::withMessages(['device_time' => 'La hora de la marca sin conexión no es válida.']);
            }
        }
        $markedAt = $offline ? $deviceTime : $now;

        // En línea manda el servidor; sin conexión, el equipo decidió con lo que sabía y se respeta.
        $type = $offline && in_array($data['type'] ?? null, ['in', 'out'], true)
            ? $data['type']
            : $this->nextType($user, $markedAt);

        // Doble toque: la misma marca otra vez en pocos segundos devuelve la anterior.
        $last = $this->lastLiveMark($user, $markedAt->copy()->addSeconds(1));
        if (!$offline && $last && $last->type !== $type
            && $last->marked_at->diffInSeconds($markedAt, true) < config('attendance.double_tap_seconds')) {
            return ['mark' => $last, 'duplicate' => true];
        }

        $presence = $this->presence->evaluate($ip, $device);
        $hasEnrollment = $this->faces->hasEnrollment($user);
        $descriptor = $data['descriptor'] ?? null;
        $faceScore = null;
        if ($hasEnrollment) {
            // Con rostro registrado y sin descriptor (cámara o librería que no cargó), se revisa.
            $faceScore = is_array($descriptor) && count($descriptor) > 0 ? $this->faces->bestScore($user, $descriptor) : 0.0;
        }
        $liveness = array_key_exists('liveness_ok', $data) && $data['liveness_ok'] !== null ? (bool) $data['liveness_ok'] : null;

        $photoPath = $this->storePhoto($data['photo'], $user->id, $markedAt);

        $mark = AttendanceMark::create([
            'user_id'     => $user->id,
            'type'        => $type,
            'marked_at'   => $markedAt,
            'device_time' => $deviceTime,
            'received_at' => $now,
            'ip'          => $ip,
            'device_id'   => $device?->id,
            'network_ok'  => $presence['network_ok'],
            'device_ok'   => $presence['device_ok'],
            'face_score'  => $faceScore,
            'liveness_ok' => $liveness,
            'photo_path'  => $photoPath,
            'status'      => $this->statusFor($offline, $presence['network_ok'], $faceScore, $liveness),
            'source'      => $offline ? 'offline' : 'kiosk',
            'local_uuid'  => $data['local_uuid'] ?? null,
        ]);

        return ['mark' => $mark, 'duplicate' => false];
    }

    /** Guarda la foto (JPEG o PNG en data URL o base64) en storage/app/private/asistencia/AAAA/MM. */
    public function storePhoto(string $data, int $userId, CarbonInterface $at): string
    {
        $raw = preg_replace('#^data:image/(jpeg|jpg|png);base64,#i', '', trim($data));
        $bytes = base64_decode((string) $raw, true);
        if ($bytes === false || strlen($bytes) < 100) {
            throw ValidationException::withMessages(['photo' => 'La foto no llegó bien. Vuelve a intentar.']);
        }
        if (strlen($bytes) > config('attendance.photo_max_bytes')) {
            throw ValidationException::withMessages(['photo' => 'La foto es demasiado grande.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            default      => null,
        };
        if ($ext === null) {
            throw ValidationException::withMessages(['photo' => 'La foto tiene que ser JPEG o PNG.']);
        }
        $path = sprintf('%s/%s/%d-%s.%s', config('attendance.photo_dir'), $at->format('Y/m'), $userId, Str::uuid(), $ext);
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    /** «Hoy», «Ayer» o «lun 2 nov». */
    public function dayLabel(CarbonInterface $date): string
    {
        $today = now()->startOfDay();
        $day = Carbon::instance($date)->startOfDay();
        if ($day->equalTo($today)) {
            return 'Hoy';
        }
        if ($day->equalTo($today->copy()->subDay())) {
            return 'Ayer';
        }

        return self::DAYS[$day->dayOfWeek] . ' ' . $day->day . ' ' . self::MONTHS[$day->month - 1];
    }

    /**
     * Turnos de una persona: entradas y salidas emparejadas. Una entrada sin salida o una salida sin entrada
     * quedan solas. $statuses: qué marcas cuentan (para la planilla, solo las válidas).
     *
     * @return array<int, array{in: ?AttendanceMark, out: ?AttendanceMark, date: string}>
     */
    public function shifts(User $user, CarbonInterface $from, CarbonInterface $to, array $statuses): array
    {
        $window = config('attendance.open_shift_hours');
        $marks = AttendanceMark::where('user_id', $user->id)
            ->whereIn('status', $statuses)
            ->whereBetween('marked_at', [Carbon::instance($from)->subHours($window), Carbon::instance($to)->addHours($window)])
            ->orderBy('marked_at')
            ->orderBy('id')
            ->get();

        $shifts = [];
        $open = null;
        foreach ($marks as $mark) {
            if ($mark->type === 'in') {
                if ($open) {
                    $shifts[] = ['in' => $open, 'out' => null];
                }
                $open = $mark;
                continue;
            }
            if ($open && $open->marked_at->diffInHours($mark->marked_at, true) < $window) {
                $shifts[] = ['in' => $open, 'out' => $mark];
                $open = null;
            } else {
                if ($open) {
                    $shifts[] = ['in' => $open, 'out' => null];
                    $open = null;
                }
                $shifts[] = ['in' => null, 'out' => $mark];
            }
        }
        if ($open) {
            $shifts[] = ['in' => $open, 'out' => null];
        }

        $fromDay = Carbon::instance($from)->startOfDay();
        $toDay = Carbon::instance($to)->endOfDay();

        return array_values(array_filter(array_map(function ($shift) {
            $anchor = $shift['in'] ?? $shift['out'];
            $shift['date'] = $anchor->marked_at->toDateString();

            return $shift;
        }, $shifts), function ($shift) use ($fromDay, $toDay) {
            $anchor = ($shift['in'] ?? $shift['out'])->marked_at;

            return $anchor->betweenIncluded($fromDay, $toDay);
        }));
    }

    /**
     * «Mis marcas»: solo día, entrada y salida de la persona (§5.8). Nunca horas totales, montos ni estados.
     *
     * @return array<int, array{date: string, label: string, in: ?string, out: ?string}>
     */
    public function myMarks(User $user, ?int $days = null): array
    {
        $days ??= config('attendance.my_marks_days');
        $from = now()->startOfDay()->subDays($days - 1);
        $shifts = $this->shifts($user, $from, now(), AttendanceMark::LIVE);

        return collect($shifts)
            ->sortByDesc(fn ($s) => ($s['in'] ?? $s['out'])->marked_at->getTimestamp())
            ->map(fn ($s) => [
                'date'  => $s['date'],
                'label' => $this->dayLabel(Carbon::parse($s['date'])),
                'in'    => $s['in']?->marked_at->format('H:i'),
                'out'   => $s['out']?->marked_at->format('H:i'),
            ])
            ->values()
            ->all();
    }

    /** Entrada y salida de hoy de una persona (para la caja). Solo horas. */
    public function today(User $user): array
    {
        $shifts = $this->shifts($user, now()->startOfDay(), now(), AttendanceMark::LIVE);
        $last = $shifts ? $shifts[array_key_last($shifts)] : null;
        $in = $last['in'] ?? null;
        $out = $last['out'] ?? null;

        return [
            'enabled' => $this->profileFor($user) !== null,
            'entry'   => $in?->marked_at->format('H:i'),
            'exit'    => $out?->marked_at->format('H:i'),
            'open'    => $in !== null && $out === null,
        ];
    }

    /**
     * Entradas sin salida ya cerradas por el tiempo (más de open_shift_hours): el dueño pone la hora con motivo.
     *
     * @return array<int, array{mark: AttendanceMark, schedule_end: ?string}>
     */
    public function openShiftsWithoutExit(int $days = 45): array
    {
        $cutoff = now()->subHours(config('attendance.open_shift_hours'));
        $result = [];
        foreach ($this->roster() as $user) {
            foreach ($this->shifts($user, now()->subDays($days), $cutoff, AttendanceMark::LIVE) as $shift) {
                // Solo entradas válidas: una entrada por revisar ya está en la bandeja por sí misma
                if ($shift['in'] && !$shift['out'] && $shift['in']->status === AttendanceMark::STATUS_VALID
                    && $shift['in']->marked_at->lt($cutoff)) {
                    $schedule = $user->employeeProfile?->scheduleFor($shift['in']->marked_at->dayOfWeekIso);
                    $result[] = ['mark' => $shift['in'], 'schedule_end' => $schedule['end'] ?? null];
                }
            }
        }

        return $result;
    }

    /**
     * Después de confirmar una red nueva: las marcas pendientes por red que vinieron de ella se vuelven a evaluar
     * (quedan válidas, o «Rostro a revisar» si el rostro no coincidió).
     */
    public function reevaluateNetwork(AttendanceNetwork $network, int $reviewerId): int
    {
        $count = 0;
        AttendanceMark::where('status', AttendanceMark::STATUS_PENDING_NETWORK)->whereNotNull('ip')->get()
            ->each(function (AttendanceMark $mark) use ($network, $reviewerId, &$count) {
                if (!$this->presence->ipMatches($mark->ip, $network->cidr)) {
                    return;
                }
                $status = $this->faceStatus($mark->face_score, $mark->liveness_ok);
                $mark->update([
                    'network_ok'  => true,
                    'status'      => $status,
                    'reviewed_by' => $status === AttendanceMark::STATUS_VALID ? $reviewerId : null,
                    'reviewed_at' => $status === AttendanceMark::STATUS_VALID ? now() : null,
                    'review_note' => $status === AttendanceMark::STATUS_VALID ? 'Red del local confirmada.' : null,
                ]);
                $count++;
            });

        return $count;
    }

    /** Para la respuesta al trabajador: solo «registrada» o «guardada para revisar». */
    public function publicStatus(AttendanceMark $mark): string
    {
        return $mark->status === AttendanceMark::STATUS_VALID ? 'registered' : 'saved_for_review';
    }

    /** Borra las fotos de marcas más viejas que photo_retention_days. La marca queda. */
    public function purgeOldPhotos(): int
    {
        $limit = now()->subDays(config('attendance.photo_retention_days'));
        $count = 0;
        AttendanceMark::whereNotNull('photo_path')->where('marked_at', '<', $limit)->orderBy('id')
            ->chunkById(200, function ($marks) use (&$count) {
                foreach ($marks as $mark) {
                    Storage::disk('local')->delete($mark->photo_path);
                    DB::table('attendance_marks')->where('id', $mark->id)->update(['photo_path' => null]);
                    $count++;
                }
            });

        return $count;
    }
}
