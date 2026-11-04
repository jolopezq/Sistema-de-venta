<?php

namespace Tests\Feature;

use App\Models\AttendanceMark;
use App\Models\AttendanceNetwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\SetsUpAttendance;
use Tests\TestCase;

/**
 * Reloj de asistencia en el equipo autorizado (docs/PLAN_ASISTENCIA_Y_SUELDOS.md, F1).
 */
class AttendanceKioskTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAttendance;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        RateLimiter::clear('kiosk|127.0.0.1');
        $this->authorizeDevice();
        $this->travelTo(now()->setTime(14, 0));
    }

    public function test_the_kiosk_only_works_on_an_authorized_device(): void
    {
        $this->kiosk('GET', 'roster', [], 'llave-que-no-existe-0123456789-0123456789-abcdef')
            ->assertForbidden()->assertJsonPath('reason', 'device_not_authorized');

        $this->device->update(['revoked_at' => now()]);
        $this->kiosk('GET', 'roster')->assertForbidden();
    }

    public function test_the_roster_shows_names_and_next_mark_but_never_pay_or_hours(): void
    {
        $this->localNetwork();
        $cajera = $this->worker('cashier', '1234', ['monthly_salary' => 3300, 'pay_mode' => 'monthly']);
        $staff = $this->worker('staff');
        User::factory()->create(['role' => 'cashier']); // sin perfil: no marca

        $response = $this->kiosk('GET', 'roster')->assertOk();
        $people = collect($response->json('people'));
        $this->assertCount(2, $people);
        $this->assertSame(['id', 'name', 'initials', 'next', 'has_pin', 'face_enrolled'], array_keys($people->first()));
        $this->assertSame('in', $people->firstWhere('id', $cajera->id)['next']);
        $this->assertTrue($response->json('network_ok'));
        $this->assertStringNotContainsString('3300', $response->getContent());
        $this->assertNotNull($people->firstWhere('id', $staff->id));
    }

    public function test_entry_and_exit_from_the_local_are_valid_and_keep_a_photo(): void
    {
        $this->localNetwork();
        $user = $this->worker();

        $this->kiosk('POST', 'pin', ['user_id' => $user->id, 'pin' => '1234'])->assertOk()->assertJsonPath('next', 'in');
        $entry = $this->clock($user)->assertCreated()
            ->assertJsonPath('type', 'in')
            ->assertJsonPath('time', '14:00')
            ->assertJsonPath('status', 'registered');
        $this->assertArrayNotHasKey('face_score', $entry->json());

        $this->travel(8)->hours();
        $this->clock($user)->assertCreated()->assertJsonPath('type', 'out')->assertJsonPath('time', '22:00')->assertJsonPath('entry_time', '14:00');

        $marks = AttendanceMark::orderBy('id')->get();
        $this->assertSame(['in', 'out'], $marks->pluck('type')->all());
        $this->assertSame(['valid', 'valid'], $marks->pluck('status')->all());
        $this->assertTrue($marks[0]->network_ok && $marks[0]->device_ok);
        $this->assertSame($this->device->id, $marks[0]->device_id);
        Storage::disk('local')->assertExists($marks[0]->photo_path);
        $this->assertStringStartsWith('asistencia/' . now()->format('Y/m') . '/', $marks[0]->photo_path);
    }

    public function test_a_wrong_pin_is_rejected_and_locks_the_person_after_five_attempts(): void
    {
        $this->localNetwork();
        $user = $this->worker();
        RateLimiter::clear("attendance-pin:{$this->device->id}:{$user->id}");

        for ($i = 0; $i < 5; $i++) {
            $this->clock($user, [], '9999')->assertUnprocessable()->assertJsonValidationErrors('pin');
        }
        $this->clock($user)->assertStatus(429)->assertJsonPath('reason', 'pin_locked');
        $this->assertSame(0, AttendanceMark::count());
    }

    public function test_a_double_tap_returns_the_same_mark(): void
    {
        $this->localNetwork();
        $user = $this->worker();
        $this->clock($user)->assertCreated();
        $this->travel(30)->seconds();
        $this->clock($user)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('type', 'in');
        $this->assertSame(1, AttendanceMark::count());
    }

    public function test_a_new_network_keeps_the_mark_pending_and_confirming_it_validates_the_rest(): void
    {
        $user = $this->worker();
        $other = $this->worker('staff', '4321');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->clock($user)->assertCreated()->assertJsonPath('status', 'saved_for_review');
        $this->clock($other, ['pin' => '4321'])->assertCreated();
        $this->assertSame(2, AttendanceMark::where('status', 'pending_network')->count());

        $first = AttendanceMark::where('user_id', $user->id)->first();
        $this->actingAs($admin)->postJson("/api/attendance/marks/{$first->id}/approve", ['add_network' => true])
            ->assertOk()->assertJsonPath('mark.status', 'valid')->assertJsonPath('revalidated', 2);

        $this->assertSame(2, AttendanceMark::where('status', 'valid')->count());
        $this->assertDatabaseHas('attendance_networks', ['cidr' => '127.0.0.1/32', 'confirmed_from_mark_id' => $first->id]);

        // La próxima marca desde esa red ya entra sola
        $this->travel(5)->hours();
        $this->clock($user)->assertCreated()->assertJsonPath('status', 'registered');
    }

    public function test_the_face_is_compared_on_the_server_and_the_worker_never_sees_the_score(): void
    {
        $this->localNetwork();
        $user = $this->worker();
        $this->enrollFace($user, 0.0);

        $response = $this->clock($user, ['descriptor' => $this->descriptor(0.5), 'liveness_ok' => true])
            ->assertCreated()->assertJsonPath('status', 'saved_for_review');
        $this->assertStringNotContainsString('score', $response->getContent());
        $this->assertSame('face_review', AttendanceMark::first()->status);
        $this->assertEqualsWithDelta(0.0, AttendanceMark::first()->face_score, 0.001);

        $this->travel(4)->hours();
        $this->clock($user, ['descriptor' => $this->descriptor(0.25), 'liveness_ok' => true])->assertJsonPath('status', 'registered');
        $this->assertEqualsWithDelta(0.67, AttendanceMark::latest('id')->first()->face_score, 0.01);

        // Sin parpadeo o sin descriptor también se revisa
        $this->travel(4)->hours();
        $this->clock($user, ['descriptor' => $this->descriptor(0.0), 'liveness_ok' => false])->assertJsonPath('status', 'saved_for_review');
        $this->travel(4)->hours();
        $this->clock($user)->assertJsonPath('status', 'saved_for_review');
    }

    public function test_an_offline_mark_keeps_the_device_time_and_waits_for_approval(): void
    {
        $this->localNetwork();
        $user = $this->worker();
        $uuid = (string) Str::uuid();
        $payload = [
            'offline' => true, 'type' => 'in', 'local_uuid' => $uuid,
            'device_time' => now()->subHours(2)->utc()->toIso8601String(),
        ];

        $this->clock($user, $payload)->assertCreated()->assertJsonPath('status', 'saved_for_review')->assertJsonPath('time', '12:00');
        $this->clock($user, $payload)->assertOk()->assertJsonPath('duplicate', true);

        $mark = AttendanceMark::sole();
        $this->assertSame('offline_review', $mark->status);
        $this->assertSame('offline', $mark->source);
        $this->assertSame('12:00', $mark->marked_at->format('H:i'));
        $this->assertSame('14:00', $mark->received_at->format('H:i'));

        $this->clock($user, ['offline' => true, 'device_time' => now()->addHour()->toIso8601String(), 'local_uuid' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('device_time');
    }

    public function test_my_marks_only_has_the_day_and_the_entry_and_exit_times(): void
    {
        $this->localNetwork();
        $user = $this->worker('cashier', '1234', ['pay_mode' => 'monthly', 'monthly_salary' => 3300]);
        $this->travelTo(now()->subDay()->setTime(8, 1));
        $this->clock($user)->assertCreated();
        $this->travelTo(now()->setTime(16, 5));
        $this->clock($user)->assertCreated();
        $this->travelTo(now()->addDay()->setTime(8, 3));
        $this->clock($user)->assertCreated();

        $response = $this->kiosk('POST', 'my-marks', ['user_id' => $user->id, 'pin' => '1234'])->assertOk();
        $days = $response->json('days');
        $this->assertSame([
            ['date' => now()->toDateString(), 'label' => 'Hoy', 'in' => '08:03', 'out' => null],
            ['date' => now()->subDay()->toDateString(), 'label' => 'Ayer', 'in' => '08:01', 'out' => '16:05'],
        ], $days);
        $this->assertStringNotContainsString('3300', $response->getContent());

        $this->kiosk('POST', 'my-marks', ['user_id' => $user->id, 'pin' => '0000'])->assertUnprocessable();
    }

    public function test_staff_only_marks_and_cannot_log_in(): void
    {
        $staff = $this->worker('staff');
        $staff->update(['password' => 'clave-segura']);

        $this->postJson('/api/login', ['email' => $staff->email, 'password' => 'clave-segura'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_a_person_without_attendance_cannot_mark(): void
    {
        $this->localNetwork();
        $user = $this->worker();
        $user->employeeProfile->update(['attendance_enabled' => false]);

        $this->clock($user)->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertSame(0, AttendanceMark::count());
        $this->assertSame(1, AttendanceNetwork::count());
    }
}
