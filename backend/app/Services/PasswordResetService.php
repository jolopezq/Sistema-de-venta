<?php

namespace App\Services;

use App\Mail\PasswordChangedMail;
use App\Mail\PasswordResetCodeMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recuperar la contraseña con un código de 6 dígitos enviado al correo del usuario.
 *
 * 1. request(): si el correo pertenece a un usuario activo, descarta los códigos anteriores y envía uno nuevo
 *    (vence en 15 minutos). La respuesta al navegador es siempre la misma, exista o no el correo.
 * 2. verify(): compara el código (máximo 5 intentos por código) y entrega un permiso de un solo uso que vence en
 *    10 minutos. El mensaje de error es el mismo para código equivocado, vencido o inexistente.
 * 3. reset(): con ese permiso cambia la contraseña, cierra todas las sesiones abiertas del usuario y le avisa por
 *    correo del cambio.
 *
 * Solo se guardan huellas HMAC-SHA256 (con APP_KEY) del código y del permiso.
 */
class PasswordResetService
{
    public const CODE_MINUTES = 15;
    public const MAX_ATTEMPTS = 5;
    public const RESET_MINUTES = 10;
    /** Un correo nuevo como mucho cada 60 s por usuario (evita llenar la bandeja de alguien). */
    public const RESEND_SECONDS = 60;

    public function request(string $email, ?string $ip = null): void
    {
        $user = $this->findUser($email);
        if (! $user) {
            return;
        }

        $last = PasswordResetCode::where('user_id', $user->id)->latest('id')->first();
        if ($last && $last->created_at && $last->created_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $code, $ip) {
            PasswordResetCode::where('user_id', $user->id)->open()->update(['used_at' => now()]);
            PasswordResetCode::create([
                'user_id' => $user->id,
                'code_hash' => $this->hash($code),
                'expires_at' => now()->addMinutes(self::CODE_MINUTES),
                'ip' => $ip,
            ]);
        });

        try {
            Mail::to($user->email)->send(new PasswordResetCodeMail($user, $code, self::CODE_MINUTES));
            AuditLogger::log('password_reset_requested', 'users', "Código de recuperación enviado a {$user->email}.", $user->id);
        } catch (Throwable $e) {
            Log::error('No se pudo enviar el código de recuperación de contraseña', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            AuditLogger::log('password_reset_mail_failed', 'users', 'No se pudo enviar el código de recuperación: ' . Str::limit($e->getMessage(), 180), $user->id);
        }
    }

    /**
     * @return string|null el permiso para poner la contraseña nueva, o null si el código no sirve
     */
    public function verify(string $email, string $code): ?string
    {
        $user = $this->findUser($email);
        if (! $user) {
            return null;
        }

        $record = PasswordResetCode::where('user_id', $user->id)->open()->whereNull('verified_at')->latest('id')->first();
        if (! $record || $record->expires_at->isPast() || $record->attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        if (! hash_equals($record->code_hash, $this->hash($code))) {
            $record->attempts++;
            if ($record->attempts >= self::MAX_ATTEMPTS) {
                $record->used_at = now();
                AuditLogger::log('password_reset_code_locked', 'users', 'Código de recuperación anulado tras ' . self::MAX_ATTEMPTS . ' intentos fallidos.', $user->id);
            }
            $record->save();

            return null;
        }

        $token = Str::random(64);
        $record->update([
            'verified_at' => now(),
            'reset_token_hash' => $this->hash($token),
            'reset_expires_at' => now()->addMinutes(self::RESET_MINUTES),
        ]);

        return $token;
    }

    /** @return bool false si el permiso no existe, venció o ya se usó */
    public function reset(string $email, string $token, string $password): bool
    {
        $user = $this->findUser($email);
        if (! $user) {
            return false;
        }

        $record = PasswordResetCode::where('user_id', $user->id)->open()
            ->whereNotNull('verified_at')->where('reset_token_hash', $this->hash($token))->latest('id')->first();
        if (! $record || ! $record->reset_expires_at || $record->reset_expires_at->isPast()) {
            return false;
        }

        DB::transaction(function () use ($user, $record, $password) {
            $user->forceFill(['password' => $password])->save();
            $record->update(['used_at' => now()]);
            PasswordResetCode::where('user_id', $user->id)->open()->update(['used_at' => now()]);
            // Quien tenía la sesión abierta con la contraseña anterior tiene que volver a entrar
            $user->tokens()->delete();
        });

        AuditLogger::log('password_reset_completed', 'users', 'Contraseña cambiada con código enviado al correo; se cerraron sus sesiones abiertas.', $user->id);

        try {
            Mail::to($user->email)->send(new PasswordChangedMail($user));
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar el aviso de contraseña cambiada', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return true;
    }

    private function findUser(string $email): ?User
    {
        $email = mb_strtolower(trim($email));

        return $email === '' ? null : User::whereRaw('LOWER(email) = ?', [$email])->first();
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
