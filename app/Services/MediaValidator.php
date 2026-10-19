<?php

namespace App\Services;

use App\Enums\ContentType;
use App\Models\MediaAsset;
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

        $errors = array_merge($errors, $this->validateText($spec, $label, $text));

        $count = count($media);
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
    private function validateText(array $spec, string $label, ?string $text): array
    {
        $length = mb_strlen(trim((string) $text));

        if (($spec['text']['required'] ?? false) && $length === 0) {
            return [ucfirst($label).' necesita texto.'];
        }

        if (mb_strlen((string) $text) > $spec['text']['max']) {
            return ['El texto tiene '.mb_strlen((string) $text)." caracteres y $label admite como maximo ".$spec['text']['max'].'.'];
        }

        return [];
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

        if (isset($rules['max_bytes']) && ($asset->size ?? $asset->declared_size) > $rules['max_bytes']) {
            $e[] = "$noun pesa ".$this->mb($asset->size ?? $asset->declared_size)." y $label admite como maximo ".$this->mb($rules['max_bytes']).'.';
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
