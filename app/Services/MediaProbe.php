<?php

namespace App\Services;

use App\Models\MediaAsset;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extrae dimensiones, duracion, fps y codec. Las imagenes se leen con GD; los videos con ffprobe
 * (sobre la ruta local o la URL temporal, sin descargar el fichero entero por PHP).
 */
class MediaProbe
{
    public function __construct(private readonly MediaStorage $storage) {}

    /**
     * @return array{width: ?int, height: ?int, duration: ?float, fps: ?float, codec: ?string, audio_codec: ?string, mime: ?string}
     *
     * @throws RuntimeException si el fichero no es legible como imagen/video.
     */
    public function inspect(MediaAsset $asset): array
    {
        return $asset->isVideo() ? $this->video($asset) : $this->image($asset);
    }

    private function image(MediaAsset $asset): array
    {
        $contents = $this->storage->disk()->get($asset->path);
        $info = is_string($contents) ? @getimagesizefromstring($contents) : false;

        if (! $info) {
            throw new RuntimeException('El fichero no es una imagen valida.');
        }

        return [
            'width' => (int) $info[0], 'height' => (int) $info[1], 'duration' => null, 'fps' => null,
            'codec' => null, 'audio_codec' => null, 'mime' => $info['mime'] ?? null,
        ];
    }

    private function video(MediaAsset $asset): array
    {
        $disk = $this->storage->disk();
        $source = $this->storage->isS3()
            ? $disk->temporaryUrl($asset->path, now()->addMinutes(30))
            : $disk->path($asset->path);

        $process = new Process([
            (string) config('media.ffprobe', 'ffprobe'), '-v', 'error', '-print_format', 'json',
            '-show_format', '-show_streams', $source,
        ]);
        $process->setTimeout((float) config('media.ffprobe_timeout', 60));

        try {
            $process->run();
        } catch (\Throwable) {
            throw new RuntimeException('No se pudo leer el video (ffprobe no disponible o sin respuesta).');
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('El fichero no es un video valido.');
        }

        return $this->parse((string) $process->getOutput());
    }

    /**
     * Interpreta la salida JSON de ffprobe. Publico para poder probarlo sin binario.
     *
     * @return array{width: ?int, height: ?int, duration: ?float, fps: ?float, codec: ?string, audio_codec: ?string, mime: ?string}
     */
    public function parse(string $json): array
    {
        $data = json_decode($json, true);
        $streams = is_array($data) ? ($data['streams'] ?? []) : [];
        $video = collect($streams)->firstWhere('codec_type', 'video');
        $audio = collect($streams)->firstWhere('codec_type', 'audio');

        if (! $video) {
            throw new RuntimeException('El fichero no contiene una pista de video.');
        }

        $duration = $video['duration'] ?? ($data['format']['duration'] ?? null);

        return [
            'width' => isset($video['width']) ? (int) $video['width'] : null,
            'height' => isset($video['height']) ? (int) $video['height'] : null,
            'duration' => is_numeric($duration) ? round((float) $duration, 3) : null,
            'fps' => $this->fps($video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? null),
            'codec' => $video['codec_name'] ?? null,
            'audio_codec' => $audio['codec_name'] ?? null,
            'mime' => null,
        ];
    }

    private function fps(mixed $rate): ?float
    {
        if (! is_string($rate) || ! str_contains($rate, '/')) {
            return is_numeric($rate) ? round((float) $rate, 3) : null;
        }

        [$num, $den] = array_map('floatval', explode('/', $rate, 2));

        return $den > 0 ? round($num / $den, 3) : null;
    }
}
