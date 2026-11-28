<?php

namespace App\Connectors\Meta\Instagram;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Meta\GraphClient;
use App\Connectors\Support\ContainerNetwork;
use App\Connectors\Support\StepBudget;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

/**
 * Ganchos de Instagram para `ContainerPublishFlow`: contenedor `/{ig}/media` -> estado `status_code` -> `/{ig}/media_publish`.
 * Antes de crear contenedores se consulta `content_publishing_limit`. `findRecent` busca en la media reciente una publicacion con la
 * misma descripcion posterior a la hora programada (las sin texto no se pueden reconocer: limitacion documentada); las historias,
 * que no se listan en `/media`, se resuelven por `/{ig}/stories`.
 */
class InstagramContainers implements ContainerNetwork
{
    /** Cuantas publicaciones recientes se revisan al buscar un duplicado. */
    private const RECENT_MEDIA = 25;

    public function __construct(private readonly GraphClient $graph) {}

    public function label(): string
    {
        return 'Instagram';
    }

    public function carouselType(): ContentType
    {
        return ContentType::InstagramCarousel;
    }

    public function assertQuota(SocialAccount $account): void
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

    public function containerParams(PublishRequest $request): array
    {
        $media = $request->firstMedia();
        $caption = (string) $request->text;

        return (match ($request->type) {
            ContentType::InstagramImage => ['image_url' => $media->url] + ($caption !== '' ? ['caption' => $caption] : []) + $this->altParams($media),
            ContentType::InstagramReel => [
                'media_type' => 'REELS',
                'video_url' => $media->url,
                'share_to_feed' => ($request->options['share_to_feed'] ?? true) ? 'true' : 'false',
            ] + ($caption !== '' ? ['caption' => $caption] : []) + ($media->cover !== null ? ['cover_url' => $media->cover->url] : []) + (isset($request->options['instagram_audio']) ? [
                'audio_configuration' => json_encode([
                    'audio_id' => $request->options['instagram_audio']['audio_id'],
                    'audio_volume' => (int) ($request->options['instagram_audio']['audio_volume'] ?? 100),
                    'video_volume' => (int) ($request->options['instagram_audio']['video_volume'] ?? 100),
                ], JSON_THROW_ON_ERROR),
            ] : []),
            // Las historias no admiten texto: la descripcion se ignora (limitacion avisada en el compositor).
            ContentType::InstagramStory => ['media_type' => 'STORIES'] + $this->mediaParams($media),
            default => throw new PermanentPublishException('Tipo de contenido no admitido en Instagram.', 'tipo_no_admitido'),
        }) + InstagramPostOptions::containerParams($request->options);
    }

    public function childParams(PublishMedia $media): array
    {
        return $this->mediaParams($media) + ['is_carousel_item' => 'true'];
    }

    public function parentParams(PublishRequest $request, array $children): array
    {
        return [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => (string) $request->text,
        ] + InstagramPostOptions::containerParams($request->options);
    }

    public function createContainer(SocialAccount $account, array $params): string
    {
        $token = isset($params['audio_configuration']) ? $account->refresh_token : $account->access_token;
        $created = $this->graph->post($account->external_id.'/media', $params, (string) $token);
        $id = (string) ($created['id'] ?? '');

        if ($id === '') {
            throw new PermanentPublishException('Instagram no devolvio el contenedor de media.', 'sin_contenedor');
        }

        return $id;
    }

    public function containerStatus(SocialAccount $account, string $containerId): array
    {
        $data = $this->graph->get($containerId, ['fields' => 'status_code,status'], (string) $account->access_token);

        return ['code' => (string) ($data['status_code'] ?? 'IN_PROGRESS'), 'detail' => isset($data['status']) ? (string) $data['status'] : null];
    }

    public function failureMessage(array $status): string
    {
        $detail = (string) ($status['detail'] ?? '');

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

    public function publishContainer(SocialAccount $account, string $containerId): string
    {
        $published = $this->graph->post($account->external_id.'/media_publish', ['creation_id' => $containerId], (string) $account->access_token);
        $id = (string) ($published['id'] ?? '');

        if ($id === '') {
            throw new PermanentPublishException('Instagram no devolvio el ID de la publicacion.', 'sin_id');
        }

        return $id;
    }

    public function permalink(SocialAccount $account, string $mediaId): ?string
    {
        try {
            $data = $this->graph->get($mediaId, ['fields' => 'permalink'], (string) $account->access_token);

            return $data['permalink'] ?? null;
        } catch (PublishException $e) {
            Log::notice('instagram.permalink_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            return null; // la publicacion ya existe: el enlace es accesorio
        }
    }

    public function findRecent(SocialAccount $account, PublishRequest $request, StepBudget $budget): ?PublishResult
    {
        $caption = trim((string) $request->text);

        // Las historias no llevan texto ni salen en `/media`: se resuelven por su contenedor (ver locatePublished).
        if ($caption === '' || $request->type === ContentType::InstagramStory) {
            return null; // sin texto no hay forma fiable de reconocer la publicacion
        }

        $data = $this->graph->get($account->external_id.'/media', ['fields' => 'id,caption,permalink,timestamp', 'limit' => self::RECENT_MEDIA], (string) $account->access_token);
        $budget->spent();

        foreach ($data['data'] ?? [] as $item) {
            $when = isset($item['timestamp']) ? strtotime((string) $item['timestamp']) : false;

            if (trim((string) ($item['caption'] ?? '')) === $caption && ($request->since === null || ($when !== false && $when >= $request->since->getTimestamp()))) {
                return PublishResult::published((string) $item['id'], $item['permalink'] ?? null);
            }
        }

        return null;
    }

    public function locatePublished(SocialAccount $account, PublishRequest $request, string $containerId, StepBudget $budget): ?PublishResult
    {
        return $request->type === ContentType::InstagramStory
            ? $this->findStory($account, $request, $containerId, $budget)
            : $this->findRecent($account, $request, $budget);
    }

    /**
     * Las historias no salen en `/media` ni llevan texto: se buscan en `/stories` (activas, 24 h). Se elige la del contenedor
     * (mismo ID); si el ID no coincide, solo se acepta una historia si es la UNICA publicada desde `since` (con varias, adivinar
     * podria atribuir la historia de otra publicacion): en la duda se queda con el ID del contenedor.
     */
    private function findStory(SocialAccount $account, PublishRequest $request, string $containerId, StepBudget $budget): ?PublishResult
    {
        try {
            $data = $this->graph->get($account->external_id.'/stories', ['fields' => 'id,permalink,timestamp', 'limit' => self::RECENT_MEDIA], (string) $account->access_token);
        } catch (PublishException $e) {
            Log::notice('instagram.stories_lookup_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);
            $data = [];
        }
        $budget->spent();

        $candidates = [];
        foreach ($data['data'] ?? [] as $item) {
            if ((string) ($item['id'] ?? '') === $containerId) {
                return PublishResult::published((string) $item['id'], $item['permalink'] ?? null);
            }

            $when = isset($item['timestamp']) ? strtotime((string) $item['timestamp']) : false;

            if ($when !== false && ($request->since === null || $when >= $request->since->getTimestamp())) {
                $candidates[] = $item;
            }
        }

        // Una historia dura 24 h: si la ventana de busqueda es mas antigua, la nuestra ya no puede estar listada y la unica que
        // aparezca seria AJENA; en la duda se queda con el ID del contenedor (revision F3 i3 R4).
        $listable = $request->since === null || $request->since->gt(now()->subHours(24));

        if ($listable && count($candidates) === 1) {
            return PublishResult::published((string) $candidates[0]['id'], $candidates[0]['permalink'] ?? null);
        }

        return null;
    }

    /** @return array<string, string> */
    private function mediaParams(PublishMedia $media): array
    {
        return $media->isVideo()
            ? ['media_type' => 'VIDEO', 'video_url' => $media->url]
            : ['image_url' => $media->url] + $this->altParams($media);
    }

    /**
     * Texto alternativo propio de este destino (T-17); solo imagenes.
     *
     * @return array<string, string>
     */
    private function altParams(PublishMedia $media): array
    {
        return ! $media->isVideo() && $media->alt !== null && $media->alt !== '' ? ['alt_text' => $media->alt] : [];
    }
}
