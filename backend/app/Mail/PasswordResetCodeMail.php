<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Correo con el código de 6 dígitos para cambiar la contraseña. El código no va en el asunto (se ve en notificaciones). */
class PasswordResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $code, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu código para cambiar la contraseña de Ohana POS');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-reset-code', text: 'mail.password-reset-code-text');
    }
}
