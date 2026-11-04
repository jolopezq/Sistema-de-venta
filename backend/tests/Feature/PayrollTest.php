<?php

namespace Tests\Feature;

use App\Models\AttendanceMark;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\ExpenseCategory;
use App\Models\FinanceExpense;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\SetsUpAttendance;
use Tests\TestCase;

/**
 * Planilla de sueldos con los ejemplos del plan (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §3.5): por hora y sueldo mensual.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAttendance;

    private User $owner;
    private ExpenseCategory $salaries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->salaries = ExpenseCategory::firstOrCreate(['name' => 'Salarios y personal'], ['section' => 'operating', 'group_name' => 'Personal', 'sort_order' => 10, 'is_active' => true]);
    }

    private function shift(User $user, string $date, string $in, string $out, int $outNextDay = 0): void
    {
        foreach ([['in', Carbon::parse("{$date} {$in}")], ['out', Carbon::parse("{$date} {$out}")->addDays($outNextDay)]] as [$type, $at]) {
            AttendanceMark::create([
                'user_id' => $user->id, 'type' => $type, 'marked_at' => $at, 'status' => 'valid',
                'source' => 'kiosk', 'network_ok' => true, 'device_ok' => true,
            ]);
        }
    }

    private function advance(User $user, float $amount, string $date): void
    {
        $session = CashRegisterSession::create(['cashier_id' => $this->owner->id, 'opening_amount' => 100, 'status' => 'closed', 'opened_at' => "{$date} 08:00:00", 'closed_at' => "{$date} 22:00:00"]);
        CashMovement::create([
            'cash_register_session_id' => $session->id, 'user_id' => $this->owner->id, 'expense_category_id' => $this->salaries->id,
            'employee_id' => $user->id, 'type' => 'expense', 'payment_method' => 'cash', 'amount' => $amount,
            'description' => 'Adelanto de sueldo', 'occurred_at' => "{$date} 12:00:00",
        ]);
    }

    /** Días hábiles (lunes a viernes) de octubre de 2026. */
    private function weekdays(): array
    {
        $days = [];
        for ($d = Carbon::parse('2026-10-01'); $d->month === 10; $d->addDay()) {
            if (!$d->isWeekend()) {
                $days[] = $d->toDateString();
            }
        }

        return $days;
    }

    public function test_hourly_example_from_the_plan(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:00'));
        $user = $this->worker('staff', '1234', ['pay_mode' => 'hourly', 'hourly_rate' => 15]);
        $days = array_slice($this->weekdays(), 0, 22);
        // Los tres primeros días: 14:00 → 00:00 del día siguiente = 10 h (2 h extra cada uno; cuentan para el día de la entrada)
        foreach ($days as $i => $date) {
            $i < 3 ? $this->shift($user, $date, '14:00', '00:00', 1) : $this->shift($user, $date, '14:00', '22:00');
        }
        $this->advance($user, 300, '2026-10-15');
        $this->travelTo(Carbon::parse('2026-11-03 10:00'));

        $line = $this->actingAs($this->owner)->getJson('/api/attendance/payroll?from=2026-10-01&to=2026-10-31')
            ->assertOk()->json('lines.0');
        $this->assertSame(22, $line['days_worked']);
        $this->assertSame(176 * 60, $line['normal_minutes']);
        $this->assertSame(6 * 60, $line['overtime_minutes']);
        $this->assertEquals(2640.0, $line['breakdown']['base']);
        $this->assertEquals(180.0, $line['breakdown']['overtime']);
        $this->assertEquals(2820.0, $line['gross']);
        $this->assertEquals(300.0, $line['advances']);
        $this->assertEquals(2520.0, $line['net']);
    }

    public function test_monthly_example_from_the_plan_with_lates_absence_and_overtime(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:00'));
        $schedule = [];
        foreach ([1, 2, 3, 4, 5, 6] as $d) {
            $schedule[(string) $d] = ['start' => '08:00', 'end' => '16:00'];
        }
        $user = $this->worker('cashier', '1234', ['pay_mode' => 'monthly', 'monthly_salary' => 3300, 'schedule' => $schedule, 'late_tolerance_min' => 10]);

        $workdays = [];
        for ($d = Carbon::parse('2026-10-01'); $d->month === 10; $d->addDay()) {
            if (!$d->isSunday()) {
                $workdays[] = $d->toDateString();
            }
        }
        $this->assertCount(27, $workdays);
        foreach ($workdays as $date) {
            match ($date) {
                '2026-10-05' => null,                                         // falta
                '2026-10-02' => $this->shift($user, $date, '08:16', '16:00'), // atraso 16 min
                '2026-10-07' => $this->shift($user, $date, '08:29', '16:00'), // atraso 29 min
                '2026-10-08' => $this->shift($user, $date, '08:09', '16:00'), // dentro de la tolerancia
                '2026-10-03', '2026-10-10' => $this->shift($user, $date, '08:00', '18:00'), // 2 h extra
                default => $this->shift($user, $date, '08:00', '16:00'),
            };
        }
        $this->travelTo(Carbon::parse('2026-11-03 10:00'));

        $line = $this->actingAs($this->owner)->getJson('/api/attendance/payroll?from=2026-10-01&to=2026-10-31')
            ->assertOk()->json('lines.0');
        $this->assertSame(26, $line['days_worked']);
        $this->assertSame(45, $line['late_minutes']);
        $this->assertSame(1, $line['absences']);
        $this->assertSame(240, $line['overtime_minutes']);
        $this->assertEquals(13.75, $line['breakdown']['hour_value']);
        $this->assertEquals(10.31, $line['breakdown']['late_deduction']);
        $this->assertEquals(110.0, $line['breakdown']['absence_deduction']);
        $this->assertEquals(110.0, $line['breakdown']['overtime']);
        $this->assertEquals(3289.69, $line['gross']);
        $notes = collect($line['days'])->firstWhere('date', '2026-10-05')['notes'];
        $this->assertSame(['Falta (tenía horario)'], $notes);
    }

    public function test_approving_creates_one_expense_without_counting_the_advance_twice_and_can_be_voided(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:00'));
        $user = $this->worker('staff', '1234', ['pay_mode' => 'hourly', 'hourly_rate' => 20]);
        $this->shift($user, '2026-10-02', '14:00', '22:00'); // 8 h = 160
        $this->advance($user, 60, '2026-10-02');
        $this->travelTo(Carbon::parse('2026-11-03 10:00'));

        $response = $this->actingAs($this->owner)->postJson('/api/attendance/payroll/approve', [
            'from' => '2026-10-01', 'to' => '2026-10-31', 'payment_method' => 'cash',
            'others' => [['user_id' => $user->id, 'amount' => 15, 'reason' => 'Bono por inventario']],
        ])->assertCreated();
        $response->assertJsonPath('period.gross_total', 160)->assertJsonPath('period.advances_total', 60)->assertJsonPath('period.net_total', 115);

        $expense = FinanceExpense::sole();
        $this->assertEquals(115.0, (float) $expense->amount);
        $this->assertSame($this->salaries->id, $expense->expense_category_id);
        $this->assertSame('2026-10-31', $expense->date->toDateString());
        // En Finanzas, sueldos del mes = gasto 115 + adelanto de caja 60 = bruto 160 + bono 15
        $this->assertEquals(175.0, (float) $expense->amount + (float) CashMovement::sum('amount'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll_approved']);

        $this->actingAs($this->owner)->postJson('/api/attendance/payroll/approve', [
            'from' => '2026-10-15', 'to' => '2026-10-31', 'payment_method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('from');

        $period = $response->json('period.id');
        $this->actingAs($this->owner)->getJson("/api/attendance/payroll/periods/{$period}")->assertOk()
            ->assertJsonPath('lines.0.others_reason', 'Bono por inventario')->assertJsonPath('lines.0.net', 115);
        $this->actingAs($this->owner)->postJson("/api/attendance/payroll/periods/{$period}/void", ['reason' => 'Faltaba un bono'])
            ->assertOk()->assertJsonPath('period.status', 'voided');
        $this->assertSoftDeleted($expense);
        $this->actingAs($this->owner)->postJson('/api/attendance/payroll/approve', [
            'from' => '2026-10-01', 'to' => '2026-10-31', 'payment_method' => 'bank_transfer',
        ])->assertCreated();
    }

    public function test_pending_marks_or_missing_exits_block_the_approval(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:00'));
        $user = $this->worker('staff');
        AttendanceMark::create(['user_id' => $user->id, 'type' => 'in', 'marked_at' => '2026-10-02 14:00:00', 'status' => 'face_review', 'source' => 'kiosk']);
        AttendanceMark::create(['user_id' => $user->id, 'type' => 'in', 'marked_at' => '2026-10-09 14:00:00', 'status' => 'valid', 'source' => 'kiosk']);
        $this->travelTo(Carbon::parse('2026-11-03 10:00'));

        $this->actingAs($this->owner)->getJson('/api/attendance/payroll?from=2026-10-01&to=2026-10-31')
            ->assertJsonPath('blockers.pending', 1)->assertJsonPath('blockers.without_exit', 1);
        $this->actingAs($this->owner)->postJson('/api/attendance/payroll/approve', ['from' => '2026-10-01', 'to' => '2026-10-31', 'payment_method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('blockers');
        $this->actingAs($this->owner)->postJson('/api/attendance/payroll/approve', ['from' => '2026-11-01', 'to' => '2026-11-30', 'payment_method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_only_administrators_see_the_payroll_and_cashiers_get_only_names_for_advances(): void
    {
        $cashier = $this->worker('cashier');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/attendance/payroll?from=2026-10-01&to=2026-10-31')->assertOk();
        $this->actingAs($cashier)->getJson('/api/attendance/payroll?from=2026-10-01&to=2026-10-31')->assertForbidden();
        $this->actingAs($cashier)->getJson('/api/attendance/payroll/periods')->assertForbidden();

        $people = $this->actingAs($cashier)->getJson('/api/attendance/people')->assertOk();
        $this->assertSame([['id' => $cashier->id, 'name' => $cashier->name]], $people->json('data'));
    }

    public function test_a_cash_advance_records_who_received_it(): void
    {
        $cashier = $this->worker('cashier');
        $staff = $this->worker('staff');
        $session = $this->actingAs($cashier)->postJson('/api/cash-sessions', ['opening_amount' => 200])->assertSuccessful()->json('session.id');

        $this->actingAs($cashier)->postJson("/api/cash-sessions/{$session}/movements", [
            'type' => 'expense', 'amount' => 50, 'description' => 'Adelanto de sueldo', 'payment_method' => 'cash',
            'expense_category_id' => $this->salaries->id, 'employee_id' => $staff->id,
        ])->assertCreated()->assertJsonPath('movement.employee_id', $staff->id);
    }
}
