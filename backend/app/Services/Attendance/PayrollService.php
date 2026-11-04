<?php

namespace App\Services\Attendance;

use App\Models\AttendanceMark;
use App\Models\CashMovement;
use App\Models\EmployeeProfile;
use App\Models\ExpenseCategory;
use App\Models\FinanceExpense;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Planilla de sueldos (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §3.5 y §5.6). Solo cuentan las marcas válidas.
 *
 * - Por hora: horas normales × tarifa + horas extra × tarifa × recargo.
 * - Sueldo mensual: sueldo (proporcional si el período no es un mes entero) − atrasos − faltas + horas extra.
 *   Valor de la hora = sueldo ÷ 240 (30 días × 8 h); una falta = sueldo ÷ 30.
 * - Por turno: turnos completos × monto + horas extra al valor de la hora del turno × recargo.
 * Horas extra: lo que pasa de overtime_after_hours por día. Domingo: recargo propio sobre las horas de ese día.
 * Atraso: pasada la tolerancia se cuenta todo el atraso desde la hora de entrada del horario.
 * Falta: un día con horario sin ninguna marca válida (desde que la persona marca asistencia y hasta ayer).
 * Adelantos: egresos de caja con esa persona; se restan del neto y el gasto de Finanzas no los cuenta dos veces.
 * No calcula aportes, RC-IVA, aguinaldo ni finiquito.
 */
class PayrollService
{
    private const MONTHLY_HOURS = 240;
    private const DAYS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

    public function __construct(private readonly AttendanceService $attendance) {}

    /** Quienes entran: con perfil laboral activo, o con marcas válidas en el período aunque ya no marquen. */
    public function people(Carbon $from, Carbon $to): Collection
    {
        $withMarks = AttendanceMark::where('status', AttendanceMark::STATUS_VALID)
            ->whereBetween('marked_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->distinct()->pluck('user_id');

        return User::withTrashed()
            ->with('employeeProfile')
            ->whereHas('employeeProfile', fn ($q) => $q->where('attendance_enabled', true)->orWhereIn('user_id', $withMarks))
            ->orderBy('name')
            ->get();
    }

    /**
     * Cálculo de una persona en el período.
     *
     * @return array<string, mixed>
     */
    public function line(User $user, Carbon $from, Carbon $to): array
    {
        /** @var EmployeeProfile $p */
        $p = $user->employeeProfile;
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $shifts = $this->attendance->shifts($user, $from, $to, [AttendanceMark::STATUS_VALID]);

        $days = [];
        foreach ($shifts as $s) {
            $date = $s['date'];
            $days[$date] ??= ['minutes' => 0, 'first_in' => null, 'complete' => 0, 'open' => false, 'ranges' => []];
            if ($s['in'] && $s['out']) {
                $minutes = (int) floor($s['in']->marked_at->diffInMinutes($s['out']->marked_at, true));
                $days[$date]['minutes'] += $minutes;
                $days[$date]['complete']++;
                $days[$date]['ranges'][] = $s['in']->marked_at->format('H:i') . ' → ' . $s['out']->marked_at->format('H:i');
            } elseif ($s['in']) {
                $days[$date]['open'] = true;
                $days[$date]['ranges'][] = $s['in']->marked_at->format('H:i') . ' → sin salida';
            } else {
                $days[$date]['ranges'][] = 'sin entrada → ' . $s['out']->marked_at->format('H:i');
            }
            if ($s['in'] && (!$days[$date]['first_in'] || $s['in']->marked_at->lt($days[$date]['first_in']))) {
                $days[$date]['first_in'] = $s['in']->marked_at;
            }
        }
        ksort($days);

        $otAfter = (float) $p->overtime_after_hours * 60;
        $tolerance = (int) $p->late_tolerance_min;
        $normal = $overtime = $late = $sundayMinutes = $daysWorked = $completeShifts = 0;
        $detail = [];
        foreach ($days as $date => $d) {
            $day = Carbon::parse($date);
            $ot = (int) max(0, $d['minutes'] - $otAfter);
            $normal += $d['minutes'] - $ot;
            $overtime += $ot;
            $completeShifts += $d['complete'];
            if ($d['minutes'] > 0) {
                $daysWorked++;
            }
            if ($day->isSunday()) {
                $sundayMinutes += $d['minutes'];
            }
            $lateMin = 0;
            $schedule = $p->scheduleFor($day->dayOfWeekIso);
            if ($schedule && $d['first_in']) {
                $start = Carbon::parse("{$date} {$schedule['start']}", config('app.timezone'));
                $diff = (int) floor($start->diffInMinutes($d['first_in'], false));
                if ($diff > $tolerance) {
                    $lateMin = $diff;
                    $late += $diff;
                }
            }
            $notes = [];
            if ($d['open']) {
                $notes[] = 'Sin salida: 0 h hasta que se ponga la hora';
            }
            if ($lateMin) {
                $notes[] = "Atraso {$lateMin} min";
            }
            if ($ot) {
                $notes[] = $this->hm($ot) . ' extra';
            }
            $detail[] = [
                'date'    => $date,
                'label'   => self::DAYS[$day->dayOfWeek] . ' ' . $day->day,
                'ranges'  => $d['ranges'],
                'minutes' => $d['minutes'],
                'notes'   => $notes,
            ];
        }

        // Faltas: días con horario sin ninguna marca válida, desde que la persona marca y hasta ayer
        $absences = [];
        $start = $from->copy()->max($p->created_at?->copy()->startOfDay() ?? $from);
        if ($user->start_date) {
            $start = $start->max(Carbon::parse($user->start_date)->startOfDay());
        }
        $end = $to->copy()->min(now()->subDay()->endOfDay());
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if ($p->scheduleFor($day->dayOfWeekIso) && !isset($days[$day->toDateString()])) {
                $absences[] = $day->toDateString();
                $detail[] = ['date' => $day->toDateString(), 'label' => self::DAYS[$day->dayOfWeek] . ' ' . $day->day, 'ranges' => [], 'minutes' => 0, 'notes' => ['Falta (tenía horario)']];
            }
        }
        usort($detail, fn ($a, $b) => strcmp($a['date'], $b['date']));

        $mode = $p->pay_mode;
        $hourValue = match ($mode) {
            'monthly'   => (float) $p->monthly_salary / self::MONTHLY_HOURS,
            'per_shift' => (float) $p->shift_hours > 0 ? (float) $p->shift_rate / (float) $p->shift_hours : 0.0,
            default     => (float) $p->hourly_rate,
        };
        $otMultiplier = (float) $p->overtime_multiplier;
        $sundayExtra = max(0, (float) $p->sunday_multiplier - 1);

        $base = $lateDeduction = $absenceDeduction = 0.0;
        $periodDays = (int) $from->diffInDays($to->copy()->startOfDay()) + 1;
        $fullMonth = $from->day === 1 && $to->isSameDay($from->copy()->endOfMonth());
        switch ($mode) {
            case 'monthly':
                $salary = (float) $p->monthly_salary;
                $base = $fullMonth ? $salary : $salary / 30 * min($periodDays, 30);
                $lateDeduction = $late / 60 * $hourValue;
                $absenceDeduction = count($absences) * $salary / 30;
                break;
            case 'per_shift':
                $base = $completeShifts * (float) $p->shift_rate;
                break;
            default:
                $base = $normal / 60 * $hourValue;
        }
        $overtimePay = $overtime / 60 * $hourValue * $otMultiplier;
        $sundayPay = $sundayMinutes / 60 * $hourValue * $sundayExtra;
        $gross = round(max(0, $base + $overtimePay + $sundayPay - $lateDeduction - $absenceDeduction), 2);

        $advances = round((float) CashMovement::where('employee_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('occurred_at', [$from, $to])
            ->sum('amount'), 2);

        return [
            'user_id'          => $user->id,
            'name'             => $user->name,
            'pay_mode'         => $mode,
            'rate'             => match ($mode) {
                'monthly'   => (float) $p->monthly_salary,
                'per_shift' => (float) $p->shift_rate,
                default     => (float) $p->hourly_rate,
            },
            'days_worked'      => $daysWorked,
            'complete_shifts'  => $completeShifts,
            'normal_minutes'   => (int) $normal,
            'overtime_minutes' => (int) $overtime,
            'sunday_minutes'   => (int) $sundayMinutes,
            'late_minutes'     => (int) $late,
            'absences'         => count($absences),
            'breakdown'        => [
                'base'              => round($base, 2),
                'overtime'          => round($overtimePay, 2),
                'sunday'            => round($sundayPay, 2),
                'late_deduction'    => round($lateDeduction, 2),
                'absence_deduction' => round($absenceDeduction, 2),
                'hour_value'        => round($hourValue, 2),
            ],
            'gross'            => $gross,
            'advances'         => $advances,
            'net'              => round($gross - $advances, 2),
            'snapshot'         => [
                'pay_mode'             => $mode,
                'hourly_rate'          => $p->hourly_rate,
                'monthly_salary'       => $p->monthly_salary,
                'shift_rate'           => $p->shift_rate,
                'shift_hours'          => $p->shift_hours,
                'late_tolerance_min'   => $p->late_tolerance_min,
                'overtime_after_hours' => $p->overtime_after_hours,
                'overtime_multiplier'  => $p->overtime_multiplier,
                'sunday_multiplier'    => $p->sunday_multiplier,
                'schedule'             => $p->schedule,
            ],
            'days'             => $detail,
        ];
    }

    /** Lo que impide aprobar: marcas por revisar y entradas sin salida del período. */
    public function blockers(Carbon $from, Carbon $to): array
    {
        $pending = AttendanceMark::whereIn('status', AttendanceMark::PENDING)
            ->whereBetween('marked_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();
        $withoutExit = collect($this->attendance->openShiftsWithoutExit((int) max(45, $from->diffInDays(now()) + 2)))
            ->filter(fn ($row) => $row['mark']->marked_at->betweenIncluded($from->copy()->startOfDay(), $to->copy()->endOfDay()))
            ->count();

        return ['pending' => $pending, 'without_exit' => $withoutExit, 'total' => $pending + $withoutExit];
    }

    public function overlapping(Carbon $from, Carbon $to): ?PayrollPeriod
    {
        return PayrollPeriod::where('status', 'approved')
            ->whereDate('period_start', '<=', $to->toDateString())
            ->whereDate('period_end', '>=', $from->toDateString())
            ->first();
    }

    public function preview(Carbon $from, Carbon $to): array
    {
        $lines = $this->people($from, $to)
            ->filter(fn (User $u) => $u->employeeProfile !== null)
            ->map(fn (User $u) => $this->line($u, $from, $to))
            ->values();
        $overlap = $this->overlapping($from, $to);

        $warnings = [];
        $recurring = RecurringExpense::active()->where('expense_category_id', $this->category()?->id)->get();
        if ($recurring->isNotEmpty()) {
            $warnings[] = [
                'code'    => 'recurring_salary',
                'message' => 'Hay gastos fijos de sueldos (' . $recurring->pluck('name')->join(', ') . ') que también se generan cada mes. '
                    . 'Si esta planilla los reemplaza, páusalos en Finanzas → Gastos fijos para no contar el sueldo dos veces.',
            ];
        }
        $minimumHour = (float) config('attendance.minimum_wage') / self::MONTHLY_HOURS;
        foreach ($lines as $line) {
            if ($line['breakdown']['hour_value'] > 0 && $line['breakdown']['hour_value'] + 1e-9 < round($minimumHour, 2)) {
                $warnings[] = [
                    'code'    => 'below_minimum',
                    'message' => "La tarifa de {$line['name']} queda por debajo del salario mínimo (Bs " . number_format($minimumHour, 2, ',', '.') . ' por hora).',
                ];
            }
        }

        return [
            'from'     => $from->toDateString(),
            'to'       => $to->toDateString(),
            'lines'    => $lines,
            'totals'   => [
                'gross'    => round($lines->sum('gross'), 2),
                'advances' => round($lines->sum('advances'), 2),
                'net'      => round($lines->sum('net'), 2),
            ],
            'blockers' => $this->blockers($from, $to),
            'approved' => $overlap ? $this->periodRow($overlap) : null,
            'warnings' => $warnings,
        ];
    }

    /**
     * Aprueba la planilla: guarda las líneas con sus tarifas y crea un gasto en Finanzas («Salarios y personal») por
     * bruto − adelantos + otros. Los adelantos ya están en Finanzas como egresos de caja.
     *
     * @param array<int, array{user_id: int, amount: float, reason: ?string}> $others
     */
    public function approve(Carbon $from, Carbon $to, User $approver, string $paymentMethod, array $others = []): PayrollPeriod
    {
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => 'La fecha final es anterior a la inicial.']);
        }
        if ($to->copy()->startOfDay()->gt(now()->startOfDay())) {
            throw ValidationException::withMessages(['to' => 'Solo se aprueba un período que ya terminó (hasta hoy).']);
        }
        if ($this->overlapping($from, $to)) {
            throw ValidationException::withMessages(['from' => 'Ya hay una planilla aprobada que cubre esas fechas. Anúlala primero si hay que rehacerla.']);
        }
        $blockers = $this->blockers($from, $to);
        if ($blockers['total'] > 0) {
            throw ValidationException::withMessages(['blockers' => "Quedan {$blockers['total']} marcas por revisar en el período. Revísalas en Asistencia → A revisar."]);
        }

        $preview = $this->preview($from, $to);
        $othersByUser = collect($others)->keyBy('user_id');
        foreach ($othersByUser as $userId => $o) {
            if (abs((float) ($o['amount'] ?? 0)) > 0.004 && mb_strlen(trim((string) ($o['reason'] ?? ''))) < 5) {
                throw ValidationException::withMessages(['others' => 'Cada bono o descuento necesita un motivo.']);
            }
        }

        return DB::transaction(function () use ($from, $to, $approver, $paymentMethod, $preview, $othersByUser) {
            $period = PayrollPeriod::create([
                'period_start'   => $from->toDateString(),
                'period_end'     => $to->toDateString(),
                'status'         => 'approved',
                'payment_method' => $paymentMethod,
                'approved_by'    => $approver->id,
                'approved_at'    => now(),
            ]);

            $gross = $advances = $othersTotal = $net = 0.0;
            foreach ($preview['lines'] as $line) {
                $other = round((float) ($othersByUser[$line['user_id']]['amount'] ?? 0), 2);
                $lineNet = round($line['gross'] - $line['advances'] + $other, 2);
                PayrollLine::create([
                    'payroll_period_id' => $period->id,
                    'user_id'           => $line['user_id'],
                    'pay_mode'          => $line['pay_mode'],
                    'days_worked'       => $line['days_worked'],
                    'normal_minutes'    => $line['normal_minutes'],
                    'overtime_minutes'  => $line['overtime_minutes'],
                    'late_minutes'      => $line['late_minutes'],
                    'absences'          => $line['absences'],
                    'gross'             => $line['gross'],
                    'advances'          => $line['advances'],
                    'others'            => $other,
                    'others_reason'     => $other ? trim((string) $othersByUser[$line['user_id']]['reason']) : null,
                    'net'               => $lineNet,
                    'snapshot'          => $line['snapshot'] + ['breakdown' => $line['breakdown'], 'days' => $line['days']],
                ]);
                $gross += $line['gross'];
                $advances += $line['advances'];
                $othersTotal += $other;
                $net += $lineNet;
            }

            $expenseAmount = round($gross - $advances + $othersTotal, 2);
            $expense = null;
            $category = $this->category();
            if ($expenseAmount > 0 && $category) {
                $expense = FinanceExpense::create([
                    'date'                => $to->toDateString(),
                    'expense_category_id' => $category->id,
                    'supplier'            => 'Planilla de sueldos',
                    'amount'              => $expenseAmount,
                    'payment_method'      => $paymentMethod,
                    'notes'               => sprintf(
                        'Planilla del %s al %s (#%d): bruto Bs %s, menos adelantos de caja Bs %s (ya están en Finanzas como egresos de caja)%s.',
                        $from->format('d/m/Y'), $to->format('d/m/Y'), $period->id, $this->money($gross), $this->money($advances),
                        abs($othersTotal) > 0.004 ? ', más bonos y descuentos Bs ' . $this->money($othersTotal) : ''
                    ),
                    'user_id'             => $approver->id,
                ]);
            }

            $period->update([
                'gross_total'        => round($gross, 2),
                'advances_total'     => round($advances, 2),
                'others_total'       => round($othersTotal, 2),
                'net_total'          => round($net, 2),
                'finance_expense_id' => $expense?->id,
            ]);

            AuditLogger::log('payroll_approved', 'Attendance', sprintf(
                'Planilla del %s al %s aprobada por %s: %d personas, bruto Bs %s, adelantos Bs %s, neto Bs %s.%s',
                $from->format('d/m/Y'), $to->format('d/m/Y'), $approver->name, count($preview['lines']),
                $this->money($gross), $this->money($advances), $this->money($net),
                $expense ? " Gasto #{$expense->id} en Finanzas por Bs " . $this->money($expenseAmount) . '.' : ''
            ), $approver->id);

            return $period->fresh(['lines.user']);
        });
    }

    /** Anula una planilla aprobada: el gasto de Finanzas se borra (queda en la papelera) y se puede rehacer. */
    public function void(PayrollPeriod $period, User $by, string $reason): PayrollPeriod
    {
        if ($period->status !== 'approved') {
            throw ValidationException::withMessages(['period' => 'Esta planilla ya está anulada.']);
        }
        DB::transaction(function () use ($period, $by, $reason) {
            $period->expense?->delete();
            $period->update(['status' => 'voided', 'voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => trim($reason)]);
        });
        AuditLogger::log('payroll_voided', 'Attendance', sprintf(
            'Planilla del %s al %s anulada por %s. Motivo: %s',
            $period->period_start->format('d/m/Y'), $period->period_end->format('d/m/Y'), $by->name, trim($reason)
        ), $by->id);

        return $period->fresh();
    }

    public function periodRow(PayrollPeriod $period): array
    {
        return [
            'id'             => $period->id,
            'from'           => $period->period_start->toDateString(),
            'to'             => $period->period_end->toDateString(),
            'status'         => $period->status,
            'gross_total'    => (float) $period->gross_total,
            'advances_total' => (float) $period->advances_total,
            'others_total'   => (float) $period->others_total,
            'net_total'      => (float) $period->net_total,
            'payment_method' => $period->payment_method,
            'approved_by'    => $period->approver?->name,
            'approved_at'    => $period->approved_at?->format('Y-m-d H:i'),
            'expense_id'     => $period->finance_expense_id,
            'void_reason'    => $period->void_reason,
        ];
    }

    public function category(): ?ExpenseCategory
    {
        return ExpenseCategory::where('name', 'Salarios y personal')->first()
            ?? ExpenseCategory::where('group_name', 'Personal')->orderBy('sort_order')->first();
    }

    private function hm(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h ? ($m ? "{$h} h {$m} min" : "{$h} h") : "{$m} min";
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }
}
