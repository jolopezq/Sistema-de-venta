<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\PayrollPeriod;
use App\Services\Attendance\PayrollService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asistencia → Sueldos (permiso «Asistencia y sueldos»): vista de la planilla, aprobar y anular
 * (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.6). Nada de esto lo ve un trabajador.
 */
class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function preview(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json($this->payroll->preview($from, $to));
    }

    public function periods(): JsonResponse
    {
        return response()->json([
            'data' => PayrollPeriod::with('approver:id,name')->orderByDesc('period_start')->limit(50)->get()
                ->map(fn ($p) => $this->payroll->periodRow($p)),
        ]);
    }

    public function show(PayrollPeriod $period): JsonResponse
    {
        $period->load(['approver:id,name', 'lines.user:id,name']);

        return response()->json($this->payroll->periodRow($period) + [
            'lines' => $period->lines->map(fn ($l) => [
                'user_id'          => $l->user_id,
                'name'             => $l->user?->name,
                'pay_mode'         => $l->pay_mode,
                'days_worked'      => $l->days_worked,
                'normal_minutes'   => $l->normal_minutes,
                'overtime_minutes' => $l->overtime_minutes,
                'late_minutes'     => $l->late_minutes,
                'absences'         => $l->absences,
                'gross'            => (float) $l->gross,
                'advances'         => (float) $l->advances,
                'others'           => (float) $l->others,
                'others_reason'    => $l->others_reason,
                'net'              => (float) $l->net,
                'rate'             => (float) ($l->snapshot['monthly_salary'] ?? $l->snapshot['shift_rate'] ?? $l->snapshot['hourly_rate'] ?? 0),
                'breakdown'        => $l->snapshot['breakdown'] ?? null,
                'days'             => $l->snapshot['days'] ?? [],
            ]),
        ]);
    }

    public function approve(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $data = $request->validate([
            'payment_method'   => ['required', 'in:cash,qr,bank_transfer'],
            'others'           => ['nullable', 'array'],
            'others.*.user_id' => ['required', 'integer'],
            'others.*.amount'  => ['required', 'numeric', 'min:-100000', 'max:100000'],
            'others.*.reason'  => ['nullable', 'string', 'max:255'],
        ]);
        $period = $this->payroll->approve($from, $to, $request->user(), $data['payment_method'], $data['others'] ?? []);

        return response()->json(['message' => 'Planilla aprobada.', 'period' => $this->payroll->periodRow($period)], 201);
    }

    public function void(Request $request, PayrollPeriod $period): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:255']], [
            'reason.required' => 'Escribe el motivo.',
            'reason.min'      => 'El motivo tiene que tener al menos 10 caracteres.',
        ]);

        return response()->json([
            'message' => 'Planilla anulada. El gasto de Finanzas se quitó.',
            'period'  => $this->payroll->periodRow($this->payroll->void($period, $request->user(), $data['reason'])),
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [Carbon::parse($data['from'])->startOfDay(), Carbon::parse($data['to'])->startOfDay()];
    }
}
