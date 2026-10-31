<?php

namespace App\Console\Commands;

use App\Mail\TestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Envía un correo de prueba para comprobar la configuración (Resend) antes de depender de ella. */
class ProbarCorreo extends Command
{
    protected $signature = 'ohana:probar-correo {correo : A quién enviar la prueba}';

    protected $description = 'Envía un correo de prueba con la configuración actual (MAIL_MAILER, remitente y clave de Resend).';

    public function handle(): int
    {
        $mailer = config('mail.default');
        $this->line("Mailer: {$mailer} · Remitente: " . config('mail.from.address'));
        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER=log: el correo no sale, se escribe en storage/logs/laravel.log.');
        }
        try {
            Mail::to($this->argument('correo'))->send(new TestMail());
        } catch (Throwable $e) {
            $this->error('No se pudo enviar: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->info('Enviado. Revisa la bandeja de entrada (y Spam).');

        return self::SUCCESS;
    }
}
