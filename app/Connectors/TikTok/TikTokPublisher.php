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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
 * `external_reference` = publish_id.
 */
class TikTokPublisher implements Publisher, StatusPoller
{
    public const INBOX_NOTE = 'Enviado a la bandeja de TikTok: abre la app y termina de publicarlo desde tus borradores.';

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

        $data = $this->api($account, '/v2/post/publish/status/fetch/', ['publish_id' => $request->reference]);
        $status = (string) ($data['status'] ?? '');

        return match ($status) {
            'PUBLISH_COMPLETE' => $this->complete($account, $request->reference, $data),
            'SEND_TO_USER_INBOX' => PublishResult::published($request->reference, null, [], self::INBOX_NOTE),
            'FAILED' => $this->failure((string) ($data['fail_reason'] ?? '')),
            default => PublishResult::processing($request->reference), // PROCESSING_UPLOAD | PROCESSING_DOWNLOAD
        };
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

        try {
            if ($reader->size <= 0) {
                throw new PermanentPublishException('El archivo de video esta vacio.', 'media_invalida');
            }

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
            $this->uploadChunks($uploadUrl, $media, $reader, $chunk, $count);

            return PublishResult::processing($publishId);
        } finally {
            $reader->close();
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

    private function uploadChunks(string $url, PublishMedia $media, MediaChunkReader $reader, int $chunk, int $count): void
    {
        $size = $reader->size;
        $offset = 0;

        for ($i = 0; $i < $count; $i++) {
            $length = $i === $count - 1 ? $size - $offset : $chunk;
            $data = $reader->chunk($offset, $length);

            if (strlen($data) !== $length) {
                throw new TransientPublishException('No se pudo leer el video del almacenamiento.', 'lectura_media');
            }

            try {
                $response = Http::timeout((int) config('social.tiktok.upload_timeout', 100))
                    ->withHeaders(['Content-Range' => 'bytes '.$offset.'-'.($offset + $length - 1).'/'.$size])
                    ->withBody($data, $media->mime)
                    ->put($url);
            } catch (ConnectionException) {
                throw new TransientPublishException('Se corto la subida del video a TikTok.', 'red');
            }
            unset($data);

            // 206 en los trozos intermedios y 201 en el ultimo.
            if (! in_array($response->status(), [200, 201, 206], true)) {
                if ($response->serverError() || $response->status() === 429) {
                    throw new TransientPublishException('TikTok no acepto un trozo del video ('.$response->status().'); se reintentara.', 'subida_fallida');
                }

                throw new PermanentPublishException('TikTok rechazo la subida del video ('.$response->status().').', 'subida_rechazada');
            }

            $offset += $length;
        }
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

        $urls = [];
        foreach ($request->media as $m) {
            if ($m->kind !== 'image') {
                throw new PermanentPublishException('Las fotos de TikTok solo admiten imagenes.', 'media_invalida');
            }
            $urls[] = $this->onDomain($m->url, $domain);
        }

        $direct = self::directPost($account);
        $text = trim((string) $request->text);

        $body = [
            'media_type' => 'PHOTO',
            'post_mode' => $direct ? 'DIRECT_POST' : 'MEDIA_UPLOAD',
            'source_info' => ['source' => 'PULL_FROM_URL', 'photo_cover_index' => 0, 'photo_images' => $urls],
            'post_info' => $direct
                ? $this->postInfo($account, $request) + ['description' => mb_substr($text, 0, 4000)]
                : ['title' => mb_substr((string) strtok($text, "\r\n"), 0, 90), 'description' => mb_substr($text, 0, 4000)],
        ];

        $init = $this->api($account, '/v2/post/publish/content/init/', $body);

        if (($init['publish_id'] ?? '') === '') {
            throw new PermanentPublishException('TikTok no devolvio el publish_id.', 'sin_id');
        }

        return PublishResult::processing((string) $init['publish_id']);
    }

    /** La URL firmada de media debe colgar del dominio verificado (se reescribe el host). */
    private function onDomain(string $url, string $domain): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        return $domain.(string) parse_url($url, PHP_URL_PATH).($query ? '?'.$query : '');
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
            'title' => mb_substr(trim((string) $request->text), 0, 2200),
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
            $response = Http::timeout((int) config('social.http_timeout', 15))->acceptJson()
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
            return PublishResult::published($publishId);
        }

        $user = $account->username ? '@'.ltrim($account->username, '@') : '';

        return PublishResult::published($postId, $user !== '' ? "https://www.tiktok.com/{$user}/video/{$postId}" : null);
    }

    private function failure(string $reason): PublishResult
    {
        [, $text] = self::ERRORS[$reason] ?? [null, null];

        return PublishResult::failed($text ?? 'TikTok no pudo procesar el contenido'.($reason !== '' ? " ({$reason})" : '').'.');
    }
}
