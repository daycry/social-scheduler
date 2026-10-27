<?php

namespace App\Connectors\Bluesky;

use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Support\MediaChunkReader;
use App\Connectors\Support\StepBudget;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use App\Services\Media\ImageDownscaler;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Publicacion en Bluesky (`app.bsky.feed.post`).
 *
 *  - Texto con *facets* (enlaces, hashtags, menciones en bytes UTF-8) y de 0 a 4 imagenes (`uploadBlob` + `aspectRatio`); las que
 *    superan `social.bluesky.image_max_bytes` se suben como la copia recomprimida de `ImageDownscaler`.
 *  - Video (`StatusPoller`): comprueba `getUploadLimits` (cupo y email verificado), pide un token de servicio, sube el MP4 en UNA
 *    peticion en streaming a `video.bsky.app` y devuelve `procesando(jobId)`; `poll` consulta `getJobStatus` y, con el *blob*
 *    listo, crea el registro.
 *
 * Toda llamada autenticada al PDS pasa por `BlueskySession::call`, que renueva la sesion si caduca. La `rkey` (una TID) se genera
 * y se persiste con `checkpoint()` ANTES de crear el registro: un reintento tras un timeout la reutiliza.
 */
class BlueskyPublisher implements Publisher, StatusPoller
{
    public const COLLECTION = 'app.bsky.feed.post';

    /** DID de servicio del video de Bluesky (aud del token de `getUploadLimits`). */
    private const VIDEO_AUD = 'did:web:video.bsky.app';

    /** Segundos minimos que tiene que quedar del presupuesto para intentar subir el video. */
    private const MIN_UPLOAD_SECONDS = 20;

    public function __construct(
        private readonly BlueskyClient $client,
        private readonly BlueskySession $session,
        private readonly ImageDownscaler $downscaler,
    ) {}

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        try {
            return match ($request->type) {
                ContentType::BlueskyPost => $this->publishPost($account, $request),
                ContentType::BlueskyVideo => $this->publishVideo($account, $request),
                default => throw new PermanentPublishException('Bluesky no admite este tipo de contenido.', 'tipo_no_admitido'),
            };
        } catch (BlueskyException $e) {
            throw BlueskyErrors::translate($e);
        }
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        if ($request->reference === null) {
            throw new PermanentPublishException('Falta la referencia del video de Bluesky.', 'sin_referencia');
        }

        try {
            return $this->pollVideo($account, $request, $request->reference);
        } catch (BlueskyException $e) {
            throw BlueskyErrors::translate($e);
        }
    }

    /**
     * ¿Ya existe el registro? La `rkey` se guarda ANTES de crearlo, asi que `getRecord` responde si un intento anterior (que acabo en
     * timeout o error) llego a crearlo. Sin `rkey` guardada no se creo nada. Un fallo de red al comprobarlo es transitorio: no se
     * publica a ciegas (duplicado) y el reintento vuelve a preguntar.
     */
    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $rkey = (string) ($request->progress['rkey'] ?? '');
        if (! Tid::isValid($rkey)) {
            return null;
        }

        try {
            $existing = $this->client->getRecord(BlueskySession::pds($account), $this->did($account), self::COLLECTION, $rkey);
        } catch (BlueskyException $e) {
            throw BlueskyErrors::translate($e);
        }
        $request->budget()->spent();

        if ($existing !== null) {
            return $this->published($account, (string) ($existing['uri'] ?? ''), $existing);
        }

        // Video aun procesandose: el trabajo sigue vivo, el sondeo lo retoma.
        return $request->reference !== null ? PublishResult::processing($request->reference, [], ['rkey' => $rkey, 'phase' => 'processing']) : null;
    }

    // ------------------------------------------------------------------ texto e imagenes

    private function publishPost(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $rkey = $this->rkey($request);

        $images = [];
        foreach ($request->media as $media) {
            $images[] = $this->uploadImage($account, $media);
            $request->budget()->spent();
        }

        $record = $this->record($request, $images === [] ? null : ['$type' => 'app.bsky.embed.images', 'images' => $images]);

        return $this->create($account, $request, $record, $rkey);
    }

    // ------------------------------------------------------------------ video

    private function publishVideo(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $media = $request->firstMedia();
        if ($media === null || ! $media->isVideo()) {
            throw new PermanentPublishException('Falta el video que publicar en Bluesky.', 'media_invalida');
        }

        $rkey = $this->rkey($request, ['phase' => 'uploading']);
        $reader = MediaChunkReader::for($media);
        $did = $this->did($account);
        $pds = BlueskySession::pds($account);

        $budget = $request->budget();

        // 1. Cupo y email verificado: getUploadLimits con un token de servicio para el servicio de video.
        $limits = $this->session->call($account, function (string $token, string $pds) {
            $service = $this->client->serviceAuth($pds, $token, self::VIDEO_AUD, 'app.bsky.video.getUploadLimits');

            return $this->client->uploadLimits($service);
        });
        $budget->spent();
        $this->assertCanUpload($limits, $reader->size);

        // 2. Token de servicio para subir (aud = DID web del PDS de la cuenta; el valor exacto se confirma en el smoke, T-30).
        $this->assertTime($budget);
        $upload = $this->session->call($account, fn (string $token, string $pds) => $this->client->serviceAuth($pds, $token, 'did:web:'.parse_url($pds, PHP_URL_HOST), 'com.atproto.repo.uploadBlob'));
        $budget->spent();

        // 3. Subida en streaming: nunca se carga el video en memoria. Su timeout nunca pasa de lo que queda del presupuesto del paso
        // (las 3 llamadas de 15 s + los 80 s fijos pasarian de los 110 s del job).
        $this->assertTime($budget, self::MIN_UPLOAD_SECONDS);
        $timeout = (int) max(self::MIN_UPLOAD_SECONDS, min((int) config('social.bluesky.upload_timeout', 80), floor($budget->remaining())));
        $body = $reader->stream(0, $reader->size);
        try {
            $job = $this->client->uploadVideo($upload, $did, 'video.mp4', $body, $timeout);
        } catch (BlueskyException $e) {
            // Mismo contenido ya subido antes (un reintento tras un timeout): el servicio devuelve el trabajo existente.
            if ($e->errorCode !== 'already_exists' || ! is_string($e->data['jobId'] ?? null)) {
                throw $e;
            }
            $job = $e->data;
        } finally {
            $body->close();
        }
        $budget->spent();

        $jobId = (string) ($job['jobId'] ?? ($job['jobStatus']['jobId'] ?? ''));
        if ($jobId === '') {
            throw new PermanentPublishException('Bluesky no devolvio el trabajo de procesado del video.', 'sin_referencia');
        }

        return PublishResult::processing($jobId, ['pds' => $pds], ['rkey' => $rkey, 'phase' => 'processing']);
    }

    /** Sin tiempo para otra llamada dentro del presupuesto del paso: se reintenta en lugar de morir por el timeout del job. */
    private function assertTime(StepBudget $budget, int $minSeconds = 0): void
    {
        if (! $budget->allows() || $budget->remaining() < $minSeconds) {
            throw new TransientPublishException('Sin tiempo para subir el video a Bluesky en este intento; se reintentara.', 'sin_tiempo');
        }
    }

    private function pollVideo(SocialAccount $account, PublishRequest $request, string $jobId): PublishResult
    {
        $rkey = $this->rkey($request);
        $status = $this->client->jobStatus($jobId);
        $request->budget()->spent();
        $state = (string) ($status['state'] ?? '');
        $progress = ['rkey' => $rkey, 'phase' => 'processing'];

        if ($state === 'JOB_STATE_FAILED') {
            return PublishResult::failed($this->jobFailure($status), ['state' => $state]);
        }

        $blob = $status['blob'] ?? null;
        if ($state !== 'JOB_STATE_COMPLETED' || ! is_array($blob)) {
            return PublishResult::processing($jobId, ['state' => $state, 'progress' => $status['progress'] ?? null], $progress);
        }

        $media = $request->firstMedia();
        $embed = array_filter([
            '$type' => 'app.bsky.embed.video',
            'video' => $blob,
            'aspectRatio' => $media?->width && $media->height ? ['width' => (int) $media->width, 'height' => (int) $media->height] : null,
        ], fn ($v) => $v !== null);

        return $this->create($account, $request, $this->record($request, $embed), $rkey);
    }

    /** @param array<string, mixed> $limits */
    private function assertCanUpload(array $limits, int $size): void
    {
        if (($limits['canUpload'] ?? false) !== true) {
            $code = (string) ($limits['error'] ?? '');
            $message = (string) ($limits['message'] ?? '');

            throw new PermanentPublishException(match (true) {
                $code === 'unconfirmed_email' || str_contains(strtolower($message), 'email') => 'Bluesky solo permite subir videos con el email de la cuenta verificado: verificalo en los ajustes de Bluesky y reprograma la publicacion.',
                default => 'Bluesky no permite subir videos con esta cuenta ahora'.($message !== '' ? ': '.mb_substr($message, 0, 200) : '.'),
            }, 'video_no_permitido');
        }

        $videos = $limits['remainingDailyVideos'] ?? null;
        $bytes = $limits['remainingDailyBytes'] ?? null;
        if ((is_int($videos) && $videos <= 0) || (is_int($bytes) && $bytes < $size)) {
            throw new QuotaExceededException('Has alcanzado el limite diario de videos de Bluesky para esta cuenta.');
        }
    }

    /** @param array<string, mixed> $status */
    private function jobFailure(array $status): string
    {
        $code = (string) ($status['error'] ?? $status['failureCode'] ?? '');
        $message = trim((string) ($status['message'] ?? ''));

        return 'Bluesky no pudo procesar el video'.($code !== '' ? " ($code)" : '').($message !== '' ? ': '.mb_substr($message, 0, 200) : '.');
    }

    // ------------------------------------------------------------------ registro

    /**
     * Registro `app.bsky.feed.post`.
     *
     * @param  ?array<string, mixed>  $embed
     * @return array<string, mixed>
     */
    private function record(PublishRequest $request, ?array $embed): array
    {
        $text = trim((string) $request->text);
        $facets = Facets::build($text, fn (string $handle) => $this->resolveMention($handle));

        return array_filter([
            '$type' => self::COLLECTION,
            'text' => $text,
            'createdAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'langs' => array_slice((array) config('social.bluesky.default_langs', []), 0, 3) ?: null,
            'facets' => $facets ?: null,
            'embed' => $embed,
        ], fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $record */
    private function create(SocialAccount $account, PublishRequest $request, array $record, string $rkey): PublishResult
    {
        try {
            $created = $this->session->call($account, fn (string $token, string $pds) => $this->client->createRecord($pds, $token, $this->did($account), self::COLLECTION, $record, $rkey));
        } catch (BlueskyException $e) {
            // Ante CUALQUIER error de createRecord (timeout con la respuesta perdida, 5xx, o la `rkey` ya usada: el error exacto del
            // PDS no esta verificado) se comprueba con getRecord antes de fallar: si el registro existe, esta publicado.
            if (($existing = $this->recordIfExists($account, $rkey)) !== null) {
                return $this->published($account, (string) ($existing['uri'] ?? ''), $existing);
            }

            throw $e;
        } finally {
            $request->budget()->spent();
        }

        return $this->published($account, (string) ($created['uri'] ?? ''), $created);
    }

    /**
     * `rkey` del destino: la guardada en el progreso o una TID nueva, persistida YA (antes de cualquier paso irreversible).
     *
     * @param  array<string, mixed>  $extra  Claves de progreso adicionales.
     */
    private function rkey(PublishRequest $request, array $extra = []): string
    {
        $rkey = (string) ($request->progress['rkey'] ?? '');
        if (Tid::isValid($rkey)) {
            return $rkey;
        }

        $rkey = Tid::generate();
        $request->checkpoint(null, ['rkey' => $rkey] + $extra);

        return $rkey;
    }

    /**
     * Sube una imagen (o su copia recomprimida) y devuelve el elemento de `app.bsky.embed.images`.
     *
     * @return array<string, mixed>
     */
    private function uploadImage(SocialAccount $account, PublishMedia $media): array
    {
        if ($media->isVideo() || $media->disk === null || $media->path === null) {
            throw new PermanentPublishException('Bluesky solo admite imagenes en este tipo de publicacion.', 'tipo_no_admitido');
        }

        // La recompresion corre aqui, en el job (nunca en la peticion web): si la imagen supera el tope de pixeles se rechaza con un
        // mensaje. La memoria se comprueba dentro del reductor, solo en el respaldo sin ffmpeg.
        if ((int) $media->size > (int) config('social.bluesky.image_max_bytes') && ImageDownscaler::exceedsPixelCap($media->width, $media->height)) {
            throw new PermanentPublishException('La imagen es demasiado grande para Bluesky y no se puede reducir de forma segura. Usa una imagen mas pequena.', 'archivo_grande');
        }

        $fit = $this->downscaler->fitFile($media->disk, $media->path, $media->id, (int) $media->size, $media->mime, (int) config('social.bluesky.image_max_bytes'));
        if ($fit === null) {
            throw new PermanentPublishException('La imagen es demasiado grande para Bluesky y no se ha podido reducir.', 'archivo_grande');
        }

        try {
            $bytes = (string) Storage::disk($fit['disk'])->get($fit['path']);
        } catch (Throwable) {
            throw new PermanentPublishException('No se pudo leer una de las imagenes.', 'media_ilegible');
        }

        if ($bytes === '') {
            throw new PermanentPublishException('No se pudo leer una de las imagenes.', 'media_ilegible');
        }

        $blob = $this->session->call($account, fn (string $token, string $pds) => $this->client->uploadBlob($pds, $token, $bytes, $fit['mime']));

        // Proporcion de lo que realmente se sube (la copia puede tener otro tamano que la original).
        // Si es la original (no la copia) y lleva orientacion EXIF 5-8, los clientes la muestran girada: ancho y alto se intercambian.
        $size = @getimagesizefromstring($bytes);
        $width = $size !== false ? $size[0] : $media->width;
        $height = $size !== false ? $size[1] : $media->height;
        if ($size !== false && ! $fit['derived'] && $size[2] === IMAGETYPE_JPEG && ImageDownscaler::swapsAxes(ImageDownscaler::exifOrientation($bytes))) {
            [$width, $height] = [$height, $width];
        }

        return array_filter([
            'alt' => '',
            'image' => $blob,
            'aspectRatio' => $width && $height ? ['width' => (int) $width, 'height' => (int) $height] : null,
        ], fn ($v) => $v !== null);
    }

    // ------------------------------------------------------------------ utilidades

    /** @return ?array<string, mixed> El registro si existe; null si no existe o no se pudo comprobar (se propaga el error original). */
    private function recordIfExists(SocialAccount $account, string $rkey): ?array
    {
        try {
            return $this->client->getRecord(BlueskySession::pds($account), $this->did($account), self::COLLECTION, $rkey);
        } catch (Throwable) {
            return null;
        }
    }

    /** DID de la cuenta (repositorio donde se escribe). */
    private function did(SocialAccount $account): string
    {
        return (string) ($account->meta['did'] ?? $account->external_id);
    }

    /** DID de un handle mencionado, o null si no existe o falla la consulta (la mencion queda como texto plano). */
    private function resolveMention(string $handle): ?string
    {
        try {
            return $this->client->resolveHandle($handle);
        } catch (BlueskyException) {
            return null;
        }
    }

    /** @param array<string, mixed> $created */
    private function published(SocialAccount $account, string $uri, array $created): PublishResult
    {
        if (! preg_match('~^at://[^/]+/app\.bsky\.feed\.post/([^/]+)$~', $uri, $m)) {
            throw new PermanentPublishException('Bluesky no devolvio el identificador de la publicacion.', 'sin_id');
        }

        $profile = (string) ($account->meta['handle'] ?? $account->username ?? $this->did($account));

        return PublishResult::published($uri, 'https://bsky.app/profile/'.$profile.'/post/'.$m[1], ['cid' => $created['cid'] ?? null]);
    }
}
