<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Envío por la API HTTPS de Resend (POST https://api.resend.com/emails) con el cliente HTTP de Laravel.
 * No necesita paquetes nuevos (la laptop de caja no corre `composer install` al actualizarse) ni puertos SMTP
 * (el VPS puede tenerlos bloqueados). Se activa con MAIL_MAILER=resend-api y RESEND_API_KEY.
 */
class ResendApiTransport extends AbstractTransport
{
    public function __construct(private readonly string $key, private readonly string $endpoint = 'https://api.resend.com/emails')
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->key === '') {
            throw new TransportException('Falta RESEND_API_KEY en el .env.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $list = fn (array $addresses) => array_map(fn (Address $a) => $a->toString(), $addresses);
        $from = $email->getFrom()[0] ?? $message->getEnvelope()->getSender();

        $payload = array_filter([
            // «Nombre <correo>» sin comillas (Symfony entrecomilla los nombres con tildes)
            'from' => $from->getName() !== '' ? sprintf('%s <%s>', str_replace(['"', '<', '>'], '', $from->getName()), $from->getAddress()) : $from->getAddress(),
            'to' => $list($email->getTo()) ?: $list($message->getEnvelope()->getRecipients()),
            'cc' => $list($email->getCc()) ?: null,
            'bcc' => $list($email->getBcc()) ?: null,
            'subject' => (string) $email->getSubject(),
            'html' => $email->getHtmlBody(),
            'text' => $email->getTextBody(),
            'reply_to' => $list($email->getReplyTo()) ?: null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        try {
            $response = Http::withToken($this->key)
                ->acceptJson()
                ->timeout(15)
                ->withHeaders(['Idempotency-Key' => $message->getMessageId()])
                ->post($this->endpoint, $payload);
        } catch (\Throwable $e) {
            throw new TransportException('Resend no respondió: ' . $e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new TransportException(sprintf('Resend rechazó el correo (%d): %s', $response->status(), $response->json('message') ?? $response->body()));
        }

        if ($id = $response->json('id')) {
            $message->setMessageId($id);
        }
    }

    public function __toString(): string
    {
        return 'resend-api';
    }
}
