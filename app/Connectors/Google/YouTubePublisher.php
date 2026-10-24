<?php

namespace App\Connectors\Google;

use App\Connectors\Contracts\FirstCommenter;
use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\CommentNotAllowedException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\Comments;
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
 * Tiempo: cada pasada tiene un presupuesto BLANDO (`upload_budget_seconds`: no se empieza otro PUT si queda menos de
 * `upload_min_slice_seconds`) y una marca DURA (`pass_seconds` < `$timeout` del job): el timeout HTTP de cada llamada se
 * acota a lo que queda hasta esa marca, de modo que una pasada nunca supera los 110 s del job. La primera llamada de
 * subida de cada pasada siempre se intenta (garantiza avance).
 *
 * Idempotencia: cada video lleva el tag propio `ss-<post_target_id>` (`snippet.tags`); `findPublished` mira los ultimos
 * videos del canal (lista de subidas, posteriores a `since`) y pide sus tags (`videos.list`): solo el video marcado con
 * el tag DE ESTE DESTINO cuenta como "ya publicado" (dos videos con el mismo titulo no se confunden). El ID del video se
 * guarda con `checkpoint` en cuanto se conoce, y un reintento reutiliza la sesion reanudable cacheada (no abre otra ni
 * vuelve a cobrar cuota).
 */
class YouTubePublisher implements FirstCommenter, Publisher, StatusPoller
{
    public const UPLOADING = PublishResult::UPLOADING;

    /** Permiso que exige `commentThreads.insert`. Las cuentas conectadas antes de pedirlo no pueden comentar hasta reconectar. */
    public const COMMENT_SCOPE = 'https://www.googleapis.com/auth/youtube.force-ssl';

    /** Unidades de cuota de la API: `commentThreads.insert` cuesta 50 y `commentThreads.list` 1. */
    private const COMMENT_COST = 50;

    private const COMMENT_LIST_COST = 1;

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

    /** Inicio de la pasada en curso (para acotar el timeout HTTP a la marca dura). */
    private ?float $passStartedAt = null;

    public function __construct(private readonly YouTubeQuota $quota = new YouTubeQuota) {}

    /** ¿El canal concedio el permiso de comentar? Sin el, solo se desactiva el primer comentario; publicar sigue igual. */
    public static function canComment(SocialAccount $account): bool
    {
        return in_array(self::COMMENT_SCOPE, (array) $account->scopes, true);
    }

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $media = $this->videoOf($request);
        $cost = (int) config('social.youtube.upload_cost', 1600);

        // Un reintento reutiliza la sesion reanudable cacheada: ni abre otra ni vuelve a cobrar cuota local.
        $cached = Cache::get($this->sessionKey($request));
        $cached = is_string($cached) && $cached !== '' ? $cached : null;

        if ($cached === null) {
            $this->quota->assertAvailable($cost);
        }

        $reader = MediaChunkReader::for($media);
        $this->beginPass();

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
                    Cache::forget($this->sessionKey($request));
                    $this->quota->assertAvailable($cost);
                }
            }

            $session = $this->openSession($account, $request, $media, $reader->size);
            $this->quota->charge($cost); // abrir la sesion ya cuenta como uso: un reintento no debe ignorarlo
            Cache::put($this->sessionKey($request), $session, now()->addDays(3));

            return $this->upload($account, $request, $session, $media, $reader, 0);
        } finally {
            $reader->close();
            $this->passStartedAt = null;
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

        $tag = self::targetTag($request->targetId);
        $found = null;

        try {
            // La lista de subidas de un canal es la playlist cuyo ID sustituye el prefijo UC por UU. Se pagina (de la mas
            // reciente a la mas antigua) hasta pasar `since`, sin pagina siguiente o `lookup_max_pages`; cada pagina cuesta
            // 1 ud (playlistItems.list) + 1 ud (videos.list): tope 2 x paginas (revision F4-5 i2 N5).
            $pageToken = null;
            $maxPages = max(1, (int) config('social.youtube.lookup_max_pages', 3));

            for ($page = 0; $page < $maxPages && $found === null; $page++) {
                $query = ['part' => 'snippet', 'maxResults' => 50, 'playlistId' => 'UU'.substr($account->external_id, 2)];
                if ($pageToken !== null) {
                    $query['pageToken'] = $pageToken;
                }

                $recent = $this->get($account, 'playlistItems', $query);

                $ids = [];
                $reachedOlder = false;
                foreach ($recent['items'] ?? [] as $item) {
                    $snippet = $item['snippet'] ?? [];
                    $when = isset($snippet['publishedAt']) ? strtotime((string) $snippet['publishedAt']) : false;
                    $videoId = (string) ($snippet['resourceId']['videoId'] ?? '');

                    if ($videoId !== '' && ($request->since === null || ($when !== false && $when >= $request->since->getTimestamp()))) {
                        $ids[] = $videoId;
                    } elseif ($request->since !== null && $when !== false && $when < $request->since->getTimestamp()) {
                        $reachedOlder = true;
                    }
                }

                if ($ids !== []) {
                    // Los tags no vienen en la lista de subidas: se piden (1 ud de cuota) y solo cuenta el video con el tag de ESTE destino.
                    $videos = $this->get($account, 'videos', ['part' => 'snippet', 'id' => implode(',', $ids)]);

                    foreach ($videos['items'] ?? [] as $video) {
                        $id = (string) ($video['id'] ?? '');

                        if ($id !== '' && in_array($tag, (array) ($video['snippet']['tags'] ?? []), true)) {
                            $found = $id;
                            break;
                        }
                    }
                }

                $pageToken = $recent['nextPageToken'] ?? null;

                if ($reachedOlder || ! is_string($pageToken) || $pageToken === '') {
                    break;
                }
            }
        } catch (PublishException $e) {
            Log::notice('youtube.lookup_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            return null;
        }

        if ($found === null) {
            return null;
        }

        return $this->poll($account, new PublishRequest($request->targetId, $request->type, $request->text, $request->media, $request->options, $found, $request->since));
    }

    /**
     * Primer comentario (`commentThreads.insert`, 50 ud, exige `youtube.force-ssl`). Sin el permiso no se llama a la API:
     * pide reconectar. Comentarios desactivados, video no encontrado o privado -> `CommentNotAllowedException`.
     */
    public function comment(SocialAccount $account, string $externalId, string $text): string
    {
        if (! self::canComment($account)) {
            throw new ReconnectRequiredException('Reconecta el canal de YouTube para poder comentar (falta el permiso de comentarios).');
        }

        $this->quota->assertAvailable(self::COMMENT_COST);

        $response = $this->send($account, fn (PendingRequest $http) => $http->post(self::API.'/commentThreads?part=snippet', [
            'snippet' => ['videoId' => $externalId, 'topLevelComment' => ['snippet' => ['textOriginal' => $text]]],
        ]));

        if (! $response->successful()) {
            $reason = (string) ($response->json('error.errors.0.reason') ?? '');

            if (in_array($reason, ['commentsDisabled', 'videoNotFound'], true)) {
                throw new CommentNotAllowedException('YouTube no admite comentarios en este video (privado, con comentarios desactivados o «hecho para ninos»).', 'comentarios_cerrados');
            }

            throw $this->exceptionFor($response);
        }

        $this->quota->charge(self::COMMENT_COST);

        return (string) ($response->json('snippet.topLevelComment.id') ?? $response->json('id') ?? '');
    }

    /** Comentario propio (autor = el canal) con ese texto entre los hilos mas recientes del video, o null. */
    public function findOwnComment(SocialAccount $account, string $externalId, string $text): ?string
    {
        if (! self::canComment($account)) {
            return null;
        }

        $this->quota->assertAvailable(self::COMMENT_LIST_COST);
        $json = $this->get($account, 'commentThreads', ['part' => 'snippet', 'videoId' => $externalId, 'maxResults' => 100, 'order' => 'time', 'textFormat' => 'plainText']);
        $this->quota->charge(self::COMMENT_LIST_COST);

        $wanted = Comments::canon($text);

        foreach ((array) ($json['items'] ?? []) as $thread) {
            $top = $thread['snippet']['topLevelComment'] ?? [];
            $snippet = $top['snippet'] ?? [];

            if (($snippet['authorChannelId']['value'] ?? null) === $account->external_id
                && Comments::canon((string) ($snippet['textOriginal'] ?? $snippet['textDisplay'] ?? '')) === $wanted) {
                return (string) ($top['id'] ?? '') ?: null;
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
        $options = $request->options;
        $category = is_scalar($options['category_id'] ?? null) ? (string) $options['category_id'] : '';
        $meta = [
            'snippet' => [
                'title' => $this->title($request),
                'description' => $this->description($request),
                'categoryId' => preg_match('/^\d{1,3}$/', $category) === 1 ? $category : self::CATEGORY_PEOPLE_BLOGS,
                // El marcador propio del destino va SIEMPRE primero (base de la idempotencia): los tags del usuario se recortan para no expulsarlo.
                'tags' => self::tagsFor($request->targetId, (array) ($options['tags'] ?? [])),
            ],
            'status' => [
                'privacyStatus' => in_array($privacy, self::PRIVACY, true) ? $privacy : 'private',
                'selfDeclaredMadeForKids' => self::flag($options['made_for_kids'] ?? null, false),
            ],
        ];

        // Etiqueta de contenido sintetico (`status.containsSyntheticMedia`): solo si se declara; sin ella no se envia el campo.
        if (self::flag($options['contains_synthetic_media'] ?? null, false)) {
            $meta['status']['containsSyntheticMedia'] = true;
        }

        // `notifySubscribers` es un parametro de la consulta (defecto `true` en la API): solo se envia para desactivarlo.
        $query = 'uploadType=resumable&part=snippet,status'.(self::flag($options['notify_subscribers'] ?? null, true) === false ? '&notifySubscribers=false' : '');

        $response = $this->send($account, fn (PendingRequest $http) => $http
            ->withHeaders(['X-Upload-Content-Length' => (string) $size, 'X-Upload-Content-Type' => $media->mime])
            ->post(self::UPLOAD.'?'.$query, $meta));

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
        $this->beginPass();

        try {
            return $this->continueUpload($account, $request, $session, $media, $reader);
        } finally {
            $reader->close();
            $this->passStartedAt = null;
        }
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
        $chunkBytes = self::chunkSize();
        $size = $reader->size;
        $recoveries = 0;

        while (true) {
            $data = $reader->chunk($offset, min($chunkBytes, $size - $offset), $this->callTimeout((float) config('social.youtube.upload_timeout', 60)));

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

                $known = $this->queryOffset($account, $request, $session, $size);

                if ($known instanceof PublishResult) {
                    return $known;
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

            // Presupuesto comprobado ANTES de cada PUT: sin margen para otro trozo, el sondeo continua desde lo que Google tenga.
            if ($this->budgetLeft() < (float) config('social.youtube.upload_min_slice_seconds', 10)) {
                return PublishResult::processing(self::UPLOADING);
            }
        }
    }

    /** Tamano de trozo: el configurado redondeado a multiplo de 256 KiB (requisito de Google), minimo 256 KiB. */
    private static function chunkSize(): int
    {
        $unit = 256 * 1024;

        return max($unit, intdiv((int) config('social.youtube.chunk_bytes', 8 * 1024 * 1024), $unit) * $unit);
    }

    private function beginPass(): void
    {
        $this->passStartedAt = microtime(true);
    }

    /** Segundos que quedan del presupuesto blando de subida de la pasada. */
    private function budgetLeft(): float
    {
        return (float) config('social.youtube.upload_budget_seconds', 80) - $this->elapsed();
    }

    private function elapsed(): float
    {
        return $this->passStartedAt === null ? 0.0 : microtime(true) - $this->passStartedAt;
    }

    /** Timeout HTTP de una llamada: el tope propio, acotado a lo que queda hasta la marca dura de la pasada (minimo 1 s). */
    private function callTimeout(float $cap): float
    {
        if ($this->passStartedAt === null) {
            return $cap;
        }

        return max(1.0, min($cap, (float) config('social.youtube.pass_seconds', 100) - $this->elapsed()));
    }

    /** `PUT` de un trozo (o consulta de estado con cuerpo vacio y `Content-Range: bytes * / total`). */
    private function put(SocialAccount $account, string $session, string $data, string $mime, string $range): Response
    {
        return Http::timeout($this->callTimeout((float) config('social.youtube.upload_timeout', 60)))
            ->withToken((string) $account->access_token)
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
                ? $this->uploaded($response, $request)
                : $this->uploadedOrFail($account, $request); // sin cuerpo util: ultimo recurso, la lista de subidas
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

        // Se guarda YA el ID: si el job muere ahora, el reintento sondea este video en vez de buscarlo o subirlo otra vez.
        $request->checkpoint($id);
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

        $request->checkpoint($found->reference ?? $found->externalId);
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
        $http = Http::timeout($this->callTimeout((float) config('social.http_timeout', 15)))->acceptJson()->withToken((string) $account->access_token);

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

        // `rateLimitExceeded` es un tope de ritmo POR USUARIO y corto: se reintenta con espera, no agota el cupo del dia.
        if (in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
            return new TransientPublishException('YouTube limito temporalmente el ritmo de peticiones; se reintentara.', 'youtube_limite_ritmo');
        }

        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
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
            && self::flag($request->options['shorts_hashtag'] ?? null, true)
            && ! str_contains(mb_strtolower($text), '#shorts')) {
            $text = rtrim(mb_strcut($text, 0, 5000 - 9, 'UTF-8'))."\n\n#Shorts"; // deja sitio al hashtag
        }

        // Limite de YouTube: 5.000 BYTES (no caracteres) de UTF-8; mb_strcut corta sin partir un caracter.
        return mb_strcut($text, 0, 5000, 'UTF-8');
    }

    /**
     * Tag propio del destino (`snippet.tags`, publico): permite reconocer SU video aunque otro lleve el mismo titulo. Es OPACO
     * (HMAC corto con APP_KEY): no expone el id secuencial interno (CWE-200). Rotar APP_KEY cambia el tag: solo afectaria a la
     * idempotencia de un destino con subida en curso en ese instante.
     */
    public static function targetTag(int $targetId): string
    {
        return 'ss-'.substr(hash_hmac('sha256', 'youtube-target:'.$targetId, (string) config('app.key')), 0, 16);
    }

    /** Limite de `snippet.tags` de YouTube: 500 caracteres en total (las comas cuentan; un tag con espacio suma 2 por las comillas). */
    private const TAGS_LIMIT = 500;

    /** Booleano tolerante (`1`, `"0"`, `true`...): la web y el MCP pueden guardar valores equivalentes sin convertir. Sin valor, el defecto. */
    private static function flag(mixed $value, bool $default): bool
    {
        return $value === null ? $default : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    /**
     * Tags de la subida: el marcador del destino SIEMPRE el primero y los del usuario recortados al hueco que queda, de modo
     * que ni muchos tags ni tags largos o con unicode lo expulsen (`findPublished` reconoce el video por el). Limpia `<` `>` y
     * comas y los caracteres de control (saltos de linea), descarta vacios, duplicados y el propio marcador.
     *
     * @param  list<mixed>  $userTags
     * @return list<string>
     */
    public static function tagsFor(int $targetId, array $userTags): array
    {
        $marker = self::targetTag($targetId);
        $tags = [$marker];
        $used = self::tagCost($marker);
        $seen = [mb_strtolower($marker) => true];

        foreach ($userTags as $raw) {
            if (! is_string($raw)) {
                continue;
            }

            // Sin saltos de linea ni caracteres de control (la API los rechaza o los trunca): se descartan junto a `<`, `>` y la coma.
            $tag = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', str_replace(['<', '>', ','], '', $raw)));
            $key = mb_strtolower($tag);

            if ($tag === '' || isset($seen[$key])) {
                continue;
            }

            $cost = self::tagCost($tag);

            if ($used + $cost > self::TAGS_LIMIT) {
                continue; // no cabe: se omite (un tag mas corto posterior si puede entrar)
            }

            $seen[$key] = true;
            $tags[] = $tag;
            $used += $cost;
        }

        return $tags;
    }

    /** Coste de un tag en el limite: sus caracteres, 2 mas si lleva espacio (comillas) y 1 de la coma que lo separa. */
    private static function tagCost(string $tag): int
    {
        return mb_strlen($tag) + (str_contains($tag, ' ') ? 2 : 0) + 1;
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
