<?php

namespace App\Connectors\Google;

use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Support\MediaChunkReader;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publicacion en YouTube con la Data API v3 (decision G1 = A) y cliente HTTP propio:
 *  - `videos.insert` con subida REANUDABLE (`uploadType=resumable&part=snippet,status`): se abre la sesion y el video
 *    se envia en trozos leidos en streaming del disco de media (`MediaChunkReader`); nunca esta entero en memoria.
 *  - Visibilidad la pedida por el usuario (`public` no se fuerza a privado). Short = video vertical <= 3 min (lo valida
 *    `config/media_specs.php`); se anade `#Shorts` a la descripcion salvo `options.shorts_hashtag = false`.
 *  - Cuota: ~1.600 ud por subida contra un cupo diario configurable (`YouTubeQuota`); agotada -> `QuotaExceededException`
 *    y decide `QuotaPolicy` (reprogramar o fallar).
 *  - Procesamiento: tras subir, el destino queda `procesando` y se sondea `status.uploadStatus` / `processingDetails`.
 *
 * Presupuesto de tiempo: un job dura como maximo 110 s. La subida se corta tras `upload_budget_seconds` y el sondeo la
 * CONTINUA (referencia `subiendo`; la URL de sesion se guarda en cache por destino) hasta completarla.
 * `external_reference` pasa a ser el ID del video en cuanto existe.
 *
 * Idempotencia: `findPublished` busca en la lista de subidas del canal un video con el mismo titulo posterior a `since`.
 */
class YouTubePublisher implements Publisher, StatusPoller
{
    public const UPLOADING = 'subiendo';

    private const API = 'https://www.googleapis.com/youtube/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos';

    private const CATEGORY_PEOPLE_BLOGS = '22';

    private const PRIVACY = ['public', 'unlisted', 'private'];

    /** Motivos de rechazo/fallo de YouTube -> complemento legible. */
    private const REASONS = [
        'copyright' => 'por derechos de autor',
        'duplicate' => 'porque el video ya estaba subido',
        'inappropriate' => 'por contenido inapropiado',
        'length' => 'por su duracion',
        'termsOfUse' => 'por incumplir las condiciones de uso',
        'trademark' => 'por una marca registrada',
        'claim' => 'por una reclamacion de derechos',
        'legal' => 'por motivos legales',
        'uploaderAccountClosed' => 'porque la cuenta esta cerrada',
        'uploaderAccountSuspended' => 'porque la cuenta esta suspendida',
        'codec' => 'por un codec no admitido',
        'unsupportedCodec' => 'por un codec no admitido',
        'conversion' => 'por un error de conversion',
        'emptyFile' => 'porque el archivo esta vacio',
        'invalidFile' => 'porque el archivo no es valido',
        'tooSmall' => 'porque el archivo es demasiado pequeno',
        'uploadAborted' => 'porque la subida se aborto',
    ];

    public function __construct(private readonly YouTubeQuota $quota = new YouTubeQuota) {}

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $media = $this->videoOf($request);
        $cost = (int) config('social.youtube.upload_cost', 1600);

        $this->quota->assertAvailable($cost);

        $reader = MediaChunkReader::for($media);

        try {
            if ($reader->size <= 0) {
                throw new PermanentPublishException('El archivo de video esta vacio.', 'media_invalida');
            }

            $session = $this->openSession($account, $request, $media, $reader->size);
            $this->quota->charge($cost); // abrir la sesion ya cuenta como uso: un reintento no debe ignorarlo
            Cache::put($this->sessionKey($request), $session, now()->addDays(3));

            return $this->upload($account, $request, $session, $media, $reader, 0);
        } finally {
            $reader->close();
        }
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        if ($request->reference === null) {
            throw new PermanentPublishException('Falta la referencia del video de YouTube.', 'sin_referencia');
        }

        if ($request->reference === self::UPLOADING) {
            return $this->resumeUpload($account, $request);
        }

        $data = $this->get($account, 'videos', ['part' => 'status,processingDetails', 'id' => $request->reference]);
        $item = $data['items'][0] ?? null;

        if ($item === null) {
            return PublishResult::processing($request->reference); // aun no visible; el sondeo vence por tiempo
        }

        $status = $item['status'] ?? [];
        $upload = (string) ($status['uploadStatus'] ?? '');
        $processing = (string) ($item['processingDetails']['processingStatus'] ?? '');

        if (in_array($upload, ['failed', 'rejected', 'deleted'], true) || in_array($processing, ['failed', 'terminated'], true)) {
            $reason = (string) ($status['rejectionReason'] ?? $status['failureReason'] ?? $item['processingDetails']['processingFailureReason'] ?? '');
            $why = self::REASONS[$reason] ?? ($reason !== '' ? "({$reason})" : '');

            return PublishResult::failed(match ($upload) {
                'rejected' => 'YouTube rechazo el video '.($why !== '' ? $why : 'tras revisarlo').'.',
                'deleted' => 'El video se elimino de YouTube antes de terminar de procesarse.',
                default => 'YouTube no pudo procesar el video'.($why !== '' ? ' '.$why : '').'.',
            });
        }

        if ($upload === 'processed' || $processing === 'succeeded') {
            return PublishResult::published($request->reference, $this->watchUrl($request->reference, $request->type));
        }

        return PublishResult::processing($request->reference);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        // Una subida en curso o un video ya creado se resuelven por su referencia: nunca se crea otro.
        if ($request->reference !== null) {
            return $this->poll($account, $request);
        }

        $title = $this->title($request);

        try {
            // La lista de subidas de un canal es la playlist cuyo ID sustituye el prefijo UC por UU.
            $data = $this->get($account, 'playlistItems', [
                'part' => 'snippet', 'maxResults' => 10, 'playlistId' => 'UU'.substr($account->external_id, 2),
            ]);
        } catch (PublishException $e) {
            Log::notice('youtube.lookup_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            return null;
        }

        foreach ($data['items'] ?? [] as $item) {
            $snippet = $item['snippet'] ?? [];
            $when = isset($snippet['publishedAt']) ? strtotime((string) $snippet['publishedAt']) : false;
            $videoId = (string) ($snippet['resourceId']['videoId'] ?? '');

            if ($videoId !== '' && (string) ($snippet['title'] ?? '') === $title
                && ($request->since === null || ($when !== false && $when >= $request->since->getTimestamp()))) {
                return $this->poll($account, new PublishRequest($request->targetId, $request->type, $request->text, $request->media, $request->options, $videoId, $request->since));
            }
        }

        return null;
    }

    /** Borra un video del canal (scope `youtube`; `youtube.upload` no lo permite). `false` si ya no existia. */
    public function deleteVideo(SocialAccount $account, string $videoId): bool
    {
        $response = $this->send($account, fn (PendingRequest $http) => $http->delete(self::API.'/videos?'.http_build_query(['id' => $videoId])), false);

        if ($response->status() === 204) {
            return true;
        }

        if ($response->status() === 404) {
            return false;
        }

        throw $this->exceptionFor($response);
    }

    // ------------------------------------------------------------------ subida reanudable

    /** Abre la sesion de subida: devuelve la URL de sesion (cabecera `Location`). */
    private function openSession(SocialAccount $account, PublishRequest $request, PublishMedia $media, int $size): string
    {
        $privacy = (string) ($request->options['privacy'] ?? 'public');
        $meta = [
            'snippet' => [
                'title' => $this->title($request),
                'description' => $this->description($request),
                'categoryId' => self::CATEGORY_PEOPLE_BLOGS,
            ],
            'status' => [
                'privacyStatus' => in_array($privacy, self::PRIVACY, true) ? $privacy : 'private',
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        $response = $this->send($account, fn (PendingRequest $http) => $http
            ->withHeaders(['X-Upload-Content-Length' => (string) $size, 'X-Upload-Content-Type' => $media->mime])
            ->post(self::UPLOAD.'?uploadType=resumable&part=snippet,status', $meta));

        if (! $response->successful()) {
            throw $this->exceptionFor($response);
        }

        $location = (string) $response->header('Location');
        $host = (string) parse_url($location, PHP_URL_HOST);

        if (parse_url($location, PHP_URL_SCHEME) !== 'https' || ! ($host === 'googleapis.com' || str_ends_with($host, '.googleapis.com'))) {
            throw new PermanentPublishException('YouTube no devolvio una sesion de subida valida.', 'sin_sesion');
        }

        return $location;
    }

    private function resumeUpload(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $session = Cache::get($this->sessionKey($request));

        if (! is_string($session) || $session === '') {
            throw new PermanentPublishException('Se perdio la sesion de subida a YouTube; reprograma la publicacion.', 'sesion_perdida');
        }

        $media = $this->videoOf($request);
        $reader = MediaChunkReader::for($media);

        try {
            $offset = $this->queryOffset($account, $session, $reader->size);

            return $offset === null
                ? $this->uploadedOrFail($account, $request)
                : $this->upload($account, $request, $session, $media, $reader, $offset);
        } finally {
            $reader->close();
        }
    }

    /** Envia trozos desde `$offset` hasta terminar o agotar el presupuesto de tiempo de la pasada. */
    private function upload(SocialAccount $account, PublishRequest $request, string $session, PublishMedia $media, MediaChunkReader $reader, int $offset): PublishResult
    {
        $started = microtime(true);
        $budget = (int) config('social.youtube.upload_budget_seconds', 80);
        $chunkBytes = max(256 * 1024, (int) config('social.youtube.chunk_bytes', 8 * 1024 * 1024));
        $size = $reader->size;
        $recoveries = 0;

        while (true) {
            $data = $reader->chunk($offset, min($chunkBytes, $size - $offset));

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
                return $this->uploaded($response, $request);
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

                $known = $this->queryOffset($account, $session, $size);

                if ($known === null) {
                    return $this->uploadedOrFail($account, $request);
                }

                $offset = $known;
            } elseif (in_array($response->status(), [404, 410], true)) {
                Cache::forget($this->sessionKey($request));

                throw new PermanentPublishException('La sesion de subida a YouTube caduco; reprograma la publicacion.', 'sesion_caducada');
            } else {
                throw $this->exceptionFor($response);
            }

            if ($offset >= $size) {
                // Google ya tiene todo pero no devolvio el recurso: se recupera por la lista de subidas.
                return $this->uploadedOrFail($account, $request);
            }

            if ((microtime(true) - $started) >= $budget) {
                return PublishResult::processing(self::UPLOADING); // el sondeo continua desde lo que Google tenga
            }
        }
    }

    /** `PUT` de un trozo (o consulta de estado con cuerpo vacio y `Content-Range: bytes * / total`). */
    private function put(SocialAccount $account, string $session, string $data, string $mime, string $range): Response
    {
        return Http::timeout((int) config('social.youtube.upload_timeout', 60))
            ->withToken((string) $account->access_token)
            ->withoutRedirecting()
            ->withHeaders(['Content-Range' => $range])
            ->withBody($data, $mime)
            ->put($session);
    }

    /** Cuantos bytes tiene Google de la sesion. `null` = la subida ya esta completa. */
    private function queryOffset(SocialAccount $account, string $session, int $size): ?int
    {
        try {
            $response = $this->put($account, $session, '', 'application/octet-stream', "bytes */{$size}");
        } catch (ConnectionException) {
            throw new TransientPublishException('No se pudo consultar el estado de la subida a YouTube.', 'red');
        }

        if (in_array($response->status(), [200, 201], true)) {
            return null;
        }

        if ($response->status() === 308) {
            return $this->receivedUpTo($response);
        }

        if (in_array($response->status(), [404, 410], true)) {
            throw new PermanentPublishException('La sesion de subida a YouTube caduco; reprograma la publicacion.', 'sesion_caducada');
        }

        throw $this->exceptionFor($response);
    }

    /** Siguiente desplazamiento segun la cabecera `Range: bytes=0-N` de una respuesta 308 (sin ella: nada recibido). */
    private function receivedUpTo(Response $response): int
    {
        return preg_match('/bytes=\d+-(\d+)/', (string) $response->header('Range'), $m) ? ((int) $m[1]) + 1 : 0;
    }

    private function uploaded(Response $response, PublishRequest $request): PublishResult
    {
        $id = (string) ($response->json('id') ?? '');

        if ($id === '') {
            throw new PermanentPublishException('YouTube no devolvio el ID del video.', 'sin_id');
        }

        Cache::forget($this->sessionKey($request));

        // El video existe pero YouTube aun lo procesa: se sondea `uploadStatus`.
        return PublishResult::processing($id);
    }

    /** La sesion esta completa pero no tenemos el recurso: se recupera por la lista de subidas del canal. */
    private function uploadedOrFail(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $found = $this->findPublished($account, new PublishRequest($request->targetId, $request->type, $request->text, $request->media, $request->options, null, $request->since));

        if ($found === null) {
            throw new TransientPublishException('YouTube recibio el video pero aun no lo muestra; se comprobara en el siguiente intento.', 'subida_sin_confirmar');
        }

        Cache::forget($this->sessionKey($request));

        return $found;
    }

    // ------------------------------------------------------------------ HTTP y errores

    /** @return array<string, mixed> */
    private function get(SocialAccount $account, string $resource, array $query): array
    {
        $response = $this->send($account, fn (PendingRequest $http) => $http->get(self::API.'/'.$resource, $query), false);

        if (! $response->successful()) {
            throw $this->exceptionFor($response);
        }

        return $response->json() ?? [];
    }

    /** @param \Closure(PendingRequest): Response $call */
    private function send(SocialAccount $account, \Closure $call, bool $json = true): Response
    {
        $http = Http::timeout((int) config('social.http_timeout', 15))->acceptJson()->withToken((string) $account->access_token);

        try {
            return $call($json ? $http->asJson() : $http);
        } catch (ConnectionException) {
            throw new TransientPublishException('No se pudo contactar con YouTube (tiempo agotado o red).', 'red');
        }
    }

    private function exceptionFor(Response $response): PublishException|ReconnectRequiredException
    {
        $status = $response->status();
        $reason = (string) ($response->json('error.errors.0.reason') ?? $response->json('error.status') ?? '');
        $message = (string) ($response->json('error.message') ?? 'respuesta inesperada');

        Log::warning('youtube.publish_error', ['status' => $status, 'reason' => $reason]);

        if ($status === 401) {
            return new ReconnectRequiredException('YouTube rechazo las credenciales ('.($reason ?: $status).'). Reconecta el canal.');
        }

        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded'], true)) {
            $this->quota->markExhausted();

            return new QuotaExceededException('YouTube informa de que se agoto el cupo de la API ('.$reason.').', $this->quota->resetsAt());
        }

        if ($reason === 'uploadLimitExceeded') {
            return new QuotaExceededException('El canal alcanzo su limite diario de subidas de YouTube.', now()->addDay());
        }

        if ($status === 403) {
            return new PermanentPublishException('YouTube no permite publicar con esta cuenta ('.($reason ?: $message).'). Revisa los permisos y vuelve a conectar el canal.', 'permisos');
        }

        if ($status >= 500 || $status === 429) {
            return new TransientPublishException('YouTube no esta disponible temporalmente ('.($reason ?: $status).').', 'youtube_no_disponible');
        }

        return new PermanentPublishException('YouTube rechazo la publicacion: '.$message, 'rechazada');
    }

    // ------------------------------------------------------------------ contenido

    private function videoOf(PublishRequest $request): PublishMedia
    {
        $media = $request->firstMedia();

        if (! in_array($request->type, [ContentType::YouTubeVideo, ContentType::YouTubeShort], true)) {
            throw new PermanentPublishException('Tipo de contenido no admitido en YouTube.', 'tipo_no_admitido');
        }

        if ($media === null || ! $media->isVideo()) {
            throw new PermanentPublishException('Falta el archivo de video de la publicacion.', 'media_invalida');
        }

        return $media;
    }

    /** Titulo: `options.title` o la primera linea del texto (YouTube admite 100 caracteres y no `<` `>`). */
    private function title(PublishRequest $request): string
    {
        $raw = trim((string) ($request->options['title'] ?? ''));

        if ($raw === '') {
            $raw = trim((string) strtok(trim((string) $request->text), "\r\n"));
        }

        $title = trim(mb_substr(str_replace(['<', '>'], '', $raw), 0, 100));

        return $title !== '' ? $title : 'Sin titulo';
    }

    private function description(PublishRequest $request): string
    {
        $text = str_replace(['<', '>'], '', (string) $request->text);

        if ($request->type === ContentType::YouTubeShort
            && ($request->options['shorts_hashtag'] ?? true) !== false
            && ! str_contains(mb_strtolower($text), '#shorts')) {
            $text = rtrim($text."\n\n#Shorts");
        }

        return mb_substr($text, 0, 5000);
    }

    private function watchUrl(string $id, ContentType $type): string
    {
        return $type === ContentType::YouTubeShort ? "https://www.youtube.com/shorts/{$id}" : "https://www.youtube.com/watch?v={$id}";
    }

    private function sessionKey(PublishRequest $request): string
    {
        return self::sessionCacheKey($request->targetId);
    }

    private static function sessionCacheKey(int $targetId): string
    {
        return 'youtube:upload:'.$targetId;
    }

    /** Olvida la sesion de subida reanudable cacheada de un destino (al empezar de cero tras un error). */
    public static function forgetUploadSession(int $targetId): void
    {
        Cache::forget(self::sessionCacheKey($targetId));
    }
}
