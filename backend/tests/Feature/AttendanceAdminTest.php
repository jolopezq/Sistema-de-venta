<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\AttendanceMark;
use App\Models\EmployeeProfile;
use App\Models\FaceEnrollment;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\FaceMatcher;
use App\Services\Attendance\LocalPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\SetsUpAttendance;
use Tests\TestCase;

/**
 * Asistencia para administradores, perfil laboral y lo que ve cada uno (docs/PLAN_ASISTENCIA_Y_SUELDOS.md, F1 y §5.8).
 */
class AttendanceAdminTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAttendance;

    private User $owner;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        RateLimiter::clear('kiosk|127.0.0.1');
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->authorizeDevice();
        $this->travelTo(now()->setTime(14, 0));
    }

    private function mark(User $user, string $type, $at, string $status = 'valid', array $extra = []): AttendanceMark
    {
        return AttendanceMark::create(array_merge([
            'user_id' => $user->id, 'type' => $type, 'marked_at' => $at, 'received_at' => $at,
            'status' => $status, 'source' => 'kiosk', 'network_ok' => true, 'device_ok' => true,
        ], $extra));
    }

    public function test_admins_see_attendance_by_default_and_cashiers_or_kitchen_never(): void
    {
        $cashier = $this->worker('cashier');
        $kitchen = User::factory()->create(['role' => 'kitchen']);

        $this->actingAs($this->admin)->getJson('/api/attendance/today')->assertOk();
        $this->actingAs($this->owner)->getJson('/api/attendance/review')->assertOk();
        foreach (['/api/attendance/today', '/api/attendance/review', '/api/attendance/marks', '/api/attendance/setup'] as $uri) {
            $this->actingAs($cashier)->getJson($uri)->assertForbidden();
            $this->actingAs($kitchen)->getJson($uri)->assertForbidden();
        }

        // El dueño se lo puede quitar a un administrador
        $this->actingAs($this->owner)->putJson("/api/users/{$this->admin->id}/permissions", [
            'permissions' => [['module' => 'attendance', 'access_level' => 'none']],
        ])->assertOk()->assertJsonPath('modules.attendance.effective', 'none');
        $this->actingAs($this->admin->fresh())->getJson('/api/attendance/today')->assertForbidden();
    }

    public function test_a_cashier_only_sees_their_own_entry_and_exit_of_today(): void
    {
        $cashier = $this->worker('cashier', '1234', ['pay_mode' => 'monthly', 'monthly_salary' => 3300]);
        $other = $this->worker('staff');
        $this->mark($cashier, 'in', now()->setTime(8, 1));
        $this->mark($other, 'in', now()->setTime(7, 58));

        $response = $this->actingAs($cashier)->getJson('/api/attendance/me/today')->assertOk();
        $this->assertSame(['enabled' => true, 'entry' => '08:01', 'exit' => null, 'open' => true, 'require_entry_to_open_register' => false], $response->json());
        $this->assertStringNotContainsString('07:58', $response->getContent());

        $mark = AttendanceMark::first();
        $this->actingAs($cashier)->getJson("/api/attendance/marks/{$mark->id}/photo")->assertForbidden();
        $this->actingAs($cashier)->getJson("/api/users/{$cashier->id}/employee-profile")->assertForbidden();
    }

    public function test_the_review_tray_lists_pending_marks_and_entries_without_exit(): void
    {
        $user = $this->worker('staff');
        $this->mark($user, 'in', now()->subDay()->setTime(14, 2));
        $this->mark($user, 'in', now()->setTime(13, 59), 'face_review', ['face_score' => 0.41]);

        $review = $this->actingAs($this->admin)->getJson('/api/attendance/review')->assertOk();
        $review->assertJsonPath('count', 2)
            ->assertJsonPath('pending.0.status', 'face_review')
            ->assertJsonPath('pending.0.face_score', 0.41)
            ->assertJsonPath('without_exit.0.mark.time', '14:02');
    }

    public function test_a_correction_keeps_the_original_needs_a_reason_and_is_audited(): void
    {
        $user = $this->worker('staff');
        $entry = $this->mark($user, 'in', now()->subDay()->setTime(14, 2));

        $this->actingAs($this->admin)->postJson('/api/attendance/marks', [
            'user_id' => $user->id, 'type' => 'out', 'marked_at' => now()->subDay()->setTime(22, 10)->format('Y-m-d H:i'), 'reason' => 'corto',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($this->admin)->postJson('/api/attendance/marks', [
            'user_id' => $user->id, 'type' => 'out', 'marked_at' => now()->subDay()->setTime(22, 10)->format('Y-m-d H:i'),
            'reason' => 'Se olvidó de marcar; cerró conmigo a las 22:10',
        ])->assertCreated()->assertJsonPath('mark.source', 'manual')->assertJsonPath('mark.status', 'valid');

        // Corregir la entrada: la original queda «corregida»
        $this->actingAs($this->admin)->postJson('/api/attendance/marks', [
            'user_id' => $user->id, 'type' => 'in', 'marked_at' => now()->subDay()->setTime(14, 0)->format('Y-m-d H:i'),
            'reason' => 'Llegó a las 14:00 y la portátil estaba apagada', 'corrects_id' => $entry->id,
        ])->assertCreated();
        $this->assertSame('corrected', $entry->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance_mark_corrected']);
        $this->assertSame(0, $this->actingAs($this->admin)->getJson('/api/attendance/review')->json('count'));

        $this->actingAs($this->admin)->postJson('/api/attendance/marks', [
            'user_id' => $user->id, 'type' => 'in', 'marked_at' => now()->addHour()->format('Y-m-d H:i'), 'reason' => 'Una hora que no pasó todavía',
        ])->assertUnprocessable()->assertJsonValidationErrors('marked_at');
    }

    public function test_rejecting_needs_a_reason_and_only_pending_marks_can_be_reviewed(): void
    {
        $user = $this->worker('staff');
        $pending = $this->mark($user, 'in', now(), 'offline_review');
        $valid = $this->mark($user, 'out', now()->addMinutes(5), 'valid');

        $this->actingAs($this->admin)->postJson("/api/attendance/marks/{$pending->id}/reject", ['reason' => 'no'])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson("/api/attendance/marks/{$pending->id}/reject", ['reason' => 'La hora del equipo estaba mal'])
            ->assertOk()->assertJsonPath('mark.status', 'rejected');
        $this->actingAs($this->admin)->postJson("/api/attendance/marks/{$valid->id}/approve")->assertUnprocessable();
    }

    public function test_authorizing_a_device_returns_the_key_once_and_can_register_the_network(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/attendance/devices', ['name' => 'Portátil Toshiba', 'add_network' => true])
            ->assertCreated()->assertJsonPath('network.cidr', '127.0.0.1/32');
        $token = $response->json('token');
        $this->assertSame(64, strlen($token));
        $device = AttendanceDevice::where('name', 'Portátil Toshiba')->sole();
        $this->assertSame(hash('sha256', $token), $device->token_hash);
        $this->assertStringNotContainsString($token, $this->actingAs($this->admin)->getJson('/api/attendance/setup')->getContent());

        $this->kiosk('GET', 'roster', [], $token)->assertOk()->assertJsonPath('network_ok', true);
        $this->actingAs($this->admin)->deleteJson("/api/attendance/devices/{$device->id}")->assertOk();
        $this->kiosk('GET', 'roster', [], $token)->assertForbidden();
    }

    public function test_networks_match_ipv4_ranges_and_ipv6_by_their_64_prefix(): void
    {
        $presence = app(LocalPresenceService::class);
        $this->assertSame('181.115.1.20/32', $presence->networkFor('181.115.1.20'));
        $this->assertSame('2800:cd0:1a2b:3c4d::/64', $presence->networkFor('2800:cd0:1a2b:3c4d:1111:2222:3333:4444'));
        $this->assertTrue($presence->ipMatches('2800:cd0:1a2b:3c4d:9:8:7:6', '2800:cd0:1a2b:3c4d::/64'));
        $this->assertFalse($presence->ipMatches('2800:cd0:1a2b:3c4e::1', '2800:cd0:1a2b:3c4d::/64'));
        $this->assertTrue($presence->ipMatches('181.115.1.200', '181.115.1.0/24'));
        $this->assertFalse($presence->ipMatches('181.115.2.1', '181.115.1.0/24'));
        $this->assertTrue($presence->ipMatches('181.115.1.20', '181.115.1.20'));
        $this->assertFalse($presence->ipMatches('181.115.1.20', '2800:cd0::/64'));

        $this->actingAs($this->admin)->postJson('/api/attendance/networks', ['cidr' => 'no-es-una-red'])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson('/api/attendance/networks', ['cidr' => '2800:cd0:1a2b:3c4d::/64', 'label' => 'Fibra'])->assertCreated();
    }

    public function test_the_face_matcher_uses_the_same_scale_as_the_browser_library(): void
    {
        $faces = app(FaceMatcher::class);
        $this->assertSame(1.0, $faces->similarity([0.1, 0.2], [0.1, 0.2]));
        $this->assertSame(0.0, $faces->similarity($this->descriptor(0.0), $this->descriptor(0.5)));
        $this->assertEqualsWithDelta(0.67, $faces->similarity($this->descriptor(0.0), $this->descriptor(0.25)), 0.01);
        $this->assertSame(0.0, $faces->similarity([1, 2, 3], [1, 2]));
    }

    public function test_only_the_owner_edits_the_work_profile_and_the_pin_is_stored_hashed(): void
    {
        $user = User::factory()->create(['role' => 'staff']);

        $this->actingAs($this->admin)->getJson("/api/users/{$user->id}/employee-profile")->assertForbidden();
        $this->actingAs($this->owner)->putJson("/api/users/{$user->id}/employee-profile", [
            'attendance_enabled' => true, 'pay_mode' => 'monthly',
        ])->assertUnprocessable()->assertJsonValidationErrors('monthly_salary');

        $this->actingAs($this->owner)->putJson("/api/users/{$user->id}/employee-profile", [
            'attendance_enabled' => true, 'pay_mode' => 'hourly', 'hourly_rate' => 15,
            'schedule' => ['1' => ['start' => '14:00', 'end' => '22:00'], '7' => ['start' => null, 'end' => null]],
        ])->assertOk()->assertJsonPath('profile.schedule.1.start', '14:00')->assertJsonMissingPath('profile.schedule.7');

        $this->actingAs($this->owner)->putJson("/api/users/{$user->id}/employee-profile/pin", ['pin' => '12a4'])->assertUnprocessable();
        $this->actingAs($this->owner)->putJson("/api/users/{$user->id}/employee-profile/pin", ['pin' => '4321'])->assertOk();
        $profile = EmployeeProfile::where('user_id', $user->id)->sole();
        $this->assertNotSame('4321', $profile->pin_hash);
        $this->assertTrue(Hash::check('4321', $profile->pin_hash));
        $show = $this->actingAs($this->owner)->getJson("/api/users/{$user->id}/employee-profile")->assertOk()->assertJsonPath('has_pin', true);
        $this->assertStringNotContainsString('pin_hash', $show->getContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_pin_changed']);
    }

    public function test_registering_the_face_needs_consent_allows_five_captures_and_can_be_erased(): void
    {
        $user = $this->worker('staff');
        $capture = fn (array $extra = []) => $this->actingAs($this->owner)->postJson("/api/users/{$user->id}/face", array_merge([
            'descriptor' => $this->descriptor(0.1), 'photo' => $this->photo(),
        ], $extra));

        $capture()->assertUnprocessable()->assertJsonValidationErrors('consent');
        $capture(['consent' => true])->assertCreated()->assertJsonPath('face_count', 1);
        for ($i = 2; $i <= 5; $i++) {
            $capture()->assertCreated()->assertJsonPath('face_count', $i);
        }
        $capture()->assertUnprocessable();
        $this->assertNotNull($user->employeeProfile->fresh()->face_consent_at);
        $photo = FaceEnrollment::active()->first()->photo_path;
        $this->assertStringStartsWith('asistencia/rostros/', $photo);
        Storage::disk('local')->assertExists($photo);

        $this->actingAs($this->admin)->get("/api/attendance/users/{$user->id}/face-photo")->assertOk();

        $this->actingAs($this->owner)->deleteJson("/api/users/{$user->id}/face")->assertOk()->assertJsonPath('face_count', 0);
        Storage::disk('local')->assertMissing($photo);
        $this->assertSame(0, FaceEnrollment::active()->count());
    }

    public function test_mark_photos_are_erased_after_ninety_days_but_the_mark_stays(): void
    {
        $user = $this->worker('staff');
        Storage::disk('local')->put('asistencia/vieja.jpg', 'x');
        Storage::disk('local')->put('asistencia/nueva.jpg', 'x');
        $old = $this->mark($user, 'in', now()->subDays(91), 'valid', ['photo_path' => 'asistencia/vieja.jpg']);
        $new = $this->mark($user, 'in', now()->subDays(10), 'valid', ['photo_path' => 'asistencia/nueva.jpg']);

        $this->artisan('attendance:purge-photos')->assertSuccessful();

        Storage::disk('local')->assertMissing('asistencia/vieja.jpg');
        Storage::disk('local')->assertExists('asistencia/nueva.jpg');
        $this->assertNull($old->fresh()->photo_path);
        $this->assertNotNull($new->fresh()->photo_path);
    }

    public function test_removing_a_person_erases_their_face_and_takes_them_off_the_clock(): void
    {
        $user = $this->worker('staff');
        $this->enrollFace($user);
        $this->mark($user, 'in', now()->subHours(3));

        $this->actingAs($this->owner)->deleteJson("/api/users/{$user->id}")->assertNoContent();

        $this->assertSame(0, FaceEnrollment::active()->count());
        $this->assertSame(1, AttendanceMark::count());
        $this->assertCount(0, app(AttendanceService::class)->roster());
    }
}
