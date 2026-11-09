<?php

namespace App\Services\Studio;

use Illuminate\Validation\ValidationException;

/** Pure caption contract; permissions and persistence are applied by StudioTimeline/StudioCaptions. */
class StudioCaptionData
{
    /** Imported file IDs are generated data; explicit mutation IDs still use strict document validation. */
    public function reservedIds(array $document, bool $includeCaptions): array
    {
        $ids = [];
        $walk = function (array $elements) use (&$walk, &$ids): void {
            foreach ($elements as $element) {
                $ids[$element['id']] = true;
                if (($element['type'] ?? null) === 'group') {
                    $walk($element['elements']);
                }
            }
        };
        foreach ($document['pages'] as $page) {
            $ids[$page['id']] = true;
            $walk($page['elements']);
        }
        foreach ($document['timeline']['audio_tracks'] ?? [] as $track) {
            $ids[$track['id']] = true;
        }
        if ($includeCaptions) {
            foreach ($document['timeline']['captions']['cues'] ?? [] as $cue) {
                $ids[$cue['id']] = true;
            }
        }

        return $ids;
    }

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['captions' => ['Subtítulos: '.$message]]);
    }

    public static function keys(mixed $value, array $allowed, string $name): void
    {
        if (! is_array($value) || array_diff(array_keys($value), $allowed) !== []) {
            self::fail($name.' contiene propiedades no admitidas.');
        }
    }

    public static function number(mixed $value, float $min, float $max, string $name): float|int
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < $min || $value > $max) {
            self::fail($name.' fuera de rango.');
        }

        return $value;
    }

    public function style(mixed $style): array
    {
        self::keys($style, ['fontFamily', 'font_id', 'fontSize', 'fontWeight', 'fontStyle', 'fill', 'background', 'stroke', 'strokeWidth', 'textAlign', 'lineHeight', 'position', 'margin'], 'estilo');
        if (array_key_exists('fontFamily', $style) && ! StudioDocument::validFont($style['fontFamily'])) {
            self::fail('familia tipográfica inválida.');
        }
        if (array_key_exists('font_id', $style) && (! is_int($style['font_id']) || $style['font_id'] < 1 || $style['font_id'] > 9007199254740991)) {
            self::fail('font_id inválido.');
        }
        foreach (['fontSize' => [1, 1000], 'strokeWidth' => [0, 20], 'lineHeight' => [0.5, 5], 'margin' => [0, 0.25]] as $key => [$min, $max]) {
            if (array_key_exists($key, $style)) {
                self::number($style[$key], $min, $max, $key);
            }
        }
        foreach (['fontWeight' => ['normal', 'bold'], 'fontStyle' => ['normal', 'italic'], 'textAlign' => ['left', 'center', 'right'], 'position' => ['top', 'center', 'bottom']] as $key => $values) {
            if (array_key_exists($key, $style) && ! in_array($style[$key], $values, true)) {
                self::fail($key.' inválido.');
            }
        }
        foreach (['fill', 'background', 'stroke'] as $key) {
            if (array_key_exists($key, $style) && (! is_string($style[$key]) || preg_match('/^#[a-f0-9]{6}$/iD', $style[$key]) !== 1)) {
                self::fail($key.' debe ser hexadecimal.');
            }
        }

        // PHP decodes {} as []; a nonempty canonical style keeps the JSON object on reopening.
        return $style === [] ? ['fontFamily' => 'Arial'] : $style;
    }

    public function validate(mixed $captions, float $duration, array $reservedIds = []): array
    {
        self::keys($captions, ['style', 'cues'], 'pista');
        $style = $this->style($captions['style'] ?? null);
        $cues = $captions['cues'] ?? null;
        if (! is_array($cues) || ! array_is_list($cues) || count($cues) > 5000) {
            self::fail('se admiten hasta5000cues.');
        }
        $ids = array_fill_keys($reservedIds, true);
        $events = [];
        foreach ($cues as $cue) {
            self::keys($cue, ['id', 'start', 'end', 'text'], 'cue');
            $id = $cue['id'] ?? null;
            if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) !== 1 || isset($ids[$id])) {
                self::fail('ID inválido o repetido.');
            }
            $ids[$id] = true;
            $start = self::number($cue['start'] ?? null, 0, $duration, 'inicio');
            $end = self::number($cue['end'] ?? null, 0, $duration, 'fin');
            if ($start >= $end) {
                self::fail('el fin debe ser posterior al inicio.');
            }
            $text = $cue['text'] ?? null;
            if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || preg_match('/\S/u', $text) !== 1 || mb_strlen($text) > 500 || preg_match('/[\x00-\x08\x0b-\x1f\x7f-\x{009f}]/u', $text)) {
                self::fail('texto inválido o superior a500caracteres.');
            }
            $events[] = [$start, 1];
            $events[] = [$end, -1];
        }
        usort($events, fn ($a, $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        $active = 0;
        foreach ($events as [, $change]) {
            $active += $change;
            if ($active > 8) {
                self::fail('más de8cues simultáneos.');
            }
        }
        usort($cues, fn ($a, $b) => ($a['start'] <=> $b['start']) ?: (($a['end'] <=> $b['end']) ?: strcmp($a['id'], $b['id'])));

        return ['style' => $style, 'cues' => $cues];
    }
}
