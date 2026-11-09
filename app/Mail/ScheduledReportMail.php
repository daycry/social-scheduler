<?php

namespace App\Mail;

use App\Models\Report;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Informe programado (T-21): el PDF adjunto (si cabe) y un enlace firmado que caduca a las `reports.mail_link_hours` horas. */
class ScheduledReportMail extends Mailable
{
    public function __construct(public readonly Report $report, public readonly string $brandName, public readonly string $url, public readonly int $hours) {}

    public function envelope(): Envelope
    {
        // Sin saltos de linea: el nombre de la marca es del usuario y va en una cabecera.
        $brand = trim((string) preg_replace('/[\r\n]+/', ' ', $this->brandName));

        return new Envelope(subject: sprintf('Informe de %s (%s - %s)', $brand, $this->report->from->format('Y-m-d'), $this->report->to->format('Y-m-d')));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.scheduled-report', with: [
            'brandName' => $this->brandName, 'from' => $this->report->from->format('Y-m-d'), 'to' => $this->report->to->format('Y-m-d'),
            'url' => $this->url, 'hours' => $this->hours, 'attached' => $this->attaches(),
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
