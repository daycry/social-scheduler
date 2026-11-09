<?php

namespace App\Services\Studio;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Support\MediaChunkReader;
use App\Models\MediaAsset;
use App\Services\MediaStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/** Native audio inspection uses only managed local files, never a URL or playlist demuxer. */
class StudioAudioProbe
{
    public function __construct(private readonly MediaStorage $storage) {}

    public function inspect(MediaAsset $asset): array
    {
        $format = match ($asset->mime) {
            'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/mp4' => 'mov', 'audio/aac' => 'aac', 'audio/ogg' => 'ogg',
            default => throw new RuntimeException('El formato de audio no es compatible.'),
        };
        $binary = (new ExecutableFinder)->find((string) config('media.ffprobe', 'ffprobe'));
        if ($binary === null) {
            throw new StudioAudioProbeUnavailable('El motor de audio no está disponible; conserva el archivo y vuelve a intentarlo.');
        }
        $temporary = null;
        try {
            $disk = Storage::disk($asset->disk);
            if ($this->storage->isS3()) {
                $source = $temporary = $this->download($asset);
            } else {
                $source = $disk->path($asset->path);
            }
            $process = new Process([$binary, '-v', 'error', '-max_alloc', '67108864', '-protocol_whitelist', 'file',
                '-f', $format, '-probesize', '10485760', '-analyzeduration', '10000000',
                '-show_entries', 'format=format_name,duration:stream=codec_type,codec_name,sample_rate,channels,duration:stream_disposition=attached_pic',
                '-of', 'json', '-i', $source]);
            $process->setTimeout((float) config('media.audio_probe_timeout', 15));
            try {
                $process->run();
            } catch (Throwable) {
                throw new StudioAudioProbeUnavailable('El motor de audio no respondió a tiempo; vuelve a intentar la validación.');
            }
            if (! $process->isSuccessful()) {
                throw new RuntimeException('El archivo no contiene audio válido del formato declarado.');
            }

            return $this->parse($process->getOutput(), $asset->mime);
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }
    }

    private function download(MediaAsset $asset): string
    {
        $deadline = microtime(true) + (float) config('media.audio_download_timeout', 25);
        $path = storage_path('app/private/studio-audio-probe/'.Str::uuid().'.bin');
        File::ensureDirectoryExists(dirname($path), 0700);
        $out = null;
        try {
            $reader = MediaChunkReader::for(new PublishMedia(id: $asset->id, kind: 'audio', mime: $asset->mime, url: '', disk: $asset->disk, path: $asset->path), timeout: $deadline - microtime(true));
            $max = min(100 * 1024 * 1024, (int) config('media.max_bytes.audio'));
            if ($reader->size < 1 || $reader->size > $max || $reader->size > $asset->declared_size) {
                throw new RuntimeException('El audio supera el tamaño declarado o permitido.');
            }
            $out = fopen($path, 'xb');
            if (! is_resource($out)) {
                throw new StudioAudioProbeUnavailable('No se pudo preparar el sondeo de audio.');
            }
            for ($offset = 0; $offset < $reader->size;) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new StudioAudioProbeUnavailable('La lectura del audio agotó su tiempo; vuelve a intentarlo.');
                }
                $length = min(1024 * 1024, $reader->size - $offset);
                $bytes = $reader->chunk($offset, $length, $remaining);
                if (strlen($bytes) !== $length || fwrite($out, $bytes) !== $length) {
                    throw new StudioAudioProbeUnavailable('No se pudo leer el audio completo.');
                }
                $offset += $length;
            }
            fclose($out);

            return $path;
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($path);
            if ($e instanceof RuntimeException && ! $e instanceof ConnectorException) {
                throw $e;
            }
            throw new StudioAudioProbeUnavailable('No se pudo leer el audio del almacenamiento.');
        }
    }

    public function parse(string $json, string $mime): array
    {
        $data = json_decode($json, true, 32);
        $streams = is_array($data) ? ($data['streams'] ?? []) : [];
        $audio = null;
        if (! is_array($streams) || count($streams) > 16) {
            throw new RuntimeException('El audio contiene demasiadas pistas o metadatos inválidos.');
        }
        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? null) === 'video' && ! ($stream['disposition']['attached_pic'] ?? false)) {
                throw new RuntimeException('El archivo contiene vídeo; súbelo como recurso de vídeo.');
            }
            if (($stream['codec_type'] ?? null) === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }
        $allowed = match ($mime) {
            'audio/mpeg' => ['mp3'], 'audio/mp4', 'audio/aac' => ['aac'], 'audio/ogg' => ['opus', 'vorbis'],
            'audio/wav' => ['pcm_u8', 'pcm_s16le', 'pcm_s24le', 'pcm_s32le', 'pcm_f32le', 'pcm_f64le', 'pcm_alaw', 'pcm_mulaw'],
            default => [],
        };
        $duration = $audio['duration'] ?? $data['format']['duration'] ?? null;
        $seconds = is_numeric($duration) ? (float) $duration : 0;
        if ($audio === null || ! in_array($audio['codec_name'] ?? null, $allowed, true)
            || ! is_finite($seconds) || $seconds < 0.001 || $seconds > min(3600, (float) config('media.audio_max_duration', 3600))
            || ! is_numeric($audio['sample_rate'] ?? null) || $audio['sample_rate'] < 8000 || $audio['sample_rate'] > 192000
            || ! is_int($audio['channels'] ?? null) || $audio['channels'] < 1 || $audio['channels'] > 8) {
            throw new RuntimeException('El audio tiene duración, frecuencia, canales o códec no compatibles.');
        }

        return ['width' => null, 'height' => null, 'duration' => $seconds, 'fps' => null,
            'codec' => null, 'audio_codec' => $audio['codec_name'], 'mime' => $mime];
    }

    /** A killed PHP process cannot run finally; clean only old UUID files in this owned directory. */
    public static function pruneTemporaries(int $olderThanMinutes = 60): int
    {
        $directory = storage_path('app/private/studio-audio-probe');
        $resolved = realpath($directory);
        if ($resolved === false || ! is_dir($resolved)) {
            return 0;
        }
        $limit = time() - max(1, $olderThanMinutes) * 60;
        $removed = 0;
        foreach (File::files($resolved) as $file) {
            $path = $file->getRealPath();
            if ($file->isLink() || $path === false || dirname($path) !== $resolved
                || ! preg_match('/^[a-f0-9-]{36}\.bin$/D', $file->getFilename()) || $file->getMTime() >= $limit) {
                continue;
            }
            if (File::delete($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
