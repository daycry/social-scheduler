<?php

namespace App\Services\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;

/**
 * Renderiza el informe a PDF (T-39). Driver por defecto: DomPDF (PHP puro, sin Chromium ni Node en el VPS);
 * los graficos son barras HTML/CSS. Otro driver (p. ej. Chromium) se enchufa aqui sin tocar ReportService.
 */
class PdfRenderer
{
    public const SECTIONS = ['cover', 'summary', 'networks', 'top_posts', 'notes'];

    /** @param  array<string, mixed>  $data */
    public function render(array $data): string
    {
        $driver = config('reports.driver', 'dompdf');

        if ($driver !== 'dompdf') {
            throw new RuntimeException("Driver de PDF no soportado: {$driver}");
        }

        $sections = array_values(array_intersect(self::SECTIONS, (array) config('reports.sections')));

        $data = $this->withoutEmoji($data);

        return Pdf::loadView('reports.brand', ['data' => $data, 'branding' => $this->branding(), 'sections' => $sections])
            ->setPaper('a4')
            ->output();
    }

    /**
     * DejaVu Sans (la fuente embebida) no trae emojis ni selectores/uniones: saldrian como cuadrados. Se eliminan de
     * los textos del PDF (el CSV los conserva). Documentado en ADR-002.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withoutEmoji(array $data): array
    {
        array_walk_recursive($data, function (&$v) {
            if (is_string($v)) {
                $v = trim((string) preg_replace('/[\x{1F000}-\x{1FFFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u', '', $v));
            }
        });

        return $data;
    }

    /** @return array{color: string, logo: ?string, footer: string} */
    private function branding(): array
    {
        $color = (string) config('reports.branding.color');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#4f46e5';

        return ['color' => $color, 'logo' => $this->logo(), 'footer' => (string) config('reports.branding.footer')];
    }

    /** Logo local como data URI (nunca se abren URLs remotas; solo PNG/JPG pequenos). */
    private function logo(): ?string
    {
        $path = config('reports.branding.logo');

        if (! $path || ! is_file($path) || filesize($path) > 1_000_000) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };

        return $mime ? 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path)) : null;
    }
}
