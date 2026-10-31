<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** El correo sale por la API HTTPS de Resend con la clave, el remitente y las dos versiones (HTML y texto). */
class ResendApiTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'resend-api',
            'mail.mailers.resend-api.key' => 're_prueba_123',
            'mail.from.address' => 'no-responder@avisos.ohana.test',
            'mail.from.name' => 'Ohana Açaí',
        ]);
    }

    public function test_posts_to_resend_with_bearer_key_and_both_bodies(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'abc-123'], 200)]);
        $user = User::factory()->create(['email' => 'cajera@ohana.test', 'name' => 'Rox']);

        Mail::to($user->email)->send(new PasswordResetCodeMail($user, '042917', 15));

        Http::assertSent(function (Request $r) {
            $d = $r->data();
            return $r->url() === 'https://api.resend.com/emails'
                && $r->hasHeader('Authorization', 'Bearer re_prueba_123')
                && $r->hasHeader('Idempotency-Key')
                && $d['from'] === 'Ohana Açaí <no-responder@avisos.ohana.test>'
                && $d['to'] === ['cajera@ohana.test']
                && $d['subject'] === 'Tu código para cambiar la contraseña de Ohana POS'
                && str_contains($d['html'], '042917') && str_contains($d['text'], '042917')
                && ! str_contains($d['subject'], '042917')
                && ! isset($d['bcc']);
        });
    }

    public function test_a_rejection_raises_a_clear_error(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['message' => 'The domain is not verified'], 403)]);
        $this->expectExceptionMessage('Resend rechazó el correo (403): The domain is not verified');
        Mail::to('a@ohana.test')->send(new \App\Mail\TestMail());
    }

    public function test_missing_key_is_explained(): void
    {
        config(['mail.mailers.resend-api.key' => null, 'services.resend.key' => null]);
        Mail::purge('resend-api');
        $this->expectExceptionMessage('Falta RESEND_API_KEY');
        Mail::to('a@ohana.test')->send(new \App\Mail\TestMail());
    }
}
