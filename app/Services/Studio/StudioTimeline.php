<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\Studio\StudioAsset;
use App\Models\Studio\StudioFont;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Version 2 reuses version 1 geometry, ownership and font validation without discarding time data. */
class StudioTimeline
{
    private const FPS = [12, 15, 24, 25, 30, 50, 60];

    private const EPS = 1e-9;

    public function validate(User $user, Brand $brand, array $document, StudioDocument $spatial): array
    {
        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        $this->ensure($encoded !== false && strlen($encoded) <= 2 * 1024 * 1024, 'document', 'El documento temporal supera 2 MiB o contiene valores no finitos.');
        $base = $document;
        unset($base['timeline']);
        $base['schema_version'] = 1;
        if (is_array($base['pages'] ?? null)) {
            foreach ($base['pages'] as &$page) {
                if (! is_array($page)) {
                    continue;
                }
                unset($page['transition']);
                if (is_array($page['elements'] ?? null)) {
                    $page['elements'] = $this->strip($page['elements']);
                }
            }
            unset($page);
        }
        $spatial->validate($user, $brand, $base);
        // Same connector rule as the spatial validator (it returns a normalized copy; timing keys live only here).
        foreach ($document['pages'] as $i => $page) {
            $document['pages'][$i]['elements'] = StudioDocument::linkConnectors($page['elements']);
        }
        $timeline = $document['timeline'] ?? null;
        $this->keys($timeline, ['fps', 'audio_tracks', 'captions'], 'timeline');
        $fps = $timeline['fps'] ?? null;
        $this->ensure(is_int($fps) && in_array($fps, self::FPS, true), 'timeline.fps', 'FPS no admitidos.');
        $tracks = $timeline['audio_tracks'] ?? null;
        $this->ensure(is_array($tracks) && array_is_list($tracks) && count($tracks) <= 64, 'timeline.audio_tracks', 'Indica una lista de hasta 64 pistas.');
        $ids = [];
        $uses = [];
        $total = 0;
        $pages = $document['pages'];
        foreach ($pages as $i => $page) {
            $ids[$page['id']] = true;
            $duration = $page['duration'] ?? 5;
            $this->elements($page['elements'], $duration, $fps, "pages.$i.elements", $ids, $uses);
            $transition = 0;
            if (array_key_exists('transition', $page)) {
                $value = $page['transition'];
                $path = "pages.$i.transition";
                $this->keys($value, ['type', 'duration'], $path);
                $type = $value['type'] ?? null;
                $this->ensure(in_array($type, ['cut', 'fade', 'slide_left', 'slide_right'], true), $path.'.type', 'Transición no admitida.');
                $transition = $this->number($value['duration'] ?? null, 0, $duration / 2 + self::EPS, $path.'.duration');
                $this->ensure($type === 'cut' ? $transition == 0 : $i < count($pages) - 1 && $transition >= 1 / $fps - self::EPS && $transition <= ($pages[$i + 1]['duration'] ?? 5) / 2 + self::EPS,
                    $path, 'Duración o posición de transición inválida.');
            }
            $total += $duration - $transition;
        }
        foreach ($tracks as $i => $track) {
            $path = "timeline.audio_tracks.$i";
            $this->keys($track, ['id', 'asset_id', 'start', 'duration', 'trimStart', 'trimEnd', 'playbackRate', 'sourceOffset', 'loop', 'volume', 'muted', 'gainPoints'], $path);
            $id = $track['id'] ?? null;
            $this->ensure(is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) === 1 && ! isset($ids[$id]), $path.'.id', 'ID temporal inválido o repetido.');
            $ids[$id] = true;
            $asset = $track['asset_id'] ?? null;
            $this->ensure(is_int($asset) && $asset > 0 && $asset <= 9007199254740991, $path.'.asset_id', 'Recurso de audio inválido.');
            $start = $this->number($track['start'] ?? null, 0, $total, $path.'.start');
            $duration = $this->number($track['duration'] ?? null, 1 / $fps - self::EPS, $total + self::EPS, $path.'.duration');
            $this->ensure($start + $duration <= $total + self::EPS, $path, 'La pista supera el proyecto.');
            $this->mediaFields($track, $duration, $path);
            $this->number(array_key_exists('volume', $track) ? $track['volume'] : 1, 0, 1, $path.'.volume');
            $this->boolean($track, 'muted', $path);
            $uses[] = [$asset, $track, $path, true];
        }
        if (array_key_exists('captions', $timeline)) {
            $document['timeline']['captions'] = app(StudioCaptionData::class)->validate($timeline['captions'], $total, array_keys($ids));
            $style = $document['timeline']['captions']['style'];
            if (isset($style['font_id'])) {
                $font = StudioFont::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->where('status', 'ready')->find($style['font_id']);
                $this->ensure($font !== null && (! isset($style['fontFamily']) || $style['fontFamily'] === $font->family), 'timeline.captions.style.font_id', 'La fuente o su familia no está disponible en esta marca.');
            }
        }
        if ($uses !== []) {
            $links = StudioAsset::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)
                ->whereIn('media_asset_id', array_unique(array_column($uses, 0)))->with('media')->get()->keyBy('media_asset_id');
            foreach ($uses as [$id, $clip, $path, $audio]) {
                $asset = $links->get($id)?->media;
                $this->ensure($asset !== null && (int) $asset->user_id === (int) $user->id && $asset->isReady(), $path.'.asset_id', 'El recurso no está listo en esta marca.');
                $this->ensure($audio ? in_array($asset->kind, ['audio', 'video'], true) && filled($asset->audio_codec) : $asset->kind === 'video', $path.'.asset_id', 'El recurso no contiene la pista requerida.');
                $this->ensure($asset->duration !== null && is_finite((float) $asset->duration) && $asset->duration > 0, $path.'.asset_id', 'Falta la duración sondeada del recurso.');
                $start = $clip['trimStart'] ?? 0;
                $end = $clip['trimEnd'] ?? $asset->duration;
                $this->ensure($start < $end && $end <= $asset->duration + 0.01, $path.'.trimEnd', 'El recorte supera el origen.');
                $this->ensure(($clip['sourceOffset'] ?? 0) <= $end - $start + self::EPS, $path.'.sourceOffset', 'El offset supera el intervalo recortado.');
            }
        }

        return $document;
    }

    private function strip(array $elements): array
    {
        foreach ($elements as &$element) {
            if (! is_array($element)) {
                continue;
            }
            unset($element['timing']);
            if (($element['type'] ?? null) === 'video') {
                unset($element['playbackRate'], $element['sourceOffset'], $element['loop'], $element['gainPoints']);
            }
            if (($element['type'] ?? null) === 'group' && is_array($element['elements'] ?? null)) {
                $element['elements'] = $this->strip($element['elements']);
            }
        }

        return $elements;
    }

    private function elements(array $elements, float $duration, int $fps, string $path, array &$ids, array &$uses): void
    {
        foreach ($elements as $i => $element) {
            $p = "$path.$i";
            $ids[$element['id']] = true;
            $length = $duration;
            if (array_key_exists('timing', $element)) {
                $this->keys($element['timing'], ['start', 'duration'], $p.'.timing');
                $start = $this->number($element['timing']['start'] ?? null, 0, $duration, $p.'.timing.start');
                $length = $this->number($element['timing']['duration'] ?? null, 0, $duration + self::EPS, $p.'.timing.duration');
                $this->ensure($length > 0 && $start + $length > $start && $start + $length <= $duration + self::EPS, $p.'.timing', 'La ventana debe ser positiva y quedar dentro de la página.');
            }
            if ($element['type'] === 'video') {
                $this->mediaFields($element, $length, $p);
                $uses[] = [$element['asset_id'], $element, $p, false];
            }
            if ($element['type'] === 'group') {
                $this->elements($element['elements'], $duration, $fps, $p.'.elements', $ids, $uses);
            }
        }
    }

    private function mediaFields(array $clip, float $duration, string $path): void
    {
        $this->number(array_key_exists('playbackRate', $clip) ? $clip['playbackRate'] : 1, 0.25, 4, $path.'.playbackRate');
        $this->number(array_key_exists('sourceOffset', $clip) ? $clip['sourceOffset'] : 0, 0, 86400, $path.'.sourceOffset');
        $this->number(array_key_exists('trimStart', $clip) ? $clip['trimStart'] : 0, 0, 86400, $path.'.trimStart');
        if (($clip['trimEnd'] ?? null) !== null) {
            $this->number($clip['trimEnd'], 0.001, 86400, $path.'.trimEnd');
        }
        $this->boolean($clip, 'loop', $path);
        if (! array_key_exists('gainPoints', $clip)) {
            return;
        }
        $points = $clip['gainPoints'];
        $this->ensure(is_array($points) && array_is_list($points) && count($points) >= 2 && count($points) <= 64, $path.'.gainPoints', 'Indica entre 2 y 64 puntos.');
        $previous = -1;
        foreach ($points as $i => $point) {
            $p = "$path.gainPoints.$i";
            $this->keys($point, ['time', 'value'], $p);
            $time = $this->number($point['time'] ?? null, 0, $duration, $p.'.time');
            $this->number($point['value'] ?? null, 0, 1, $p.'.value');
            $this->ensure($time > $previous, $p.'.time', 'Los tiempos deben crecer estrictamente.');
            $previous = $time;
        }
        $this->ensure($points[0]['time'] == 0 && abs($points[count($points) - 1]['time'] - $duration) <= self::EPS, $path.'.gainPoints', 'La envolvente debe cubrir el clip completo.');
    }

    private function keys(mixed $value, array $allowed, string $path): void
    {
        $this->ensure(is_array($value) && array_diff(array_keys($value), $allowed) === [], $path, 'Objeto temporal inválido o con propiedades desconocidas.');
    }

    private function number(mixed $value, float $min, float $max, string $path): float
    {
        $this->ensure((is_int($value) || is_float($value)) && is_finite($value) && $value >= $min && $value <= $max, $path, 'Valor temporal no finito o fuera del intervalo.');

        return (float) $value;
    }

    private function boolean(array $value, string $key, string $path): void
    {
        $this->ensure(! array_key_exists($key, $value) || is_bool($value[$key]), $path.'.'.$key, 'Debe ser booleano.');
    }

    private function ensure(bool $condition, string $path, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$path => [$message]]);
        }
    }
}
