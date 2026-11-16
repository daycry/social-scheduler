<?php

namespace App\Connectors\Threads;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Support\ContainerNetwork;
use App\Connectors\Support\StepBudget;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

/**
 * Ganchos de Threads para `ContainerPublishFlow`: contenedor `POST /{user}/threads` -> espera de `status` -> `POST /{user}/threads_publish`.
 * Tipos: texto, imagen, video y carrusel. La media la descarga Threads de una URL publica firmada (`PublishRequestFactory`).
 *
 * Estados del contenedor (documentados): IN_PROGRESS, FINISHED, ERROR, EXPIRED (24 h) y PUBLISHED, con `error_message`.
 */
class ThreadsContainers implements ContainerNetwork
{
    /** Cuantas publicaciones recientes se revisan al buscar un duplicado. */
    private const RECENT_MEDIA = 25;

    /** Motivos de `error_message` del contenedor (documentados en «Troubleshooting») -> mensaje para el usuario. */
    private const CONTAINER_ERRORS = [
        'FAILED_DOWNLOADING_VIDEO' => 'Threads no pudo descargar el video desde la URL de la media',
        'FAILED_PROCESSING_AUDIO' => 'Threads no pudo procesar el audio del video',
        'FAILED_PROCESSING_VIDEO' => 'Threads no pudo procesar el video',
        'INVALID_ASPEC_RATIO' => 'la proporcion del video no es admitida',
        'INVALID_ASPECT_RATIO' => 'la proporcion del video no es admitida',
        'INVALID_BIT_RATE' => 'la tasa de bits del video no es admitida',
        'INVALID_DURATION' => 'la duracion del video no es admitida (de mas de 0 s a 5 min)',
        'INVALID_FRAME_RATE' => 'la tasa de fotogramas del video no es admitida (de 23 a 60 fps)',
        'INVALID_AUDIO_CHANNELS' => 'el numero de canales de audio no es admitido (mono o estereo)',
        'INVALID_AUDIO_CHANNEL_LAYOUT' => 'la disposicion de canales de audio no es admitida',
    ];

    public function __construct(private readonly ThreadsClient $client) {}

    public function label(): string
    {
        return 'Threads';
    }

    public function carouselType(): ContentType
    {
        return ContentType::ThreadsCarousel;
    }

    /** Consulta el limite de publicaciones (24 h) antes de gastar un contenedor. Si no se puede consultar, se intenta igual. */
    public function assertQuota(SocialAccount $account): void
    {
        try {
            $data = $this->client->get($account->external_id.'/threads_publishing_limit', ['fields' => 'quota_usage,config'], (string) $account->access_token, true);
        } catch (QuotaExceededException $e) {
            throw $e;
        } catch (PublishException $e) {
            Log::notice('threads.quota_check_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            return;
        }

        $row = $data['data'][0] ?? null;
        $used = (int) ($row['quota_usage'] ?? 0);
        $total = (int) ($row['config']['quota_total'] ?? config('social.threads.publish_quota', 250));

        if ($total > 0 && $used >= $total) {
            throw new QuotaExceededException("Threads permite $total publicaciones cada 24 h y ya se han usado $used.", now()->addHour());
        }
    }

    public function containerParams(PublishRequest $request): array
    {
        $media = $request->firstMedia();
        $text = trim((string) $request->text);

        return match ($request->type) {
            ContentType::ThreadsText => ['media_type' => 'TEXT', 'text' => (string) $request->text],
            ContentType::ThreadsImage, ContentType::ThreadsVideo => $this->mediaParams($media) + ($text !== '' ? ['text' => (string) $request->text] : []),
            default => throw new PermanentPublishException('Tipo de contenido no admitido en Threads.', 'tipo_no_admitido'),
        };
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
        ] + (trim((string) $request->text) !== '' ? ['text' => (string) $request->text] : []);
    }

    public function createContainer(SocialAccount $account, array $params): string
    {
        $created = $this->client->post($account->external_id.'/threads', $params, (string) $account->access_token);
        $id = (string) ($created['id'] ?? '');

        if ($id === '') {
            throw new PermanentPublishException('Threads no devolvio el contenedor de media.', 'sin_contenedor');
        }

        return $id;
    }

    public function containerStatus(SocialAccount $account, string $containerId): array
    {
        $data = $this->client->get($containerId, ['fields' => 'status,error_message'], (string) $account->access_token);

        return ['code' => (string) ($data['status'] ?? 'IN_PROGRESS'), 'detail' => isset($data['error_message']) ? (string) $data['error_message'] : null];
    }

    public function failureMessage(array $status): string
    {
        if ($status['code'] === 'EXPIRED') {
            return 'El contenedor de media de Threads caduco antes de publicarse (24 h).';
        }

        $detail = strtoupper(trim((string) $status['detail']));
        $text = self::CONTAINER_ERRORS[$detail] ?? null;

        return $text !== null
            ? 'Threads no pudo procesar el contenido: '.$text.'.'
            : 'Threads no pudo procesar el contenido'.($detail !== '' && $detail !== 'UNKNOWN' ? ': '.mb_substr($detail, 0, 200) : '.');
    }

    public function publishContainer(SocialAccount $account, string $containerId): string
    {
        $published = $this->client->post($account->external_id.'/threads_publish', ['creation_id' => $containerId], (string) $account->access_token);
        $id = (string) ($published['id'] ?? '');

        if ($id === '') {
            throw new PermanentPublishException('Threads no devolvio el ID de la publicacion.', 'sin_id');
        }

        return $id;
    }

    public function permalink(SocialAccount $account, string $mediaId): ?string
    {
        try {
            $data = $this->client->get($mediaId, ['fields' => 'permalink'], (string) $account->access_token, true);

            return $data['permalink'] ?? null;
        } catch (PublishException $e) {
            Log::notice('threads.permalink_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            return null; // la publicacion ya existe: el enlace es accesorio
        }
    }

    /**
     * Busca entre las publicaciones recientes de la cuenta una con el mismo texto y posterior al inicio del intento (las publicaciones
     * sin texto no se pueden reconocer: limitacion documentada). No usa `since` en la consulta: se filtra aqui por la hora.
     */
    public function findRecent(SocialAccount $account, PublishRequest $request, StepBudget $budget): ?PublishResult
    {
        $text = trim((string) $request->text);

        if ($text === '') {
            return null; // sin texto no hay forma fiable de reconocer la publicacion
        }

        $data = $this->client->get($account->external_id.'/threads', ['fields' => 'id,text,permalink,timestamp', 'limit' => self::RECENT_MEDIA], (string) $account->access_token);
        $budget->spent();

        foreach ($data['data'] ?? [] as $item) {
            $when = isset($item['timestamp']) ? strtotime((string) $item['timestamp']) : false;

            if (trim((string) ($item['text'] ?? '')) === $text && ($request->since === null || ($when !== false && $when >= $request->since->getTimestamp()))) {
                return PublishResult::published((string) $item['id'], $item['permalink'] ?? null);
            }
        }

        return null;
    }

    public function locatePublished(SocialAccount $account, PublishRequest $request, string $containerId, StepBudget $budget): ?PublishResult
    {
        return $this->findRecent($account, $request, $budget);
    }

    /** @return array<string, string> */
    private function mediaParams(PublishMedia $media): array
    {
        return $media->isVideo()
            ? ['media_type' => 'VIDEO', 'video_url' => $media->url]
            : ['media_type' => 'IMAGE', 'image_url' => $media->url] + ($media->alt !== null && $media->alt !== '' ? ['alt_text' => $media->alt] : []);
    }
}
