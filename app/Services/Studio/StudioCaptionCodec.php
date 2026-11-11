<?php

namespace App\Services\Studio;

/** Restricted plain SRT/WebVTT: no markup, cue settings, CSS, URLs or process execution. */
class StudioCaptionCodec
{
    public function parse(string $text, string $format): array
    {
        $this->format($format);
        if (strlen($text) > 512 * 1024 || ! mb_check_encoding($text, 'UTF-8')) {
            StudioCaptionData::fail('El archivo supera512KiB o no es UTF-8 válido.');
        }
        $text = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $text));
        $warnings = [];
        if ($format === 'vtt') {
            if (! preg_match('/^WEBVTT(?:[^\S\n][^\n]*)?\n(?:\n|$)/u', $text, $header)) {
                StudioCaptionData::fail('Falta la cabecera WEBVTT seguida de una línea vacía.');
            }
            $text = substr($text, strlen($header[0]));
        }
        $blocks = preg_split('/\n(?:[\t ]*\n)+/', trim($text, "\n"));
        $cues = [];
        foreach ($blocks as $block) {
            if ($block === '') {
                continue;
            }
            $number = count($cues) + 1;
            $lines = explode("\n", $block);
            if ($format === 'vtt' && preg_match('/^NOTE(?:[\t ]|$)/', $lines[0])) {
                $warnings[] = 'Se omitió un bloque NOTE de WebVTT.';

                continue;
            }
            $line = array_shift($lines);
            if (! str_contains($line, '-->')) {
                if (($format === 'srt' && ! preg_match('/^\d+$/D', $line)) || preg_match('/^(?:STYLE|REGION)(?:\s|$)/', $line)) {
                    StudioCaptionData::fail('Cue '.$number.': índice o bloque no admitido.');
                }
                $line = array_shift($lines) ?? '';
            }
            if (! preg_match('/^(\S+)\h+-->\h+(\S+)$/uD', $line, $times)) {
                StudioCaptionData::fail('Cue '.$number.': intervalo inválido o ajustes WebVTT no admitidos.');
            }
            $body = implode("\n", $lines);
            if (preg_match('/<[^\n]*>|&(?:[A-Za-z][A-Za-z0-9]*|#\d+|#x[0-9a-f]+);/iu', $body)) {
                StudioCaptionData::fail('Cue '.$number.': etiquetas o entidades no admitidas; usa texto plano.');
            }
            $cues[] = ['id' => 'cue_'.$number, 'start' => $this->time($times[1], $format, $number), 'end' => $this->time($times[2], $format, $number), 'text' => $body];
            if (count($cues) > 5000) {
                StudioCaptionData::fail('Se admiten hasta5000cues.');
            }
        }

        // Validate independently; the document service also applies its actual duration atomically.
        return ['cues' => app(StudioCaptionData::class)->validate(['style' => [], 'cues' => $cues], PHP_FLOAT_MAX)['cues'], 'warnings' => array_values(array_unique($warnings))];
    }

    public function encode(array $cues, string $format): string
    {
        $this->format($format);
        $cues = app(StudioCaptionData::class)->validate(['style' => [], 'cues' => $cues], PHP_FLOAT_MAX)['cues'];
        $output = $format === 'vtt' ? "WEBVTT\n\n" : '';
        foreach ($cues as $index => $cue) {
            if ($cue['end'] * 1000 > 9007199254740991) {
                StudioCaptionData::fail('Tiempo demasiado grande para exportar.');
            }
            $start = (int) round($cue['start'] * 1000);
            $end = (int) round($cue['end'] * 1000);
            if ($start >= $end) {
                StudioCaptionData::fail('El cue '.$cue['id'].' colapsa al exportar milisegundos.');
            }
            if (preg_match('/(?:\A[\t ]*\n|\n[\t ]*\z|\n[\t ]*\n)/', $cue['text'])) {
                StudioCaptionData::fail('El cue '.$cue['id'].' contiene líneas vacías que SRT/WebVTT no conserva. Edita esas líneas para descargar; el texto guardado no cambia.');
            }
            if (preg_match('/<[^\n]*>|&(?:[A-Za-z][A-Za-z0-9]*|#\d+|#x[0-9a-f]+);/iu', $cue['text'])) {
                StudioCaptionData::fail('El cue '.$cue['id'].' contiene sintaxis que el formato interpreta como marcado.');
            }
            $output .= ($index + 1)."\n".$this->stamp($start, $format).' --> '.$this->stamp($end, $format)."\n".$cue['text']."\n\n";
        }

        return $output;
    }

    private function format(string $format): void
    {
        if (! in_array($format, ['srt', 'vtt'], true)) {
            StudioCaptionData::fail('Formato admitido: srt o vtt.');
        }
    }

    private function time(string $value, string $format, int $number): float
    {
        $pattern = $format === 'srt' ? '/^(\d{2,}):([0-5]\d):([0-5]\d),(\d{3})$/D' : '/^(?:(\d{2,}):)?([0-5]\d):([0-5]\d)\.(\d{3})$/D';
        if (! preg_match($pattern, $value, $m)) {
            StudioCaptionData::fail('Cue '.$number.': tiempo inválido.');
        }
        $time = (float) ($m[1] ?: 0) * 3600 + (int) $m[2] * 60 + (int) $m[3] + (int) $m[4] / 1000;
        if (! is_finite($time)) {
            StudioCaptionData::fail('Cue '.$number.': tiempo no finito.');
        }

        return $time;
    }

    private function stamp(int $milliseconds, string $format): string
    {
        return sprintf('%02d:%02d:%02d%s%03d', intdiv($milliseconds, 3600000), intdiv($milliseconds, 60000) % 60, intdiv($milliseconds, 1000) % 60, $format === 'srt' ? ',' : '.', $milliseconds % 1000);
    }
}
