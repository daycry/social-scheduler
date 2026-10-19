<?php

namespace App\Connectors\Meta\Instagram;

use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Meta\GraphClient;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

/**
 * Publicacion en Instagram (cuenta profesional enlazada a una Pagina) con el flujo del spike:
 *   contenedor `/{ig}/media` -> espera de `status_code` -> `/{ig}/media_publish`.
 * Tipos: imagen, carrusel, reel y story. Necesita `instagram_content_publish`.
 *
 * Fases (se guardan en `external_reference` mientras el destino esta `procesando`):
 *   `child:ID1,ID2,...`  hijos del carrusel aun sin terminar de procesar
 *   `ID`                 contenedor creado; se publica cuando `status_code` = FINISHED
 *
 * Idempotencia: `findPublished` busca en la media reciente de la cuenta una publicacion con la misma
 * descripcion posterior a la hora programada (las publicaciones sin texto no se pueden reconocer y se
 * documentan como limitacion); un contenedor ya `PUBLISHED` se resuelve igual. Antes de crear contenedores
 * se consulta `content_publishing_limit`.
 */
class InstagramPublisher implements Publisher, StatusPoller
{
    /** Cuantas publicaciones recientes se revisan al buscar un duplicado. */
    private const RECENT_MEDIA = 25;

    public function __construct(private readonly GraphClient $graph = new GraphClient) {}

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $this->assertSupported($request);
        $this->assertQuota($account);

        $reference = $request->type === ContentType::InstagramCarousel
            ? $this->createCarouselChildren($account, $request)
            : $this->createContainer($account, $this->containerParams($request));

        return $this->advance($account, $request, $reference);
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        if ($request->reference === null) {
            throw new PermanentPublishException('Falta la referencia del contenedor de Instagram.', 'sin_referencia');
        }

        return $this->advance($account, $request, $request->reference);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        // Un contenedor ya publicado (o a punto) se resuelve por su estado, sin crear otro.
        if ($request->reference !== null && ! str_starts_with($request->reference, 'child:')) {
            $status = $this->containerStatus($account, $request->reference);

            if ($status['code'] === 'PUBLISHED') {
                return $this->findRecent($account, $request) ?? PublishResult::processing($request->reference);
            }

            if ($status['code'] === 'FINISHED' || $status['code'] === 'IN_PROGRESS') {
                return PublishResult::processing($request->reference);
            }
        }

        return $this->findRecent($account, $request);
    }

    // ------------------------------------------------------------------ flujo

    /** Avanza la publicacion un paso: hijos -> contenedor -> publicar. */
    private function advance(SocialAccount $account, PublishRequest $request, string $reference): PublishResult
    {
        if (str_starts_with($reference, 'child:')) {
            $ids = explode(',', substr($reference, 6));

            foreach ($ids as $id) {
                $status = $this->containerStatus($account, $id);

                if (in_array($status['code'], ['ERROR', 'EXPIRED'], true)) {
                    return PublishResult::failed($this->statusMessage($status));
                }

                if ($status['code'] !== 'FINISHED') {
                    return PublishResult::processing($reference);
                }
            }

            $reference = $this->createContainer($account, [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $ids),
                'caption' => (string) $request->text,
            ]);
        }

        $status = $this->containerStatus($account, $reference);

        return match ($status['code']) {
            'FINISHED' => $this->publishContainer($account, $reference),
            'PUBLISHED' => $this->findRecent($account, $request) ?? PublishResult::failed('Instagram indica que el contenedor ya se publico pero no se encuentra la publicacion.'),
            'ERROR', 'EXPIRED' => PublishResult::failed($this->statusMessage($status)),
            default => PublishResult::processing($reference), // IN_PROGRESS u otro estado intermedio
        };
    }

    private function publishContainer(SocialAccount $account, string $containerId): PublishResult
    {
        $published = $this->graph->post($account->external_id.'/media_publish', ['creation_id' => $containerId], (string) $account->access_token);
        $id = (string) ($published['id'] ?? '');

        if ($id === '') {
            throw new PermanentPublishException('Instagram no devolvio el ID de la publicacion.', 'sin_id');
        }

        return PublishResult::published($id, $this->permalink($account, $id), ['container' => $containerId]);
    }

    private function createCarouselChildren(SocialAccount $account, PublishRequest $request): string
    {
        $ids = [];

        foreach ($request->media as $media) {
            $ids[] = $this->createContainer($account, $this->mediaParams($media) + ['is_carousel_item' => 'true']);
        }

        return 'child:'.implode(',', $ids);
    }

    /** @return array<string, string> */
    private function containerParams(PublishRequest $request): array
    {
        $media = $request->firstMedia();
        $caption = (string) $request->text;

        return match ($request->type) {
            ContentType::InstagramImage => ['image_url' => $media->url] + ($caption !== '' ? ['caption' => $caption] : []),
            ContentType::InstagramReel => [
                'media_type' => 'REELS',
                'video_url' => $media->url,
                'share_to_feed' => ($request->options['share_to_feed'] ?? true) ? 'true' : 'false',
            ] + ($caption !== '' ? ['caption' => $caption] : []),
            // Las historias no admiten texto: la descripcion se ignora (limitacion avisada en el compositor).
            ContentType::InstagramStory => ['media_type' => 'STORIES'] + $this->mediaParams($media),
            default => throw new PermanentPublishException('Tipo de contenido no admitido en Instagram.', 'tipo_no_admitido'),
        };
    }

    /** @return array<string, string> */
    private function mediaParams(PublishMedia $media): array
    {
        return $media->isVideo()
            ? ['media_type' => 'VIDEO', 'video_url' => $media->url]
            : ['image_url' => $media->url];
    }

    /** @param array<string, string> $params */
    private function createContainer(SocialAccount $account, array $params): string
    {
        $created = $this->graph->post($account->external_id.'/media', $params, (string) $account->access_token);
        $id = (string) ($created['id'] ?? '');

        if ($id === '') {
            throw new PermanentPublishException('Instagram no devolvio el contenedor de media.', 'sin_contenedor');
        }

        return $id;
    }

    // ------------------------------------------------------------------ consultas

    /** @return array{code: string, status: ?string} */
    private function containerStatus(SocialAccount $account, string $containerId): array
    {
        $data = $this->graph->get($containerId, ['fields' => 'status_code,status'], (string) $account->access_token);

        return ['code' => (string) ($data['status_code'] ?? 'IN_PROGRESS'), 'status' => $data['status'] ?? null];
    }

    /** @param array{code: string, status: ?string} $status */
    private function statusMessage(array $status): string
    {
        $detail = (string) ($status['status'] ?? '');

        if (preg_match('/(\d{7})/', $detail, $m)) {
            $text = match ((int) $m[1]) {
                2207026 => 'el formato del video no es admitido (usa MP4/MOV con H.264 y audio AAC)',
                2207003 => 'Instagram tardo demasiado en descargar el archivo',
                2207052 => 'Instagram no pudo descargar el archivo desde la URL',
                2207023 => 'el tipo de contenido no es admitido para la cuenta',
                default => null,
            };

            if ($text !== null) {
                return 'Instagram no pudo procesar el contenido: '.$text.'.';
            }
        }

        return $status['code'] === 'EXPIRED'
            ? 'El contenedor de media de Instagram caduco antes de publicarse.'
            : 'Instagram no pudo procesar el contenido'.($detail !== '' ? ': '.mb_substr($detail, 0, 200) : '.');
    }

    private function permalink(SocialAccount $account, string $mediaId): ?string
    {
        try {
            $data = $this->graph->get($mediaId, ['fields' => 'permalink'], (string) $account->access_token);

            return $data['permalink'] ?? null;
        } catch (PublishException $e) {
            Log::notice('instagram.permalink_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            return null; // la publicacion ya existe: el enlace es accesorio
        }
    }

    private function findRecent(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $caption = trim((string) $request->text);

        if ($caption === '') {
            return null; // sin texto no hay forma fiable de reconocer la publicacion
        }

        $data = $this->graph->get($account->external_id.'/media', ['fields' => 'id,caption,permalink,timestamp', 'limit' => self::RECENT_MEDIA], (string) $account->access_token);

        foreach ($data['data'] ?? [] as $item) {
            $when = isset($item['timestamp']) ? strtotime((string) $item['timestamp']) : false;

            if (trim((string) ($item['caption'] ?? '')) === $caption && ($request->since === null || ($when !== false && $when >= $request->since->getTimestamp()))) {
                return PublishResult::published((string) $item['id'], $item['permalink'] ?? null);
            }
        }

        return null;
    }

    private function assertSupported(PublishRequest $request): void
    {
        $media = $request->media;

        if ($request->type === ContentType::InstagramCarousel) {
            if (count($media) < 2 || count($media) > 10) {
                throw new PermanentPublishException('Un carrusel de Instagram necesita entre 2 y 10 archivos.', 'carrusel_invalido');
            }

            return;
        }

        if (! in_array($request->type, [ContentType::InstagramImage, ContentType::InstagramReel, ContentType::InstagramStory], true) || $request->firstMedia() === null) {
            throw new PermanentPublishException('Tipo de contenido no admitido en Instagram.', 'tipo_no_admitido');
        }
    }

    /** Consulta el limite de publicaciones (24 h) antes de gastar un contenedor. Si no se puede consultar, se intenta igual. */
    private function assertQuota(SocialAccount $account): void
    {
        try {
            $data = $this->graph->get($account->external_id.'/content_publishing_limit', ['fields' => 'quota_usage,config'], (string) $account->access_token);
        } catch (QuotaExceededException $e) {
            throw $e;
        } catch (PublishException $e) {
            Log::notice('instagram.quota_check_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            return;
        }

        $row = $data['data'][0] ?? null;
        $used = (int) ($row['quota_usage'] ?? 0);
        $total = (int) ($row['config']['quota_total'] ?? 0);

        if ($total > 0 && $used >= $total) {
            throw new QuotaExceededException("Instagram permite $total publicaciones cada 24 h y ya se han usado $used.", now()->addHour());
        }
    }
}
