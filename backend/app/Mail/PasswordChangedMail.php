<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso de que la contraseña se cambió: si no fue la persona, sabe que alguien entró a su correo. */
class PasswordChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $when;

    public function __construct(public User $user)
    {
        $this->when = now()->timezone(config('app.timezone', 'America/La_Paz'))->format('d/m/Y \a \l\a\s H:i');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu contraseña de Ohana POS se cambió');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-changed', text: 'mail.password-changed-text');
    }
}
