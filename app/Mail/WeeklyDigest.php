<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Correo del resumen semanal (T-86), en HTML y texto plano. Todos los enlaces son absolutos; la baja es un enlace firmado de un clic
 * (RFC 8058, cabeceras `List-Unsubscribe`) y se ofrece tambien el acceso a las preferencias. Los nombres de marca y el texto de la mejor
 * publicacion son del usuario: las plantillas los escapan y nunca los interpretan.
 */
class WeeklyDigest extends Mailable
{
    /**
     * @param  array{period: array{from: string, to: string, label: string}, brands: list<array<string, mixed>>, ai: ?string}  $digest
     */
    public function __construct(
        public readonly array $digest,
        public readonly string $unsubscribeUrl,
        public readonly string $preferencesUrl,
        public readonly string $dashboardUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu resumen semanal ('.$this->digest['period']['label'].')');
    }

    /** Baja de un clic (RFC 8058): el enlace va firmado y solo desactiva el resumen de ESTE usuario. */
    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    public function content(): Content
    {
        $with = [
            'period' => $this->digest['period']['label'], 'brands' => $this->digest['brands'], 'ai' => $this->digest['ai'],
            'unsubscribeUrl' => $this->unsubscribeUrl, 'preferencesUrl' => $this->preferencesUrl, 'dashboardUrl' => $this->dashboardUrl,
        ];

        return new Content(view: 'mail.weekly-digest', text: 'mail.weekly-digest-text', with: $with);
    }
}
