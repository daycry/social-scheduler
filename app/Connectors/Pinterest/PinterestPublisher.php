<?php

namespace App\Connectors\Pinterest;

use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Storage;

/**
 * Publicacion de pines en Pinterest (T-18, `POST /v5/pins`). Imagen (`image_url`: Pinterest descarga la URL temporal firmada de la app),
 * carrusel de 2 a 5 imagenes (`multiple_image_urls`) y video: se registra (`POST /v5/media`), se sube a la URL prefirmada, y el pin se crea
 * cuando `GET /v5/media/{id}` dice `succeeded` (el destino queda `procesando` con el `media_id` como referencia y lo cierra `poll`).
 * El tablero (`pinterest_board_id`) es obligatorio; titulo, enlace y alt son opcionales; la portada del video va como `cover_image_url`.
 * Antes de crear un pin se buscan los ultimos del usuario por si ya existe (reintento tras un timeout): nunca se duplica.
 */
class PinterestPublisher implements Publisher, StatusPoller
{
    private const RECENT = 25;

    public function __construct(private readonly PinterestClient $client = new PinterestClient) {}

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $this->assertSupported($request);
        $token = $this->token($account);

        if ($request->type !== ContentType::PinterestVideo) {
            return $this->createPin($token, $request, $this->mediaSource($request));
        }

        $media = $request->media[0];
        $max = (int) config('media_specs.types.pinterest_video.video.max_bytes');
        // Un video programado antes de bajar el tope: fallo permanente y claro, no un reintento que registre medias.
        if ($max > 0 && (int) $media->size > $max) {
            throw new PermanentPublishException('El video supera '.intdiv($max, 1024 * 1024).' MB para Pinterest.', 'video_grande');
        }
        if ($media->disk === null || $media->path === null || ! Storage::disk($media->disk)->exists($media->path)) {
            throw new PermanentPublishException('El video no esta disponible en el almacenamiento para subirlo a Pinterest.', 'media_no_disponible');
        }
        $disk = Storage::disk($media->disk);
        $size = (int) $disk->size($media->path);
        if ($max > 0 && $size > $max) {
            throw new PermanentPublishException('El video supera '.intdiv($max, 1024 * 1024).' MB para Pinterest.', 'video_grande');
        }

        $budget = $request->budget();
        $saved = $request->progress['pinterest_upload'] ?? null;
        if ($request->reference !== null && preg_match('/^\d{1,30}$/D', $request->reference) && is_array($saved) && ! empty($saved['url'])) {
            // Reintento tras un fallo de la subida: el media ya esta registrado, se reutiliza (nunca se registra otro).
            $mediaId = $request->reference;
            $uploadUrl = (string) $saved['url'];
            $parameters = (array) ($saved['parameters'] ?? []);
        } else {
            // Registrar (hasta `http_timeout`) + subir (minimo `upload_min_seconds`) tienen que caber en el paso: si no, se reprograma limpio.
            $needed = (int) config('social.http_timeout', 15) + (int) config('social.pinterest.upload_min_seconds', 15);
            if ($budget->remaining() < $needed) {
                throw new TransientPublishException('Sin tiempo para subir el video a Pinterest en este intento; se reintentara.', 'sin_tiempo');
            }
            $registered = $this->client->api('POST', 'v5/media', $token, ['media_type' => 'video'])->json() ?? [];
            $budget->spent();
            if (empty($registered['media_id']) || empty($registered['upload_url'])) {
                throw new PermanentPublishException('Pinterest no devolvio donde subir el video.', 'sin_subida');
            }
            $mediaId = (string) $registered['media_id'];
            $uploadUrl = (string) $registered['upload_url'];
            $parameters = (array) ($registered['upload_parameters'] ?? []);
            // La URL prefirmada queda en el progreso para que un reintento no registre otro media.
            $request->checkpoint($mediaId, ['pinterest_upload' => ['url' => $uploadUrl, 'parameters' => $parameters]]);
        }

        $timeout = min((int) config('social.pinterest.upload_timeout', 100), (int) floor($budget->remaining()));
        if ($timeout < (int) config('social.pinterest.upload_min_seconds', 15)) {
            throw new TransientPublishException('Sin tiempo para subir el video a Pinterest en este intento; se reintentara.', 'sin_tiempo');
        }

        // En streaming: el video (hasta `media_specs.types.pinterest_video.video.max_bytes`) nunca se carga entero en memoria.
        $stream = $disk->readStream($media->path);
        if (! is_resource($stream)) {
            throw new PermanentPublishException('El video no esta disponible en el almacenamiento para subirlo a Pinterest.', 'media_no_disponible');
        }
        try {
            $this->client->upload($uploadUrl, $parameters, $stream, $media->mime, $size, $timeout);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return PublishResult::processing($mediaId, ['media_status' => 'registered']);
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $this->assertSupported($request);
        if ($request->reference === null || ! preg_match('/^\d{1,30}$/D', $request->reference)) {
            throw new PermanentPublishException('Falta la referencia del video de Pinterest.', 'sin_referencia');
        }
        $token = $this->token($account);

        $status = $this->client->api('GET', 'v5/media/'.$request->reference, $token)->json() ?? [];
        $state = strtolower((string) ($status['status'] ?? ''));
        if ($state === 'failed') {
            throw new PermanentPublishException('Pinterest no pudo procesar el video ('.mb_substr((string) ($status['failure_code'] ?? 'desconocido'), 0, 80).').', 'video_fallido');
        }
        if ($state !== 'succeeded') {
            return PublishResult::processing($request->reference, ['media_status' => $state]);
        }

        // El pin pudo crearse en un intento anterior cuya respuesta se perdio.
        if (($existing = $this->findPublished($account, $request)) !== null) {
            return $existing;
        }

        return $this->createPin($token, $request, ['source_type' => 'video_id', 'media_id' => $request->reference]
            + ($request->media[0]->cover !== null ? ['cover_image_url' => $request->media[0]->cover->url] : ['cover_image_key_frame_time' => 0]));
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $this->assertSupported($request);
        $board = (string) ($request->options['pinterest_board_id'] ?? '');
        $title = PinterestPostOptions::title($request->options, $request->text);
        $description = (string) $request->text;
        $since = $request->since?->getTimestamp();

        $items = (array) ($this->client->api('GET', 'v5/pins', $this->token($account), query: ['page_size' => self::RECENT], read: true)->json('items') ?? []);
        foreach ($items as $pin) {
            $created = isset($pin['created_at']) ? strtotime((string) $pin['created_at']) : null;
            if ((string) ($pin['board_id'] ?? '') === $board && (string) ($pin['title'] ?? '') === (string) $title
                && (string) ($pin['description'] ?? '') === $description && ! empty($pin['id'])
                && ($since === null || ($created !== false && $created !== null && $created >= $since))) {
                return PublishResult::published((string) $pin['id'], 'https://www.pinterest.com/pin/'.$pin['id'].'/');
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ interno

    /** @param array<string, mixed> $source */
    private function createPin(string $token, PublishRequest $request, array $source): PublishResult
    {
        $alt = $request->type === ContentType::PinterestPin ? ($request->media[0]->alt ?? null) : null;
        $body = array_filter([
            'board_id' => (string) $request->options['pinterest_board_id'],
            'title' => PinterestPostOptions::title($request->options, $request->text),
            'description' => ($request->text ?? '') !== '' ? $request->text : null,
            'link' => $request->options['pinterest_link'] ?? null,
            'alt_text' => $alt !== null && $alt !== '' ? $alt : null,
        ], fn ($v) => $v !== null) + ['media_source' => $source];

        $pin = $this->client->api('POST', 'v5/pins', $token, $body)->json() ?? [];
        if (empty($pin['id'])) {
            throw new PermanentPublishException('Pinterest acepto el pin pero no devolvio su identificador.', 'sin_id');
        }

        return PublishResult::published((string) $pin['id'], 'https://www.pinterest.com/pin/'.$pin['id'].'/');
    }

    /** @return array<string, mixed> */
    private function mediaSource(PublishRequest $request): array
    {
        if ($request->type === ContentType::PinterestCarousel) {
            return ['source_type' => 'multiple_image_urls', 'items' => array_map(fn ($m) => ['url' => $m->url], $request->media)];
        }

        return ['source_type' => 'image_url', 'url' => $request->media[0]->url];
    }

    private function assertSupported(PublishRequest $request): void
    {
        if ($request->type->network()->value !== 'pinterest') {
            throw new PermanentPublishException('Pinterest no puede publicar contenido de '.$request->type->network()->label().'.', 'tipo_no_admitido');
        }
        if (($errors = PinterestPostOptions::errors($request->options, $request->type)) !== []) {
            throw new PermanentPublishException($errors[0], 'opciones');
        }
        $max = (int) config('social.pinterest.description_max', 800);
        if (mb_strlen((string) $request->text) > $max) {
            throw new PermanentPublishException("Pinterest admite hasta $max caracteres en la descripcion del pin.", 'texto_largo');
        }
        if ($request->media === []) {
            throw new PermanentPublishException('Un pin de Pinterest necesita imagen o video.', 'sin_media');
        }
    }

    private function token(SocialAccount $account): string
    {
        $token = (string) $account->access_token;
        if ($token === '') {
            throw new ReconnectRequiredException('La cuenta de Pinterest no tiene token: reconecta la cuenta.');
        }

        return $token;
    }
}
