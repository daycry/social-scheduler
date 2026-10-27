<?php

namespace App\Services;

use App\Enums\ContentType;
use App\Models\MediaAsset;
use App\Support\TextLength;
use InvalidArgumentException;

/**
 * Valida texto y media contra las reglas por red/tipo de `config/media_specs.php`. Devuelve mensajes
 * concretos del limite incumplido (CA-05). No transcodifica (fuera de alcance): rechaza.
 */
class MediaValidator
{
    /**
     * @param  iterable<MediaAsset>  $media  En el orden de publicacion.
     * @return list<string> Vacia si todo es valido.
     */
    public function validate(ContentType $type, iterable $media, ?string $text = null): array
    {
        $media = is_array($media) ? array_values($media) : iterator_to_array($media, false);
        $firstKind = ($media[0] ?? null)?->kind;
        $spec = $this->spec($type, $firstKind);
        $label = $spec['label'];
        $errors = [];

        $count = count($media);
        $errors = array_merge($errors, $this->validateText($spec, $label, $text, $count));

        $min = $spec['media']['min'];
        $max = $spec['media']['max'];

        if ($max === 0 && $count > 0) {
            $errors[] = ucfirst($label).' no admite imagenes ni videos.';
        } elseif ($count < $min) {
            $errors[] = $min === 1
                ? ucfirst($label).' necesita al menos un archivo de media.'
                : ucfirst($label)." necesita al menos $min archivos (hay $count).";
        } elseif ($count > $max) {
            $errors[] = ucfirst($label)." admite como maximo $max archivo".($max === 1 ? '' : 's')." (hay $count).";
        }

        foreach ($media as $i => $asset) {
            $prefix = $count > 1 ? 'Archivo '.($i + 1).' ("'.$asset->original_name.'"): ' : '';

            if (! $asset->isReady()) {
                $errors[] = $prefix.'el archivo aun no esta subido o no es valido.';

                continue;
            }

            if (! in_array($asset->kind, $spec['media']['kinds'], true)) {
                $kinds = implode(' o ', array_map(fn ($k) => $k === 'video' ? 'video' : 'imagen', $spec['media']['kinds']));
                $errors[] = $prefix.ucfirst($label)." solo admite $kinds.";

                continue;
            }

            foreach ($this->validateAsset($spec[$asset->kind] ?? [], $asset, $label) as $message) {
                $errors[] = $prefix.$message;
            }
        }

        return $errors;
    }

    /**
     * Recomendaciones (`recommended` en media_specs) que el contenido no cumple: avisos, nunca errores.
     *
     * @param  iterable<MediaAsset>  $media
     * @return list<string>
     */
    public function warnings(ContentType $type, iterable $media): array
    {
        $media = is_array($media) ? array_values($media) : iterator_to_array($media, false);
        $spec = $this->spec($type, ($media[0] ?? null)?->kind);
        $out = [];

        foreach ($media as $asset) {
            $rules = $spec['recommended'][$asset->kind] ?? [];
            $noun = $asset->isVideo() ? 'El video' : 'La imagen';

            foreach ([['width', 'ancho'], ['height', 'alto']] as [$field, $word]) {
                $min = $rules["min_$field"] ?? null;
                if ($min !== null && $asset->{$field} !== null && $asset->{$field} < $min) {
                    $out[] = "$noun mide {$asset->{$field}} px de $word; se recomiendan al menos $min px en {$spec['label']} (se publicara, pero puede verse con menos calidad).";
                }
            }
        }

        return $out;
    }

    /** Lanza `InvalidArgumentException` con todos los motivos si algo no cumple. */
    public function assertValid(ContentType $type, iterable $media, ?string $text = null): void
    {
        $errors = $this->validate($type, $media, $text);

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
    }

    /** @return array<string, mixed> */
    public function spec(ContentType $type, ?string $firstMediaKind = null): array
    {
        $key = $type->specKey($firstMediaKind);
        $spec = config("media_specs.types.$key");

        if (! is_array($spec)) {
            throw new InvalidArgumentException("No hay reglas de media para $key.");
        }

        return $spec;
    }

    /** @return list<string> */
    private function validateText(array $spec, string $label, ?string $text, int $mediaCount = 0): array
    {
        // El modo de conteo es de la red: caracteres (por defecto), grafemas (Bluesky) o la regla de Threads.
        $length = TextLength::count($text, $spec['text']['count'] ?? TextLength::CHARS);

        if (($spec['text']['required'] ?? false) && $length === 0) {
            return [ucfirst($label).' necesita texto.'];
        }

        if (($spec['text']['required_without_media'] ?? false) && $length === 0 && $mediaCount === 0) {
            return [ucfirst($label).' necesita texto o al menos una imagen.'];
        }

        if ($length > $spec['text']['max']) {
            return ["El texto tiene $length caracteres y $label admite como maximo ".$spec['text']['max'].'.'];
        }

        $maxBytes = $spec['text']['max_bytes'] ?? null;
        if ($maxBytes !== null && strlen(trim((string) $text)) > $maxBytes) {
            return ['El texto pesa '.strlen(trim((string) $text))." bytes y $label admite como maximo $maxBytes bytes."];
        }

        $maxHashtags = $spec['text']['max_hashtags'] ?? null;
        if ($maxHashtags !== null && ($hashtags = self::countHashtags((string) $text)) > $maxHashtags) {
            return ["El texto tiene $hashtags hashtags y $label admite como maximo $maxHashtags."];
        }

        return [];
    }

    /**
     * Hashtags del texto: un `#` seguido de letras, numeros o guion bajo (Unicode) y no precedido de letra, numero, guion bajo o `/`
     * (como Instagram: `a#b` y los `#` de una URL no cuentan). Es la MISMA regla que `countHashtags`
     * de `resources/js/Composables/textCount.js`: si cambia una, cambia la otra.
     */
    public static function countHashtags(string $text): int
    {
        return (int) preg_match_all('/(?<![\p{L}\p{N}_\/])#[\p{L}\p{N}_]+/u', $text);
    }

    /** @return list<string> */
    private function validateAsset(array $rules, MediaAsset $asset, string $label): array
    {
        $e = [];
        $noun = $asset->isVideo() ? 'El video' : 'La imagen';
        $needsMeta = fn (string $field) => $asset->{$field} === null;

        if (isset($rules['mimes']) && ! in_array($asset->mime, $rules['mimes'], true)) {
            $e[] = "$noun es ".$this->formatName($asset->mime).' y '.$label.' solo admite '.implode(', ', array_map($this->formatName(...), $rules['mimes'])).'.';
        }

        // Tope fijo (`max_bytes`) o de esta instalacion (`max_bytes_config`, p. ej. el video de Bluesky), con mensaje propio opcional.
        $maxBytes = isset($rules['max_bytes_config']) ? (int) config($rules['max_bytes_config']) : ($rules['max_bytes'] ?? null);
        if ($maxBytes !== null && ($asset->size ?? $asset->declared_size) > $maxBytes) {
            $e[] = isset($rules['too_big_message'])
                ? strtr($rules['too_big_message'], [':max' => $this->mb($maxBytes), ':size' => $this->mb($asset->size ?? $asset->declared_size)])
                : "$noun pesa ".$this->mb($asset->size ?? $asset->declared_size)." y $label admite como maximo ".$this->mb($maxBytes).'.';
        }

        if (isset($rules['min_duration']) || isset($rules['max_duration'])) {
            if ($needsMeta('duration')) {
                $e[] = 'No se pudo leer la duracion del video.';
            } else {
                if (isset($rules['max_duration']) && $asset->duration > $rules['max_duration']) {
                    $e[] = 'El video dura '.$this->seconds($asset->duration)." y $label admite como maximo ".$this->seconds($rules['max_duration']).'.';
                }
                if (isset($rules['min_duration']) && $asset->duration < $rules['min_duration']) {
                    $e[] = 'El video dura '.$this->seconds($asset->duration)." y $label necesita al menos ".$this->seconds($rules['min_duration']).'.';
                }
            }
        }

        if (isset($rules['min_fps']) || isset($rules['max_fps'])) {
            if ($needsMeta('fps')) {
                $e[] = 'No se pudo leer la tasa de fotogramas del video.';
            } else {
                if (isset($rules['min_fps']) && $asset->fps < $rules['min_fps']) {
                    $e[] = 'El video va a '.$this->num($asset->fps)." fps y $label necesita al menos ".$rules['min_fps'].' fps.';
                }
                if (isset($rules['max_fps']) && $asset->fps > $rules['max_fps']) {
                    $e[] = 'El video va a '.$this->num($asset->fps)." fps y $label admite como maximo ".$rules['max_fps'].' fps.';
                }
            }
        }

        foreach ([['width', 'ancho'], ['height', 'alto']] as [$field, $word]) {
            foreach (['min', 'max'] as $bound) {
                $limit = $rules["{$bound}_$field"] ?? null;
                if ($limit === null || $asset->{$field} === null) {
                    continue;
                }
                if ($bound === 'min' && $asset->{$field} < $limit) {
                    $e[] = "$noun mide {$asset->{$field}} px de $word y $label necesita al menos $limit px.";
                }
                if ($bound === 'max' && $asset->{$field} > $limit) {
                    $e[] = "$noun mide {$asset->{$field}} px de $word y $label admite como maximo $limit px.";
                }
            }
        }

        if (isset($rules['aspect']) && $asset->width && $asset->height) {
            $ratio = $asset->width / $asset->height;
            if ($ratio < $rules['aspect']['min'] - 0.005 || $ratio > $rules['aspect']['max'] + 0.005) {
                $e[] = "$noun tiene proporcion ".$this->num($ratio)." (ancho/alto) y $label exige entre ".$this->num($rules['aspect']['min']).' y '.$this->num($rules['aspect']['max']).'.';
            }
        }

        if (isset($rules['codecs']) && $asset->codec !== null && ! in_array($asset->codec, $rules['codecs'], true)) {
            $e[] = "El video usa el codec {$asset->codec} y $label admite ".implode(', ', $rules['codecs']).'.';
        }

        if (isset($rules['audio_codecs']) && $asset->audio_codec !== null && ! in_array($asset->audio_codec, $rules['audio_codecs'], true)) {
            $e[] = "El audio usa el codec {$asset->audio_codec} y $label admite ".implode(', ', $rules['audio_codecs']).'.';
        }

        return $e;
    }

    private function formatName(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            'image/webp' => 'WebP',
            'video/mp4' => 'MP4',
            'video/quicktime' => 'MOV',
            default => $mime,
        };
    }

    private function mb(int|float $bytes): string
    {
        return $bytes >= 1024 ** 3 ? $this->num($bytes / 1024 ** 3).' GB' : $this->num($bytes / 1024 ** 2).' MB';
    }

    private function seconds(int|float $s): string
    {
        if ($s >= 120) {
            $m = intdiv((int) round($s), 60);
            $rest = (int) round($s) % 60;

            return $rest === 0 ? "$m min" : "$m min $rest s";
        }

        return $this->num($s).' s';
    }

    private function num(int|float $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    }
}
