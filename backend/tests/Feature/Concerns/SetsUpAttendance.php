<?php

namespace Tests\Feature\Concerns;

use App\Models\AttendanceDevice;
use App\Models\AttendanceNetwork;
use App\Models\EmployeeProfile;
use App\Models\FaceEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Equipo autorizado, red del local y personas que marcan, para las pruebas del reloj de asistencia.
 */
trait SetsUpAttendance
{
    protected string $deviceToken = '';
    protected ?AttendanceDevice $device = null;

    protected function authorizeDevice(string $name = 'Portátil de caja'): string
    {
        $this->deviceToken = Str::random(64);
        $this->device = AttendanceDevice::create(['name' => $name, 'token_hash' => AttendanceDevice::hashToken($this->deviceToken)]);

        return $this->deviceToken;
    }

    /** Las pruebas llegan desde 127.0.0.1. */
    protected function localNetwork(string $cidr = '127.0.0.1/32'): AttendanceNetwork
    {
        return AttendanceNetwork::create(['label' => 'Red del local', 'cidr' => $cidr]);
    }

    protected function worker(string $role = 'cashier', string $pin = '1234', array $profile = []): User
    {
        $user = User::factory()->create(['role' => $role]);
        $p = EmployeeProfile::create(array_merge([
            'user_id' => $user->id, 'attendance_enabled' => true, 'pay_mode' => 'hourly', 'hourly_rate' => 15,
        ], $profile));
        $p->forceFill(['pin_hash' => Hash::make($pin)])->save();

        return $user->fresh();
    }

    protected function photo(): string
    {
        return 'data:image/jpeg;base64,' . base64_encode(file_get_contents(base_path('tests/Fixtures/asistencia-foto.jpg')));
    }

    protected function descriptor(float $value = 0.0, int $size = 1024): array
    {
        return array_fill(0, $size, $value);
    }

    protected function enrollFace(User $user, float $value = 0.0): FaceEnrollment
    {
        return FaceEnrollment::create(['user_id' => $user->id, 'descriptor' => $this->descriptor($value)]);
    }

    protected function kiosk(string $method, string $uri, array $data = [], ?string $token = null, ?string $ip = null): TestResponse
    {
        $headers = ['X-Attendance-Device' => $token ?? $this->deviceToken, 'Accept' => 'application/json'];
        if ($ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        return $this->json($method, "/api/attendance/kiosk/{$uri}", $data, $headers);
    }

    protected function clock(User $user, array $extra = [], string $pin = '1234', ?string $ip = null): TestResponse
    {
        return $this->kiosk('POST', 'clock', array_merge(['user_id' => $user->id, 'pin' => $pin, 'photo' => $this->photo()], $extra), null, $ip);
    }
}
