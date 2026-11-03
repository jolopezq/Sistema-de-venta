<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceMark;
use App\Models\AttendanceNetwork;
use App\Models\FaceEnrollment;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\LocalPresenceService;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asistencia para administradores (permiso «Asistencia y sueldos»): hoy, la bandeja «A revisar», todas las marcas,
 * fotos y correcciones con motivo (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.5).
 */
class AttendanceAdminController extends Controller
{
    private const TYPE_NAMES = ['in' => 'Entrada', 'out' => 'Salida'];

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly LocalPresenceService $presence,
    ) {}

    /** Hoy: cada persona con sus marcas del día. */
    public function today(): JsonResponse
    {
        $people = $this->attendance->roster()->map(function (User $user) {
            $marks = AttendanceMark::where('user_id', $user->id)
                ->whereIn('status', AttendanceMark::LIVE)
                ->where('marked_at', '>=', now()->startOfDay())
                ->orderBy('marked_at')->get();
            $schedule = $user->employeeProfile?->scheduleFor(now()->dayOfWeekIso);

            return [
                'id'       => $user->id,
                'name'     => $user->name,
                'role'     => $user->role,
                'schedule' => $schedule,
                'open'     => $this->attendance->nextType($user) === 'out',
                'marks'    => $marks->map(fn ($m) => $this->markRow($m, false))->values(),
            ];
        });

        return response()->json(['date' => now()->toDateString(), 'people' => $people->values()]);
    }

    /** Bandeja «A revisar»: marcas pendientes y entradas sin salida. */
    public function review(): JsonResponse
    {
        $pending = AttendanceMark::with('user:id,name')
            ->whereIn('status', AttendanceMark::PENDING)
            ->orderBy('marked_at')
            ->get()
            ->map(fn ($m) => $this->markRow($m, true));

        $withoutExit = collect($this->attendance->openShiftsWithoutExit())->map(fn ($row) => [
            'mark'         => $this->markRow($row['mark']->loadMissing('user:id,name'), true),
            'schedule_end' => $row['schedule_end'],
        ]);

        return response()->json([
            'pending'      => $pending->values(),
            'without_exit' => $withoutExit->values(),
            'count'        => $pending->count() + $withoutExit->count(),
        ]);
    }

    /** Todas las marcas de un período (por persona y estado). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from'    => ['nullable', 'date_format:Y-m-d'],
            'to'      => ['nullable', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer'],
            'status'  => ['nullable', 'string'],
        ]);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now()->toDateString())->endOfDay();

        $marks = AttendanceMark::with(['user:id,name', 'reviewer:id,name', 'creator:id,name'])
            ->whereBetween('marked_at', [$from, $to])
            ->when($data['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->orderByDesc('marked_at')
            ->limit(1000)
            ->get();

        return response()->json(['data' => $marks->map(fn ($m) => $this->markRow($m, true))->values()]);
    }

    public function photo(AttendanceMark $mark): Response
    {
        if (!$mark->photo_path || !Storage::disk('local')->exists($mark->photo_path)) {
            abort(404, 'La foto ya no está (se borran a los 90 días).');
        }

        return Storage::disk('local')->response($mark->photo_path, null, ['Cache-Control' => 'private, max-age=600']);
    }

    /** Foto de referencia del rostro registrado, para compararla en la bandeja. */
    public function facePhoto(User $user): Response
    {
        $enrollment = FaceEnrollment::active()->where('user_id', $user->id)->whereNotNull('photo_path')->orderBy('id')->first();
        if (!$enrollment || !Storage::disk('local')->exists($enrollment->photo_path)) {
            abort(404, 'Esta persona no tiene rostro registrado.');
        }

        return Storage::disk('local')->response($enrollment->photo_path, null, ['Cache-Control' => 'private, max-age=600']);
    }

    /** Aprobar una marca pendiente. Con add_network, su red queda como red del local y se revisan las demás. */
    public function approve(Request $request, AttendanceMark $mark): JsonResponse
    {
        $data = $request->validate([
            'add_network' => ['nullable', 'boolean'],
            'note'        => ['nullable', 'string', 'max:255'],
        ]);
        if (!$mark->isPending()) {
            throw ValidationException::withMessages(['mark' => 'Esta marca ya no está pendiente.']);
        }

        $revalidated = 0;
        DB::transaction(function () use ($request, $mark, $data, &$revalidated) {
            if (($data['add_network'] ?? false) && $mark->ip) {
                $cidr = $this->presence->networkFor($mark->ip);
                $network = AttendanceNetwork::active()->where('cidr', $cidr)->first()
                    ?? AttendanceNetwork::create([
                        'label'                  => 'Red del local (confirmada el ' . now()->format('d/m/Y') . ')',
                        'cidr'                   => $cidr,
                        'added_by'               => $request->user()->id,
                        'confirmed_from_mark_id' => $mark->id,
                    ]);
                AuditLogger::log('attendance_network_added', 'Attendance',
                    "Red {$cidr} agregada como red del local por {$request->user()->name} desde la marca #{$mark->id}.", $request->user()->id);
                $revalidated = $this->attendance->reevaluateNetwork($network, $request->user()->id);
                $mark->refresh();
            }
            if ($mark->isPending()) {
                $mark->update([
                    'status'      => AttendanceMark::STATUS_VALID,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                    'review_note' => $data['note'] ?? null,
                ]);
            }
        });

        AuditLogger::log('attendance_mark_approved', 'Attendance', sprintf(
            '%s de %s del %s aprobada por %s.',
            self::TYPE_NAMES[$mark->type], $mark->user->name, $mark->marked_at->format('d/m/Y H:i'), $request->user()->name
        ), $request->user()->id);

        return response()->json(['message' => 'Marca aprobada.', 'mark' => $this->markRow($mark->fresh(['user:id,name']), true), 'revalidated' => $revalidated]);
    }

    public function reject(Request $request, AttendanceMark $mark): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:255']], [
            'reason.required' => 'Escribe el motivo.',
            'reason.min'      => 'El motivo tiene que tener al menos 10 caracteres.',
        ]);
        if (!$mark->isPending()) {
            throw ValidationException::withMessages(['mark' => 'Esta marca ya no está pendiente.']);
        }
        $mark->update([
            'status'      => AttendanceMark::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => trim($data['reason']),
        ]);
        AuditLogger::log('attendance_mark_rejected', 'Attendance', sprintf(
            '%s de %s del %s rechazada por %s. Motivo: %s',
            self::TYPE_NAMES[$mark->type], $mark->user->name, $mark->marked_at->format('d/m/Y H:i'), $request->user()->name, trim($data['reason'])
        ), $request->user()->id);

        return response()->json(['message' => 'Marca rechazada.', 'mark' => $this->markRow($mark->fresh(['user:id,name']), true)]);
    }

    /**
     * Agregar o corregir una marca a mano, con motivo. Si corrige otra, la original queda como «corregida» y se
     * conserva (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.5).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'      => ['required', 'integer', 'exists:users,id'],
            'type'         => ['required', 'in:in,out'],
            'marked_at'    => ['required', 'date'],
            'reason'       => ['required', 'string', 'min:10', 'max:255'],
            'corrects_id'  => ['nullable', 'integer', 'exists:attendance_marks,id'],
        ], [
            'reason.required' => 'Escribe el motivo.',
            'reason.min'      => 'El motivo tiene que tener al menos 10 caracteres.',
        ]);
        $user = User::findOrFail($data['user_id']);
        $markedAt = Carbon::parse($data['marked_at'], config('app.timezone'));
        if ($markedAt->gt(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['marked_at' => 'No se puede marcar una hora que todavía no pasó.']);
        }
        $original = !empty($data['corrects_id']) ? AttendanceMark::findOrFail($data['corrects_id']) : null;
        if ($original && ($original->user_id !== $user->id || !in_array($original->status, AttendanceMark::LIVE, true))) {
            throw ValidationException::withMessages(['corrects_id' => 'Esa marca no se puede corregir.']);
        }

        $mark = DB::transaction(function () use ($request, $data, $user, $markedAt, $original) {
            if ($original) {
                $original->update(['status' => AttendanceMark::STATUS_CORRECTED, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            }

            return AttendanceMark::create([
                'user_id'           => $user->id,
                'type'              => $data['type'],
                'marked_at'         => $markedAt,
                'received_at'       => now(),
                'ip'                => $request->ip(),
                'network_ok'        => false,
                'device_ok'         => false,
                'status'            => AttendanceMark::STATUS_VALID,
                'source'            => 'manual',
                'reason'            => trim($data['reason']),
                'corrected_from_id' => $original?->id,
                'created_by'        => $request->user()->id,
                'reviewed_by'       => $request->user()->id,
                'reviewed_at'       => now(),
            ]);
        });

        AuditLogger::log($original ? 'attendance_mark_corrected' : 'attendance_mark_added', 'Attendance', sprintf(
            '%s de %s del %s %s por %s.%s Motivo: %s',
            self::TYPE_NAMES[$mark->type], $user->name, $markedAt->format('d/m/Y H:i'),
            $original ? 'corregida' : 'agregada a mano', $request->user()->name,
            $original ? ' Antes: ' . self::TYPE_NAMES[$original->type] . ' ' . $original->marked_at->format('d/m/Y H:i') . '.' : '',
            trim($data['reason'])
        ), $request->user()->id);

        return response()->json(['message' => 'Marca guardada.', 'mark' => $this->markRow($mark->load('user:id,name'), true)], 201);
    }

    /** Fila de una marca para administradores (incluye red, rostro y foto). */
    private function markRow(AttendanceMark $m, bool $withUser): array
    {
        $row = [
            'id'          => $m->id,
            'user_id'     => $m->user_id,
            'type'        => $m->type,
            'marked_at'   => $m->marked_at->format('Y-m-d H:i:s'),
            'date'        => $m->marked_at->toDateString(),
            'time'        => $m->marked_at->format('H:i'),
            'day_label'   => $this->attendance->dayLabel($m->marked_at),
            'device_time' => $m->device_time?->format('Y-m-d H:i:s'),
            'received_at' => $m->received_at?->format('Y-m-d H:i:s'),
            'status'      => $m->status,
            'source'      => $m->source,
            'network_ok'  => $m->network_ok,
            'device_ok'   => $m->device_ok,
            'ip'          => $m->ip,
            'face_score'  => $m->face_score,
            'liveness_ok' => $m->liveness_ok,
            'has_photo'   => (bool) $m->photo_path,
            'reason'      => $m->reason,
            'review_note' => $m->review_note,
            'corrected_from_id' => $m->corrected_from_id,
        ];
        if ($withUser) {
            $row['user_name'] = $m->user?->name;
            $row['reviewer_name'] = $m->relationLoaded('reviewer') ? $m->reviewer?->name : null;
            $row['creator_name'] = $m->relationLoaded('creator') ? $m->creator?->name : null;
        }

        return $row;
    }
}
