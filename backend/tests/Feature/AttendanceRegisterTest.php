<?php

namespace Tests\Feature;

use App\Models\AttendanceMark;
use App\Models\User;
use App\Services\Attendance\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\SetsUpAttendance;
use Tests\TestCase;

/**
 * Caja y asistencia: con el ajuste activo, abrir la caja pide la entrada (docs/PLAN_ASISTENCIA_Y_SUELDOS.md, D4).
 */
class AttendanceRegisterTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAttendance;

    public function test_opening_the_register_only_warns_until_the_owner_turns_the_rule_on(): void
    {
        $this->travelTo(now()->setTime(8, 0));
        $cashier = $this->worker('cashier');

        // Por defecto solo se avisa (las dos primeras semanas)
        $this->actingAs($cashier)->postJson('/api/cash-sessions', ['opening_amount' => 200])->assertSuccessful();
    }

    public function test_with_the_rule_on_the_register_needs_todays_entry(): void
    {
        $this->travelTo(now()->setTime(8, 0));
        app(AttendanceSettings::class)->set('require_entry_to_open_register', true);
        $cashier = $this->worker('cashier');
        $withoutAttendance = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($cashier)->postJson('/api/cash-sessions', ['opening_amount' => 200])
            ->assertStatus(422)->assertJsonPath('reason', 'attendance_entry_required');

        // Quien no marca asistencia no se ve afectado
        $this->actingAs($withoutAttendance)->postJson('/api/cash-sessions', ['opening_amount' => 200])->assertSuccessful();

        AttendanceMark::create([
            'user_id' => $cashier->id, 'type' => 'in', 'marked_at' => now()->subMinutes(3), 'status' => 'valid',
            'source' => 'kiosk', 'network_ok' => true, 'device_ok' => true,
        ]);
        $this->actingAs($cashier)->postJson('/api/cash-sessions', ['opening_amount' => 200])->assertSuccessful();
    }

    public function test_the_owner_turns_the_rule_on_from_the_setup_and_it_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson('/api/attendance/settings', ['require_entry_to_open_register' => true])
            ->assertOk()->assertJsonPath('settings.require_entry_to_open_register', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance_settings_changed']);

        $cashier = $this->worker('cashier');
        $this->actingAs($cashier)->getJson('/api/attendance/me/today')->assertJsonPath('require_entry_to_open_register', true);
        $this->actingAs($cashier)->putJson('/api/attendance/settings', ['require_entry_to_open_register' => false])->assertForbidden();
    }
}
