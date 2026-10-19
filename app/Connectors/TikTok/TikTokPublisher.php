<?php

namespace App\Connectors\TikTok;

use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Support\MediaChunkReader;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Publicacion en TikTok (Content Posting API), decision G1 = D + B:
 *  - POR DEFECTO: borrador en la bandeja (`/v2/post/publish/inbox/video/init/`, scope video.upload). El usuario
 *    termina de publicar en la app: el destino queda `publicada` con la nota «enviado a la bandeja de TikTok».
 *  - Direct Post (`/v2/post/publish/video/init/` con `post_info`) solo si `meta.direct_post` = true en la cuenta
 *    (requiere auditoria aprobada de TikTok). `unaudited_client_can_only_post_to_private_accounts` -> fallo permanente.
 *  - Subida FILE_UPLOAD por trozos en streaming: un trozo si <= 64 MB, si no trozos de 10 MB y el ultimo absorbe el resto.
 *  - Video >= 23 fps antes de subir (`frame_rate_check_failed`). Foto/carrusel por PULL_FROM_URL solo con dominio verificado.
 *  - Estado por `/v2/post/publish/status/fetch/`. `creator_info/query` lleva cuerpo `{}`.
 *
 * `external_reference` = publish_id, desde ANTES de subir el primer trozo (`checkpoint`): un corte en cualquier PUT se
 * reconcilia por el con `status/fetch`, nunca con un `init` nuevo (no hay borradores duplicados).
 *
 * Subida por pasadas: un job dura como maximo 110 s. La subida se reparte en pasadas con presupuesto
 * (`social.tiktok.upload_budget_seconds`) y su estado vive en `progress` (`phase = uploading`, `upload_url`, trozo siguiente);
 * `PollPublishStatus` la continua. Al acabar, `progress.phase = processing` y `uploaded_at` (arranca el plazo de procesado).
 *
 * `upload_url` caduca a la hora (doc. de TikTok): la subida se reinicia con un `init` nuevo si no se publico nada (maximo
 * `social.tiktok.upload_restarts`) o falla con «la subida caduco: reprograma». El plazo de la fase de subida de TikTok es
 * `social.tiktok.upload_max_minutes` (lo aplica PollPublishStatus), no el de YouTube.
 *
 * Borrador (inbox) atascado: TikTok puede dejar `PROCESSING_UPLOAD` indefinidamente aunque el video ya esta en la bandeja
 * (observado en el spike en sandbox). Pasado `social.tiktok.inbox_grace_minutes` desde la subida completa se da por
 * `publicada` con una nota ("TikTok no confirmo el estado") en lugar de `fallida`: el video ya esta subido y el usuario
 * lo termina en su app. `external_id` de un borrador es el `publish_id` (NO un ID de video): `progress.external_id_kind =
 * publish_id` lo marca para que la analitica (T-34) no lo use como ID de video.
 *
 * DECISION (revision F4-5 i2 N3): tras la gracia NO se sigue sondeando en segundo plano. El destino sale del ciclo de sondeo
 * al quedar `publicada`, y reabrirlo exigiria un vigilante nuevo y una transicion `publicada -> fallida` que el agregado del
 * post no contempla. La nota («TikTok no confirmo el estado; revisa tus borradores») avisa al usuario; `inbox_grace_minutes`
 * (TIKTOK_INBOX_GRACE_MINUTES) permite alargar la espera antes de darlo por enviado.
 */
class TikTokPublisher implements Publisher, StatusPoller
{
    /** Inicio de la pasada de subida en curso (acota el timeout HTTP a la marca dura). */
    private ?float $passStartedAt = null;

    public const INBOX_NOTE = 'Enviado a la bandeja de TikTok: abre la app y termina de publicarlo desde tus borradores.';

    public const INBOX_UNCONFIRMED_NOTE = 'Enviado a la bandeja (TikTok no confirmo el estado); revisa tus borradores.';

    /** Marca en `progress` de que `external_id` es un publish_id y no el ID de un video. */
    public const KIND_PUBLISH_ID = 'publish_id';

    private const API = 'https://open.tiktokapis.com';

    private const MIN_FPS = 23;

    private const ERRORS = [
        'frame_rate_check_failed' => ['permanent', 'TikTok rechazo el video: necesita al menos 23 fps.'],
        'duration_check_failed' => ['permanent', 'TikTok rechazo el video por su duracion.'],
        'file_format_check_failed' => ['permanent', 'TikTok no admite el formato del video (usa MP4/MOV con H.264).'],
        'picture_size_check_failed' => ['permanent', 'TikTok rechazo el tamano de las imagenes.'],
        'spam_risk_too_many_posts' => ['quota', 'TikTok limito las publicaciones de la cuenta por hoy.'],
        'spam_risk_user_banned_from_posting' => ['permanent', 'TikTok impide publicar a esta cuenta (bloqueo por spam).'],
        'reached_active_user_cap' => ['quota', 'La app de TikTok alcanzo su limite diario de usuarios.'],
        'url_ownership_unverified' => ['permanent', 'El dominio de la media no esta verificado en TikTok.'],
        'unaudited_client_can_only_post_to_private_accounts' => ['permanent', 'TikTok solo permite publicar en cuentas privadas hasta aprobar la auditoria de la app. Desactiva Direct Post en Conexiones para enviar el video como borrador a la bandeja.'],
        'privacy_level_option_mismatch' => ['permanent', 'La visibilidad pedida no esta disponible para esta cuenta de TikTok.'],
        'access_token_invalid' => ['reconnect', 'TikTok rechazo las credenciales.'],
        'scope_not_authorized' => ['reconnect', 'Falta un permiso de TikTok (video.upload / video.publish).'],
        'scope_permission_missed' => ['reconnect', 'Falta un permiso de TikTok (video.upload / video.publish).'],
        'rate_limit_exceeded' => ['transient', 'TikTok limito temporalmente las peticiones.'],
        'internal_error' => ['transient', 'TikTok tuvo un error interno.'],
    ];

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        return match ($request->type) {
            ContentType::TikTokVideo => $this->publishVideo($account, $request),
            ContentType::TikTokPhoto => $this->publishPhotos($account, $request),
            default => throw new PermanentPublishException('Tipo de contenido no admitido en TikTok.', 'tipo_no_admitido'),
        };
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        if ($request->reference === null) {
            throw new PermanentPublishException('Falta el publish_id de TikTok.', 'sin_referencia');
        }

        // Subida a medias: se continua desde el trozo siguiente guardado en `progress`.
        if (($request->progress['phase'] ?? null) === 'uploading') {
            return $this->resumeUpload($account, $request);
        }

        $data = $this->api($account, '/v2/post/publish/status/fetch/', ['publish_id' => $request->reference]);
        $status = (string) ($data['status'] ?? '');
        $kind = ['phase' => 'done', 'external_id_kind' => self::KIND_PUBLISH_ID];

        return match ($status) {
            'PUBLISH_COMPLETE' => $this->complete($account, $request->reference, $data),
            'SEND_TO_USER_INBOX' => PublishResult::published($request->reference, null, [], self::INBOX_NOTE, $kind),
            'FAILED' => $this->failure((string) ($data['fail_reason'] ?? '')),
            default => $this->stuckDraft($account, $request, $status)
                ? PublishResult::published($request->reference, null, [], self::INBOX_UNCONFIRMED_NOTE, $kind)
                : PublishResult::processing($request->reference), // PROCESSING_UPLOAD | PROCESSING_DOWNLOAD
        };
    }

    /** Borrador con la subida completa hace mas de `inbox_grace_minutes` y TikTok sin confirmar el estado. */
    private function stuckDraft(SocialAccount $account, PublishRequest $request, string $status): bool
    {
        $uploadedAt = (int) ($request->progress['uploaded_at'] ?? 0);

        return $status === 'PROCESSING_UPLOAD'
            && ! self::directPost($account)
            && $uploadedAt > 0
            && now()->timestamp - $uploadedAt >= (int) config('social.tiktok.inbox_grace_minutes', 10) * 60;
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        // TikTok no lista lo publicado: con publish_id se consulta su estado; sin el, no hay constancia.
        return $request->reference !== null ? $this->poll($account, $request) : null;
    }

    /**
     * Opciones de publicacion de la cuenta (`creator_info/query`, cuerpo `{}`).
     *
     * @return array<string, mixed>
     */
    public function creatorInfo(SocialAccount $account): array
    {
        return $this->api($account, '/v2/post/publish/creator_info/query/', null);
    }

    public static function directPost(SocialAccount $account): bool
    {
        return ($account->meta['direct_post'] ?? false) === true;
    }

    // ------------------------------------------------------------------ video

    private function publishVideo(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $media = $request->firstMedia();

        if ($media === null || ! $media->isVideo()) {
            throw new PermanentPublishException('Falta el archivo de video de la publicacion.', 'media_invalida');
        }

        if ($media->fps !== null && $media->fps < self::MIN_FPS) {
            throw new PermanentPublishException('TikTok exige al menos '.self::MIN_FPS.' fps y el video tiene '.round($media->fps, 1).'. Exportalo a 24 fps o mas.', 'frame_rate_check_failed');
        }

        $reader = MediaChunkReader::for($media);
        $this->passStartedAt = microtime(true);

        try {
            if ($reader->size <= 0) {
                throw new PermanentPublishException('El archivo de video esta vacio.', 'media_invalida');
            }

            [$publishId, $progress] = $this->openUpload($account, $request, $reader, 0);

            return $this->uploadChunks($account, $request, $publishId, $progress, $media, $reader);
        } finally {
            $reader->close();
            $this->passStartedAt = null;
        }
    }

    /**
     * Abre la sesion de subida (`init`) y guarda YA su publish_id y progreso. La `upload_url` vale 1 hora desde que se emite
     * (doc. de TikTok): `upload_url_at` permite detectar la caducidad; `restarts` cuenta los reinicios por caducidad.
     *
     * @return array{0: string, 1: array<string, mixed>} [publish_id, progress]
     */
    private function openUpload(SocialAccount $account, PublishRequest $request, MediaChunkReader $reader, int $restarts): array
    {
        [$chunk, $count] = $this->plan($reader->size);
        $source = ['source' => 'FILE_UPLOAD', 'video_size' => $reader->size, 'chunk_size' => $chunk, 'total_chunk_count' => $count];

        if (self::directPost($account)) {
            $init = $this->api($account, '/v2/post/publish/video/init/', ['post_info' => $this->postInfo($account, $request), 'source_info' => $source]);
        } else {
            $init = $this->api($account, '/v2/post/publish/inbox/video/init/', ['source_info' => $source]);
        }

        $publishId = (string) ($init['publish_id'] ?? '');
        $uploadUrl = (string) ($init['upload_url'] ?? '');

        if ($publishId === '' || $uploadUrl === '') {
            throw new PermanentPublishException('TikTok no abrio la sesion de subida.', 'sin_sesion');
        }

        $this->assertUploadHost($uploadUrl);

        // El publish_id y el estado de la subida se guardan ANTES del primer PUT: si el job muere a mitad (o en el
        // ultimo trozo), el reintento reconcilia con este publish_id en vez de abrir un borrador nuevo.
        $progress = ['phase' => 'uploading', 'upload_url' => $uploadUrl, 'upload_url_at' => now()->timestamp, 'restarts' => $restarts, 'chunk_size' => $chunk, 'chunk_count' => $count, 'next' => 0, 'size' => $reader->size];
        $request->checkpoint($publishId, $progress);

        return [$publishId, $progress];
    }

    /** La `upload_url` lleva emitida mas que su vigencia (`upload_url_ttl_minutes`: 1 h en TikTok, con margen)? */
    private function uploadUrlExpired(array $progress): bool
    {
        $issued = (int) ($progress['upload_url_at'] ?? 0);

        return $issued > 0 && now()->timestamp - $issued >= (int) config('social.tiktok.upload_url_ttl_minutes', 55) * 60;
    }

    /**
     * La `upload_url` caduco (por tiempo, o el PUT dio 401/403/404/410): en FILE_UPLOAD TikTok no publica nada hasta recibir
     * el ultimo trozo, asi que se reinicia con un `init` nuevo (publish_id y progreso nuevos) hasta `upload_restarts` veces;
     * agotadas, falla con un mensaje claro. Limite conocido: si el ultimo trozo llego pero se perdio su respuesta, el
     * reinicio puede dejar un segundo borrador en la bandeja.
     */
    private function restartExpired(SocialAccount $account, PublishRequest $request, array $progress, PublishMedia $media, MediaChunkReader $reader): PublishResult
    {
        $restarts = (int) ($progress['restarts'] ?? 0);

        if ($restarts >= (int) config('social.tiktok.upload_restarts', 1)) {
            throw new PermanentPublishException('La subida a TikTok caduco antes de terminar (la URL de subida vale 1 hora): reprograma la publicacion.', 'subida_caducada');
        }

        Log::notice('tiktok.upload_url_expired', ['target_id' => $request->targetId, 'restarts' => $restarts]);

        [$publishId, $fresh] = $this->openUpload($account, $request, $reader, $restarts + 1);

        return $this->uploadChunks($account, $request, $publishId, $fresh, $media, $reader);
    }

    /** Continua una subida a medias (estado en `progress`) dentro de una pasada con presupuesto. */
    private function resumeUpload(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $progress = $request->progress;
        $media = $request->firstMedia();

        if ($media === null || ! $media->isVideo() || ! is_string($progress['upload_url'] ?? null)) {
            throw new PermanentPublishException('Se perdio el estado de la subida a TikTok; reprograma la publicacion.', 'sesion_perdida');
        }

        $reader = MediaChunkReader::for($media);
        $this->passStartedAt = microtime(true);

        try {
            if ($reader->size !== (int) ($progress['size'] ?? -1)) {
                throw new PermanentPublishException('El video cambio durante la subida a TikTok; reprograma la publicacion.', 'media_invalida');
            }

            $this->assertUploadHost((string) $progress['upload_url']);

            return $this->uploadChunks($account, $request, (string) $request->reference, $progress, $media, $reader);
        } finally {
            $reader->close();
            $this->passStartedAt = null;
        }
    }

    /** @return array{0: int, 1: int} [tamano de trozo, numero de trozos]; el ultimo absorbe el resto. */
    private function plan(int $size): array
    {
        if ($size <= (int) config('social.tiktok.single_chunk_max_bytes', 64 * 1024 * 1024)) {
            return [$size, 1];
        }

        $chunk = max(5 * 1024 * 1024, (int) config('social.tiktok.chunk_bytes', 10 * 1024 * 1024));

        return [$chunk, max(1, intdiv($size, $chunk))];
    }

    /**
     * Sube los trozos desde `progress.next` hasta terminar o agotar el presupuesto de la pasada. Cada trozo se envia en
     * streaming (el cuerpo es un flujo de la lectura por rango, nunca el trozo entero en memoria) y su avance se guarda.
     *
     * @param  array<string, mixed>  $progress
     */
    private function uploadChunks(SocialAccount $account, PublishRequest $request, string $publishId, array $progress, PublishMedia $media, MediaChunkReader $reader): PublishResult
    {
        $url = (string) $progress['upload_url'];
        $chunk = (int) $progress['chunk_size'];
        $count = (int) $progress['chunk_count'];
        $size = $reader->size;
        $first = true;

        for ($i = (int) ($progress['next'] ?? 0); $i < $count; $i++) {
            // La URL de subida vale 1 h: caducada, se reinicia en vez de provocar un 4xx seguro.
            if ($this->uploadUrlExpired($progress)) {
                return $this->restartExpired($account, $request, $progress, $media, $reader);
            }

            // Presupuesto comprobado ANTES de cada PUT (el primero de la pasada siempre se intenta: garantiza avance).
            if (! $first && $this->budgetLeft() < (float) config('social.tiktok.upload_min_slice_seconds', 15)) {
                return PublishResult::processing($publishId, [], $progress);
            }
            $first = false;

            $offset = $i * $chunk;
            $length = $i === $count - 1 ? $size - $offset : $chunk;
            $body = $reader->stream($offset, $length, $this->callTimeout((float) config('social.tiktok.upload_timeout', 100)));

            if ($body->getSize() !== $length) {
                $body->close();

                throw new TransientPublishException('No se pudo leer el video del almacenamiento.', 'lectura_media');
            }

            try {
                $response = Http::timeout($this->callTimeout((float) config('social.tiktok.upload_timeout', 100)))
                    ->withHeaders(['Content-Range' => 'bytes '.$offset.'-'.($offset + $length - 1).'/'.$size])
                    ->withBody($body, $media->mime)
                    ->put($url);
            } catch (ConnectionException) {
                throw new TransientPublishException('Se corto la subida del video a TikTok.', 'red');
            } finally {
                $body->close();
            }

            // 206 en los trozos intermedios y 201 en el ultimo.
            if (! in_array($response->status(), [200, 201, 206], true)) {
                // 401/403/404/410 en la URL de subida = sesion caducada o revocada: se reinicia (o falla con motivo claro).
                if (in_array($response->status(), [401, 403, 404, 410], true)) {
                    return $this->restartExpired($account, $request, $progress, $media, $reader);
                }
                if ($response->serverError() || $response->status() === 429) {
                    throw new TransientPublishException('TikTok no acepto un trozo del video ('.$response->status().'); se reintentara.', 'subida_fallida');
                }

                throw new PermanentPublishException('TikTok rechazo la subida del video ('.$response->status().').', 'subida_rechazada');
            }

            $progress['next'] = $i + 1;

            if ($progress['next'] < $count) {
                $request->checkpoint($publishId, $progress);
            }
        }

        // Subida completa: empieza el procesado (y su plazo).
        return PublishResult::processing($publishId, [], ['phase' => 'processing', 'uploaded_at' => now()->timestamp]);
    }

    /** Segundos que quedan del presupuesto blando de subida de la pasada. */
    private function budgetLeft(): float
    {
        return (float) config('social.tiktok.upload_budget_seconds', 80) - $this->elapsed();
    }

    private function elapsed(): float
    {
        return $this->passStartedAt === null ? 0.0 : microtime(true) - $this->passStartedAt;
    }

    /** Timeout HTTP acotado a lo que queda hasta la marca dura de la pasada (`pass_seconds` < `$timeout` 110 s del job). */
    private function callTimeout(float $cap): float
    {
        if ($this->passStartedAt === null) {
            return $cap;
        }

        return max(1.0, min($cap, (float) config('social.tiktok.pass_seconds', 100) - $this->elapsed()));
    }

    private function assertUploadHost(string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ok = parse_url($url, PHP_URL_SCHEME) === 'https' && array_filter(
            (array) config('social.tiktok.upload_hosts', []),
            fn ($suffix) => $host === $suffix || str_ends_with($host, '.'.$suffix),
        ) !== [];

        if (! $ok) {
            throw new PermanentPublishException('TikTok devolvio una URL de subida no valida.', 'url_subida_invalida');
        }
    }

    // ------------------------------------------------------------------ fotos

    private function publishPhotos(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $domain = rtrim((string) config('social.tiktok.verified_domain'), '/');

        if ($domain === '') {
            throw new PermanentPublishException('Las fotos de TikTok necesitan un dominio verificado (TIKTOK_VERIFIED_DOMAIN).', 'dominio_no_verificado');
        }

        if ($request->media === []) {
            throw new PermanentPublishException('Faltan las imagenes de la publicacion.', 'media_invalida');
        }

        if (! str_starts_with($domain, 'https://') || parse_url($domain, PHP_URL_HOST) === null) {
            throw new PermanentPublishException('TIKTOK_VERIFIED_DOMAIN debe ser una URL https (el dominio verificado en TikTok).', 'dominio_no_verificado');
        }

        $urls = [];
        foreach ($request->media as $m) {
            if ($m->kind !== 'image') {
                throw new PermanentPublishException('Las fotos de TikTok solo admiten imagenes.', 'media_invalida');
            }
            $urls[] = $this->onDomain($m, $domain);
        }

        $direct = self::directPost($account);
        $text = trim((string) $request->text);

        $body = [
            'media_type' => 'PHOTO',
            'post_mode' => $direct ? 'DIRECT_POST' : 'MEDIA_UPLOAD',
            'source_info' => ['source' => 'PULL_FROM_URL', 'photo_cover_index' => 0, 'photo_images' => $urls],
            'post_info' => $direct
                ? array_merge($this->postInfo($account, $request), ['title' => mb_substr((string) strtok($text, "\r\n"), 0, 90), 'description' => mb_substr($text, 0, 4000)])
                : ['title' => mb_substr((string) strtok($text, "\r\n"), 0, 90), 'description' => mb_substr($text, 0, 4000)],
        ];

        $init = $this->api($account, '/v2/post/publish/content/init/', $body);

        if (($init['publish_id'] ?? '') === '') {
            throw new PermanentPublishException('TikTok no devolvio el publish_id.', 'sin_id');
        }

        return PublishResult::processing((string) $init['publish_id']);
    }

    /**
     * URL publica de una imagen bajo el dominio verificado: `<dominio>/<clave del objeto>` (sin `/bucket` ni query firmada).
     * Exige que el dominio verificado sea el DOMINIO PUBLICO del bucket y que las imagenes sean legibles sin firma: TikTok
     * descarga la URL tal cual, y reescribir el host de una URL firmada SigV4 (o path-style `/bucket/clave`) no funciona.
     */
    private function onDomain(PublishMedia $media, string $domain): string
    {
        if ($media->path === null || $media->path === '') {
            throw new PermanentPublishException('No se encuentra la imagen en el almacenamiento.', 'media_invalida');
        }

        $root = '';
        if ($media->disk !== null && ($disk = Storage::disk($media->disk)) instanceof AwsS3V3Adapter) {
            $root = trim((string) ($disk->getConfig()['root'] ?? ''), '/'); // prefijo del bucket: forma parte de la clave
        }

        $key = ($root !== '' ? $root.'/' : '').ltrim($media->path, '/');

        return $domain.'/'.implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    // ------------------------------------------------------------------ direct post

    /** @return array<string, mixed> */
    private function postInfo(SocialAccount $account, PublishRequest $request): array
    {
        $options = (array) ($this->creatorInfo($account)['privacy_level_options'] ?? []);
        $asked = (string) ($request->options['privacy_level'] ?? '');

        // La visibilidad pedida si la cuenta la permite; si no, la mas restrictiva (nunca se abre mas de lo pedido).
        if ($asked === '' || ! in_array($asked, $options, true)) {
            $asked = in_array('SELF_ONLY', $options, true) ? 'SELF_ONLY' : (string) ($options[0] ?? 'SELF_ONLY');
        }

        return [
            'title' => mb_substr(trim((string) $request->text), 0, 2200), // video: 2.200; la foto lo recorta a 90
            'privacy_level' => $asked,
            'disable_comment' => (bool) ($request->options['disable_comment'] ?? false),
        ];
    }

    // ------------------------------------------------------------------ HTTP y errores

    /**
     * POST JSON a la API. `$body === null` envia el objeto vacio `{}` (con `[]` TikTok responde invalid_params).
     *
     * @return array<string, mixed> `data` de la respuesta
     */
    private function api(SocialAccount $account, string $path, ?array $body): array
    {
        try {
            $response = Http::timeout($this->callTimeout((float) config('social.http_timeout', 15)))->acceptJson()
                ->withToken((string) $account->access_token)
                ->withBody($body === null || $body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json; charset=UTF-8')
                ->post(self::API.$path);
        } catch (ConnectionException) {
            throw new TransientPublishException('No se pudo contactar con TikTok (tiempo agotado o red).', 'red');
        }

        $code = (string) ($response->json('error.code') ?? ($response->successful() ? 'ok' : 'http_'.$response->status()));

        if ($response->successful() && $code === 'ok') {
            return (array) ($response->json('data') ?? []);
        }

        throw $this->exception($response, $code);
    }

    private function exception(Response $response, string $code): \Throwable
    {
        Log::warning('tiktok.publish_error', ['status' => $response->status(), 'code' => $code]);

        [$kind, $text] = self::ERRORS[$code] ?? [null, null];
        $message = (string) ($response->json('error.message') ?? '');

        return match (true) {
            $kind === 'reconnect' || $response->status() === 401 => new ReconnectRequiredException(($text ?? 'TikTok rechazo las credenciales.').' Reconecta la cuenta.'),
            $kind === 'quota' => new QuotaExceededException((string) $text, null, 'cuota'),
            $kind === 'transient' || $response->serverError() || $response->status() === 429 => new TransientPublishException($text ?? 'TikTok no esta disponible temporalmente.', $code),
            $kind === 'permanent' => new PermanentPublishException((string) $text, $code),
            default => new PermanentPublishException('TikTok rechazo la publicacion ('.$code.')'.($message !== '' ? ': '.mb_substr($message, 0, 200) : '.'), $code),
        };
    }

    /** @param array<string, mixed> $data */
    private function complete(SocialAccount $account, string $publishId, array $data): PublishResult
    {
        $ids = (array) ($data['publicaly_available_post_id'] ?? $data['publicly_available_post_id'] ?? []);
        $postId = (string) ($ids[0] ?? '');

        if ($postId === '') {
            return PublishResult::published($publishId, null, [], null, ['phase' => 'done', 'external_id_kind' => self::KIND_PUBLISH_ID]);
        }

        $user = $account->username ? '@'.ltrim($account->username, '@') : '';

        return PublishResult::published($postId, $user !== '' ? "https://www.tiktok.com/{$user}/video/{$postId}" : null, [], null, ['phase' => 'done', 'external_id_kind' => 'video_id']);
    }

    private function failure(string $reason): PublishResult
    {
        [, $text] = self::ERRORS[$reason] ?? [null, null];

        return PublishResult::failed($text ?? 'TikTok no pudo procesar el contenido'.($reason !== '' ? " ({$reason})" : '').'.');
    }
}
