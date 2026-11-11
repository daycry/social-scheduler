<?php

namespace App\Mail;

use App\Models\Report;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** Informe programado (T-21): el PDF adjunto (si cabe) y un enlace firmado que caduca a las `reports.mail_link_hours` horas. */
class ScheduledReportMail extends Mailable
{
    public function __construct(public readonly Report $report, public readonly string $brandName, public readonly string $url, public readonly int $hours, public readonly string $ownerName = '', public readonly string $ownerEmail = '', public readonly string $unsubscribeUrl = '') {}

    public function envelope(): Envelope
    {
        // Sin saltos de linea: el nombre de la marca es del usuario y va en una cabecera.
        $brand = trim((string) preg_replace('/[\r\n]+/', ' ', $this->brandName));

        $replyTo = $this->ownerEmail !== '' ? [new Address($this->ownerEmail, trim((string) preg_replace('/[\r\n]+/', ' ', $this->ownerName)))] : [];

        return new Envelope(replyTo: $replyTo, subject: sprintf('Informe de %s (%s - %s)', $brand, $this->report->from->format('Y-m-d'), $this->report->to->format('Y-m-d')));
    }

    /** Baja de un clic (RFC 8058): el enlace va firmado y solo da de baja a ESTE destinatario de ESTA programacion. */
    public function headers(): Headers
    {
        return new Headers(text: $this->unsubscribeUrl === '' ? [] : ['List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.scheduled-report', with: [
            'brandName' => $this->brandName, 'from' => $this->report->from->format('Y-m-d'), 'to' => $this->report->to->format('Y-m-d'),
            'url' => $this->url, 'hours' => $this->hours, 'attached' => $this->attaches(),
            'ownerName' => $this->ownerName, 'unsubscribeUrl' => $this->unsubscribeUrl,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if (! $this->attaches()) {
            return [];
        }

        return [Attachment::fromStorageDisk((string) config('filesystems.media'), (string) $this->report->path)->as($this->report->downloadName($this->brandName))->withMime('application/pdf')];
    }

    private function attaches(): bool
    {
        return $this->report->isReady() && (int) $this->report->size <= (int) config('reports.mail_attach_max_bytes', 10_000_000);
    }
}
