<?php

namespace App\Services;

use App\Models\MediaAsset;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extrae dimensiones, duracion, fps y codec. Las imagenes se leen con GD; los videos con ffprobe
 * sobre un fichero local (en R2 se descarga a un temporal; ffprobe nunca abre URLs).
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
        // Seguridad (CWE-918): ffprobe NUNCA abre una URL. Un fichero subido por el usuario puede ser un manifiesto
        // HLS/DASH disfrazado de .mp4 cuyos segmentos apunten a la red interna. Se sondea siempre un fichero LOCAL
        // (en R2 se baja a un temporal), con el demuxer forzado a MP4/MOV y solo el protocolo `file` permitido.
        $temporary = null;

        try {
            if ($this->storage->isS3()) {
                $source = $temporary = $this->download($asset);
            } else {
                $source = $this->storage->disk()->path($asset->path);
            }

            return $this->runFfprobe($source);
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }
    }

    /** Copia el objeto a un fichero temporal (en streaming, sin cargarlo en memoria). */
    private function download(MediaAsset $asset): string
    {
        $stream = $this->storage->disk()->readStream($asset->path);
        $target = tempnam(sys_get_temp_dir(), 'probe_');

        if (! is_resource($stream) || $target === false) {
            throw new RuntimeException('No se pudo leer el video del almacenamiento.');
        }

        try {
            $out = fopen($target, 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($out);
        } catch (\Throwable) {
            @unlink($target);

            throw new RuntimeException('No se pudo leer el video del almacenamiento.');
        } finally {
            fclose($stream);
        }

        return $target;
    }

    private function runFfprobe(string $source): array
    {
        $process = new Process([
            (string) config('media.ffprobe', 'ffprobe'), '-v', 'error',
            '-protocol_whitelist', 'file',
            '-f', 'mov', // demuxer MP4/MOV forzado: HLS/DASH/playlists/concat no se interpretan
            '-print_format', 'json', '-show_format', '-show_streams', '-i', $source,
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
     * Interpreta la salida JSON de ffprobe. Publico para poder probarlo sin binario. Las dimensiones son las
     * VISIBLES: un video grabado en vertical con metadato de rotacion (displaymatrix / tag `rotate`) de +-90 o 270
     * grados se guarda con ancho y alto intercambiados.
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

        $format = (string) ($data['format']['format_name'] ?? '');
        if ($format !== '' && ! str_contains($format, 'mov') && ! str_contains($format, 'mp4')) {
            throw new RuntimeException('El fichero no es un video MP4/MOV valido.');
        }

        $duration = $video['duration'] ?? ($data['format']['duration'] ?? null);
        $width = isset($video['width']) ? (int) $video['width'] : null;
        $height = isset($video['height']) ? (int) $video['height'] : null;

        if ($width !== null && $height !== null && $this->rotation($video) % 180 !== 0) {
            [$width, $height] = [$height, $width];
        }

        return [
            'width' => $width,
            'height' => $height,
            'duration' => is_numeric($duration) ? round((float) $duration, 3) : null,
            'fps' => $this->fps($video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? null),
            'codec' => $video['codec_name'] ?? null,
            'audio_codec' => $audio['codec_name'] ?? null,
            'mime' => null,
        ];
    }

    /** Rotacion en grados (0, 90, 180, 270) segun displaymatrix o la tag `rotate` heredada. */
    private function rotation(array $video): int
    {
        $degrees = $video['tags']['rotate'] ?? null;

        foreach ($video['side_data_list'] ?? [] as $side) {
            if (isset($side['rotation'])) {
                $degrees = $side['rotation'];
            }
        }

        return is_numeric($degrees) ? (((int) round((float) $degrees)) % 360 + 360) % 360 : 0;
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
