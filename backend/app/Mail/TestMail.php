<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Correo de prueba de `php artisan ohana:probar-correo` (comprueba la configuración de envío). */
class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Prueba de correo de Ohana POS');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Si lees esto, Ohana POS ya puede enviar correos (por ejemplo, los códigos para recuperar la contraseña).</p>');
    }
}
