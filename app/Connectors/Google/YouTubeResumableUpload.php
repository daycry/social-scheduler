<?php

namespace App\Connectors\Google;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Support\MediaChunkReader;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Subida REANUDABLE de un video (`videos.insert` con `uploadType=resumable`): abre la sesion, envia el video en trozos leidos en
 * streaming del disco de media (`MediaChunkReader`; nunca esta entero en memoria) y, si el presupuesto de la pasada se agota,
 * devuelve `subiendo` para que el sondeo la continue desde lo que Google tenga. La URL de sesion se guarda en cache por destino:
 * un reintento reutiliza la sesion (no abre otra ni vuelve a cobrar cuota local).
 *
 * Tiempo: cada pasada tiene un presupuesto BLANDO (`upload_budget_seconds`: no se empieza otro PUT si queda menos de
 * `upload_min_slice_seconds`) y una marca DURA (`pass_seconds` < `$timeout` del job), ambos en `YouTubeClient::pass()`. La primera
 * llamada de cada pasada siempre se intenta (garantiza avance).
 *
 * Si Google tiene el video completo pero no devolvio el recurso, se recupera con `$findUploaded` (la busqueda por tag del
 * publisher en la lista de subidas del canal).
 */
class YouTubeResumableUpload
{
    private const UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos';

    private const THUMBNAIL_UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set';

    public function __construct(private readonly YouTubeClient $client, private readonly YouTubeVideoStatus $status) {}

    /** Olvida la sesion de subida reanudable cacheada de un destino (al empezar de cero tras un error). */
    public static function forgetSession(int $targetId): void
    {
        Cache::forget(self::sessionKey($targetId));
    }

    /** Primera pasada: cuota, sesion (la cacheada o una nueva) y envio de trozos. */
    public function start(SocialAccount $account, PublishRequest $request, PublishMedia $media): PublishResult
    {
        $quota = $this->client->quota();
        $cost = (int) config('social.youtube.upload_cost', 1600);

        // Un reintento reutiliza la sesion reanudable cacheada: ni abre otra ni vuelve a cobrar cuota local.
        $cached = Cache::get(self::sessionKey($request->targetId));
        $cached = is_string($cached) && $cached !== '' ? $cached : null;

        if ($cached === null) {
            $quota->assertAvailable($cost);
        }

        $reader = MediaChunkReader::for($media);
        $this->client->pass()->begin();

        try {
            if ($reader->size <= 0) {
                throw new PermanentPublishException('El archivo de video esta vacio.', 'media_invalida');
            }

            if ($cached !== null) {
                try {
                    return $this->continueUpload($account, $request, $cached, $media, $reader);
                } catch (PermanentPublishException $e) {
                    if ($e->errorCode !== 'sesion_caducada') {
                        throw $e;
                    }
                    // La sesion guardada caduco (Google las borra a la semana): se abre una nueva, esta si cuesta cuota.
                    self::forgetSession($request->targetId);
                    $quota->assertAvailable($cost);
                }
            }

            $session = $this->openSession($account, $request, $media, $reader->size);
            $quota->charge($cost); // abrir la sesion ya cuenta como uso: un reintento no debe ignorarlo
            Cache::put(self::sessionKey($request->targetId), $session, now()->addDays(3));

            return $this->upload($account, $request, $session, $media, $reader, 0);
        } finally {
            $this->client->pass()->end();
        }
    }

    /** Pasadas siguientes (referencia `subiendo`): continua la sesion guardada. */
    public function resume(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $session = Cache::get(self::sessionKey($request->targetId));

        if (! is_string($session) || $session === '') {
            throw new PermanentPublishException('Se perdio la sesion de subida a YouTube; reprograma la publicacion.', 'sesion_perdida');
        }

        $media = YouTubeVideoMetadata::videoOf($request);
        $reader = MediaChunkReader::for($media);
        $this->client->pass()->begin();

        try {
            return $this->continueUpload($account, $request, $session, $media, $reader);
        } finally {
            $this->client->pass()->end();
        }
    }

    /** Abre la sesion de subida: devuelve la URL de sesion (cabecera `Location`). */
    private function openSession(SocialAccount $account, PublishRequest $request, PublishMedia $media, int $size): string
    {
        $payload = YouTubeVideoMetadata::insertPayload($request);

        $response = $this->client->send($account, fn (PendingRequest $http) => $http
            ->withHeaders(['X-Upload-Content-Length' => (string) $size, 'X-Upload-Content-Type' => $media->mime])
            ->post(self::UPLOAD.'?'.$payload['query'], $payload['body']));

        if (! $response->successful()) {
            throw $this->client->exceptionFor($response);
        }

        $location = (string) $response->header('Location');
        $host = (string) parse_url($location, PHP_URL_HOST);

        if (parse_url($location, PHP_URL_SCHEME) !== 'https' || ! ($host === 'googleapis.com' || str_ends_with($host, '.googleapis.com'))) {
            throw new PermanentPublishException('YouTube no devolvio una sesion de subida valida.', 'sin_sesion');
        }

        return $location;
    }

    /** Pregunta a Google cuanto tiene de la sesion y sigue desde ahi (o recoge el video si ya estaba completo). */
    private function continueUpload(SocialAccount $account, PublishRequest $request, string $session, PublishMedia $media, MediaChunkReader $reader): PublishResult
    {
        $state = $this->queryOffset($account, $request, $session, $reader->size);

        return $state instanceof PublishResult ? $state : $this->upload($account, $request, $session, $media, $reader, $state);
    }

    /** Envia trozos desde `$offset` hasta terminar o agotar el presupuesto de tiempo de la pasada. */
    private function upload(SocialAccount $account, PublishRequest $request, string $session, PublishMedia $media, MediaChunkReader $reader, int $offset): PublishResult
    {
        $pass = $this->client->pass();
        $chunkBytes = self::chunkSize();
        $size = $reader->size;
        $recoveries = 0;

        while (true) {
            $data = $reader->chunk($offset, min($chunkBytes, $size - $offset), $pass->callTimeout((float) config('social.youtube.upload_timeout', 60)));

            if ($data === '') {
                throw new TransientPublishException('No se pudo leer el video del almacenamiento.', 'lectura_media');
            }

            $range = 'bytes '.$offset.'-'.($offset + strlen($data) - 1).'/'.$size;

            try {
                $response = $this->put($account, $session, $data, $media->mime, $range);
            } catch (ConnectionException) {
                $response = null;
            }
            unset($data);

            if ($response !== null && in_array($response->status(), [200, 201], true)) {
                return $this->uploaded($response, $request, $account);
            }

            if ($response !== null && $response->status() === 308) {
                $received = $this->receivedUpTo($response);

                // Google no avanza: tras varios intentos sin progreso se abandona (se reintenta mas tarde).
                if ($received <= $offset && ++$recoveries > 3) {
                    throw new TransientPublishException('YouTube no esta aceptando los trozos del video; se reintentara.', 'subida_sin_progreso');
                }

                $offset = max($offset, $received);
            } elseif ($response === null || $response->serverError()) {
                // Corte de red o 5xx: se pregunta a Google cuanto recibio y se continua desde ahi (protocolo reanudable).
                if (++$recoveries > 3) {
                    throw new TransientPublishException('La subida a YouTube se interrumpio varias veces; se reintentara.', 'subida_interrumpida');
                }

                $known = $this->queryOffset($account, $request, $session, $size);

                if ($known instanceof PublishResult) {
                    return $known;
                }

                $offset = $known;
            } elseif (in_array($response->status(), [404, 410], true)) {
                self::forgetSession($request->targetId);

                throw new PermanentPublishException('La sesion de subida a YouTube caduco; reprograma la publicacion.', 'sesion_caducada');
            } else {
                throw $this->client->exceptionFor($response);
            }

            if ($offset >= $size) {
                // Google ya tiene todo pero no devolvio el recurso: se recupera por la lista de subidas.
                return $this->uploadedOrFail($account, $request);
            }

            // Presupuesto comprobado ANTES de cada PUT: sin margen para otro trozo, el sondeo continua desde lo que Google tenga.
            if (! $pass->canStartChunk()) {
                return PublishResult::processing(PublishResult::UPLOADING);
            }
        }
    }

    /** Tamano de trozo: el configurado redondeado a multiplo de 256 KiB (requisito de Google), minimo 256 KiB. */
    private static function chunkSize(): int
    {
        $unit = 256 * 1024;

        return max($unit, intdiv((int) config('social.youtube.chunk_bytes', 8 * 1024 * 1024), $unit) * $unit);
    }

    /** `PUT` de un trozo (o consulta de estado con cuerpo vacio y `Content-Range: bytes * / total`). */
    private function put(SocialAccount $account, string $session, string $data, string $mime, string $range): Response
    {
        return $this->client->uploadRequest($account)
            ->withoutRedirecting()
            ->withHeaders(['Content-Range' => $range])
            ->withBody($data, $mime)
            ->put($session);
    }

    /**
     * Cuantos bytes tiene Google de la sesion, o el resultado final si la subida ya esta completa: con 200/201 el cuerpo
     * trae el video (se toma su `id`, sin depender de la lista de subidas, que llega con retraso).
     */
    private function queryOffset(SocialAccount $account, PublishRequest $request, string $session, int $size): int|PublishResult
    {
        try {
            $response = $this->put($account, $session, '', 'application/octet-stream', "bytes */{$size}");
        } catch (ConnectionException) {
            throw new TransientPublishException('No se pudo consultar el estado de la subida a YouTube.', 'red');
        }

        if (in_array($response->status(), [200, 201], true)) {
            return ((string) $response->json('id')) !== ''
                ? $this->uploaded($response, $request, $account)
                : $this->uploadedOrFail($account, $request); // sin cuerpo util: ultimo recurso, la lista de subidas
        }

        if ($response->status() === 308) {
            return $this->receivedUpTo($response);
        }

        if (in_array($response->status(), [404, 410], true)) {
            throw new PermanentPublishException('La sesion de subida a YouTube caduco; reprograma la publicacion.', 'sesion_caducada');
        }

        throw $this->client->exceptionFor($response);
    }

    /** Siguiente desplazamiento segun la cabecera `Range: bytes=0-N` de una respuesta 308 (sin ella: nada recibido). */
    private function receivedUpTo(Response $response): int
    {
        return preg_match('/bytes=\d+-(\d+)/', (string) $response->header('Range'), $m) ? ((int) $m[1]) + 1 : 0;
    }

    private function uploaded(Response $response, PublishRequest $request, SocialAccount $account): PublishResult
    {
        $id = (string) ($response->json('id') ?? '');

        if ($id === '') {
            throw new PermanentPublishException('YouTube no devolvio el ID del video.', 'sin_id');
        }

        // Se guarda YA el ID: si el job muere ahora, el reintento sondea este video en vez de buscarlo o subirlo otra vez.
        $request->checkpoint($id);
        self::forgetSession($request->targetId);
        $this->setThumbnail($account, $request, $id);

        // El video existe pero YouTube aun lo procesa: se sondea `uploadStatus`.
        return PublishResult::processing($id);
    }

    /**
     * Portada propia de este destino (T-17): `thumbnails.set` con los bytes de la imagen elegida, una sola vez al terminar la subida.
     * El video ya existe, asi que un fallo de la miniatura NO falla ni repite la publicacion: queda en el log sin datos sensibles.
     */
    private function setThumbnail(SocialAccount $account, PublishRequest $request, string $videoId): void
    {
        $cover = $request->firstMedia()?->cover;
        if ($cover === null || $cover->disk === null || $cover->path === null) {
            return;
        }

        try {
            $bytes = (string) Storage::disk($cover->disk)->get($cover->path);
            if ($bytes === '') {
                throw new \RuntimeException('portada vacia');
            }
            $this->client->quota()->charge((int) config('social.youtube.thumbnail_cost', 50));
            $response = $this->client->uploadRequest($account)
                ->withBody($bytes, $cover->mime)
                ->post(self::THUMBNAIL_UPLOAD.'?videoId='.rawurlencode($videoId).'&uploadType=media');
            if (! $response->successful()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }
        } catch (\Throwable $e) {
            Log::warning('YouTube: no se pudo fijar la portada del video.', ['target' => $request->targetId, 'error' => class_basename($e).': '.$e->getMessage()]);
        }
    }

    /** La sesion esta completa pero no tenemos el recurso: se recupera por la lista de subidas del canal. */
    private function uploadedOrFail(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $found = $this->status->findByTarget($account, $request);

        if ($found === null) {
            throw new TransientPublishException('YouTube recibio el video pero aun no lo muestra; se comprobara en el siguiente intento.', 'subida_sin_confirmar');
        }

        $request->checkpoint($found->reference ?? $found->externalId);
        self::forgetSession($request->targetId);

        return $found;
    }

    private static function sessionKey(int $targetId): string
    {
        return 'youtube:upload:'.$targetId;
    }
}
