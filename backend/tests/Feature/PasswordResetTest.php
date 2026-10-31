<?php

namespace Tests\Feature;

use App\Mail\PasswordChangedMail;
use App\Mail\PasswordResetCodeMail;
use App\Models\AuditLog;
use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Recuperar la contraseña con un código enviado al correo (docs/PLAN_RECUPERAR_CONTRASENA.md). */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['email' => 'dueno@ohana.test', 'role' => 'super_admin', 'password' => 'vieja123']);
    }

    /** Pide el código y devuelve el que se envió por correo. */
    private function requestCode(string $email = 'dueno@ohana.test'): ?string
    {
        $code = null;
        $this->postJson('/api/password/code', ['email' => $email])->assertOk();
        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $m) use (&$code) { $code = $m->code; return true; });

        return $code;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_sends_a_six_digit_code_and_stores_only_its_hash(): void
    {
        $user = $this->owner();
        $code = $this->requestCode('  DUENO@ohana.test ');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        Mail::assertSent(PasswordResetCodeMail::class, fn ($m) => $m->hasTo('dueno@ohana.test'));
        $row = PasswordResetCode::first();
        $this->assertSame($user->id, $row->user_id);
        $this->assertNotSame($code, $row->code_hash);
        $this->assertStringNotContainsString($code, json_encode($row->getAttributes()));
        $this->assertTrue($row->expires_at->between(now()->addMinutes(14), now()->addMinutes(15)));
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset_requested', 'user_id' => $user->id]);
    }

    public function test_unknown_email_gets_the_same_answer_and_no_mail(): void
    {
        $this->owner();
        $known = $this->postJson('/api/password/code', ['email' => 'dueno@ohana.test'])->json();
        $unknown = $this->postJson('/api/password/code', ['email' => 'nadie@ohana.test'])->assertOk()->json();

        $this->assertSame($known, $unknown);
        Mail::assertSentCount(1);
    }

    public function test_deleted_user_gets_no_code(): void
    {
        $this->owner()->delete();
        $this->postJson('/api/password/code', ['email' => 'dueno@ohana.test'])->assertOk();
        Mail::assertNothingSent();
    }

    public function test_full_flow_changes_password_closes_sessions_and_notifies(): void
    {
        $user = $this->owner();
        $user->createToken('caja');
        $code = $this->requestCode();

        $token = $this->postJson('/api/password/verify', ['email' => 'dueno@ohana.test', 'code' => $code])->assertOk()->json('reset_token');
        $this->assertSame(64, strlen($token));

        $this->postJson('/api/password/reset', [
            'email' => 'dueno@ohana.test', 'reset_token' => $token, 'password' => 'nueva1234', 'password_confirmation' => 'nueva1234',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('nueva1234', $user->password));
        $this->assertSame(0, $user->tokens()->count(), 'las sesiones abiertas se cierran');
        Mail::assertSent(PasswordChangedMail::class, fn ($m) => $m->hasTo('dueno@ohana.test'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset_completed', 'user_id' => $user->id]);
        $this->postJson('/api/login', ['email' => 'dueno@ohana.test', 'password' => 'nueva1234'])->assertOk();

        // El permiso y el código no sirven dos veces
        $this->postJson('/api/password/reset', [
            'email' => 'dueno@ohana.test', 'reset_token' => $token, 'password' => 'otra12345', 'password_confirmation' => 'otra12345',
        ])->assertStatus(422)->assertJsonValidationErrors('reset_token');
        $this->postJson('/api/password/verify', ['email' => 'dueno@ohana.test', 'code' => $code])->assertStatus(422);
    }

    public function test_wrong_code_counts_attempts_and_locks_after_five(): void
    {
        $this->owner();
        $code = $this->requestCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/password/verify', ['email' => 'dueno@ohana.test', 'code' => $wrong])
                ->assertStatus(422)->assertJsonPath('errors.code.0', 'El código no es correcto o ya venció. Revísalo o pide uno nuevo.');
        }
        // Tras 5 intentos el código correcto tampoco sirve
        $this->postJson('/api/password/verify', ['email' => 'dueno@ohana.test', 'code' => $code])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset_code_locked']);
    }

    public function test_unknown_email_and_wrong_code_give_the_same_error(): void
    {
        $this->owner();
        $this->requestCode();
        $a = $this->postJson('/api/password/verify', ['email' => 'nadie@ohana.test', 'code' => '123456'])->assertStatus(422)->json();
        $b = $this->postJson('/api/password/verify', ['email' => 'dueno@ohana.test', 'code' => '999999'])->assertStatus(422)->json();
        $this->assertSame($a['errors'], $b['errors']);
    }

    public function test_code_expires_after_fifteen_minutes(): void
    {
        $this->owner();
        $code = $this->requestCode();
        $this->travel(16)->minutes();
        $this->postJson('/api/password/verify', ['email' => 'dueno@ohana.test', 'code' => $code])->assertStatus(422);
    }

    public function test_reset_permission_expires_after_ten_minutes(): void
    {
        $this->owner();
        $token = $this->postJson('/api/password/verify', ['email' => 'dueno@ohana.test', 'code' => $this->requestCode()])->json('reset_token');
        $this->travel(11)->minutes();
        $this->postJson('/api/password/reset', [
            'email' => 'dueno@ohana.test', 'reset_token' => $token, 'password' => 'nueva1234', 'password_confirmation' => 'nueva1234',
        ])->assertStatus(422);
    }

    public function test_new_code_replaces_the_previous_one_and_resend_waits_a_minute(): void
    {
        $this->owner();
        $first = $this->requestCode();

        // Antes de 60 s no se envía otro correo (respuesta igual)
        $this->postJson('/api/password/code', ['email' => 'dueno@ohana.test'])->assertOk();
        Mail::assertSentCount(1);

        $this->travel(61)->seconds();
        $this->postJson('/api/password/code', ['email' => 'dueno@ohana.test'])->assertOk();
        Mail::assertSentCount(2);
        $second = null;
        Mail::assertSent(PasswordResetCodeMail::class, function ($m) use (&$second) { $second = $m->code; return true; });

        if ($first !== $second) {
            $this->postJson('/api/password/verify', ['email' => 'dueno@ohana.test', 'code' => $first])->assertStatus(422);
        }
        $this->postJson('/api/password/verify', ['email' => 'dueno@ohana.test', 'code' => $second])->assertOk();
    }

    public function test_validation_messages(): void
    {
        $this->postJson('/api/password/code', ['email' => 'no-es-correo'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/password/verify', ['email' => 'a@b.co', 'code' => '12ab'])->assertStatus(422)->assertJsonPath('errors.code.0', 'El código tiene 6 dígitos.');
        $this->postJson('/api/password/reset', ['email' => 'a@b.co', 'reset_token' => str_repeat('a', 64), 'password' => '12345', 'password_confirmation' => '54321'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_requesting_codes_is_rate_limited(): void
    {
        $this->owner();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/password/code', ['email' => 'dueno@ohana.test'])->assertOk();
        }
        $this->postJson('/api/password/code', ['email' => 'dueno@ohana.test'])
            ->assertStatus(429)->assertJsonPath('message', 'Demasiados intentos. Espera unos minutos y vuelve a intentar.');
    }

    public function test_a_mail_failure_is_logged_and_does_not_reveal_anything(): void
    {
        $user = $this->owner();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Resend caído'));
        $this->postJson('/api/password/code', ['email' => 'dueno@ohana.test'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset_mail_failed', 'user_id' => $user->id]);
    }
}
