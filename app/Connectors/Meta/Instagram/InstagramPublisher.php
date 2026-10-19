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
use App\Connectors\Support\StepBudget;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

/**
 * Publicacion en Instagram (cuenta profesional enlazada a una Pagina) con el flujo del spike:
 *   contenedor `/{ig}/media` -> espera de `status_code` -> `/{ig}/media_publish`.
 * Tipos: imagen, carrusel, reel y story. Necesita `instagram_content_publish`.
 *
 * Referencia (`external_reference`) mientras el destino esta `procesando`:
 *   `carousel`   carrusel en construccion; el avance (hijos creados / listos, padre) vive en `progress` del destino
 *   `ID`         contenedor creado (unico o padre del carrusel); se publica cuando `status_code` = FINISHED
 * Cada ID se persiste EN EL ACTO (`PublishRequest::checkpoint`) antes del paso siguiente: un timeout en
 * `media_publish` o un worker caido nunca pierde el contenedor ni obliga a recrearlo.
 *
 * Un carrusel de 10 se divide en pasos reanudables (crear hijos -> sondear hijos -> crear padre -> publicar), cada
 * uno acotado por `StepBudget` (< `$timeout` del job): al agotarse el presupuesto el paso guarda su progreso y
 * continua en el siguiente sondeo.
 *
 * Idempotencia: `findPublished` busca en la media reciente de la cuenta una publicacion con la misma
 * descripcion posterior a la hora programada (las publicaciones sin texto no se pueden reconocer y se
 * documentan como limitacion); un contenedor ya `PUBLISHED` se resuelve igual (las historias, que no se listan en
 * `/media`, por `/{ig}/stories`). Antes de crear contenedores se consulta `content_publishing_limit`.
 */
class InstagramPublisher implements Publisher, StatusPoller
{
    /** Referencia mientras un carrusel aun crea/espera a sus hijos (el avance vive en `progress`). */
    private const BUILDING = 'carousel';

    /** Cuantas publicaciones recientes se revisan al buscar un duplicado. */
    private const RECENT_MEDIA = 25;

    public function __construct(private readonly GraphClient $graph = new GraphClient) {}

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $this->assertSupported($request);
        $budget = StepBudget::start();

        if ($request->type === ContentType::InstagramCarousel) {
            if ($request->progress === []) {
                $this->assertQuota($account);
                $budget->spent();
            }

            return $this->advanceCarousel($account, $request, $budget);
        }

        $this->assertQuota($account);
        $budget->spent();

        $containerId = $this->createContainer($account, $this->containerParams($request));
        $request->checkpoint($containerId);
        $budget->spent();

        return $this->advance($account, $request, $containerId, $budget);
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        if ($request->reference === null) {
            throw new PermanentPublishException('Falta la referencia del contenedor de Instagram.', 'sin_referencia');
        }

        $budget = StepBudget::start();

        return $request->reference === self::BUILDING
            ? $this->advanceCarousel($account, $request, $budget)
            : $this->advance($account, $request, $request->reference, $budget);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $container = $this->containerOf($request);

        // Un contenedor ya publicado (o a punto) se resuelve por su estado, sin crear otro.
        if ($container !== null) {
            $status = $this->containerStatus($account, $container);

            if ($status['code'] === 'PUBLISHED') {
                return $this->resolvePublished($account, $request, $container) ?? PublishResult::processing($container);
            }

            if ($status['code'] === 'FINISHED' || $status['code'] === 'IN_PROGRESS') {
                return PublishResult::processing($container);
            }
        }

        // Carrusel aun en construccion (sin padre): nada puede estar publicado; `publish` retoma desde `progress`.
        if ($request->type === ContentType::InstagramCarousel && $container === null && $request->progress !== []) {
            return null;
        }

        return $this->findRecent($account, $request);
    }

    // ------------------------------------------------------------------ flujo

    /** Contenedor "vivo" del destino: el unico/padre, o null si el carrusel aun se construye. */
    private function containerOf(PublishRequest $request): ?string
    {
        if ($request->reference !== null && $request->reference !== self::BUILDING) {
            return $request->reference;
        }

        return isset($request->progress['parent']) ? (string) $request->progress['parent'] : null;
    }

    /** Estado -> publicar: comprueba el contenedor y, si esta listo (y cabe en el presupuesto), lo publica. */
    private function advance(SocialAccount $account, PublishRequest $request, string $containerId, StepBudget $budget): PublishResult
    {
        if (! $budget->allows()) {
            return PublishResult::processing($containerId);
        }

        $status = $this->containerStatus($account, $containerId);
        $budget->spent();

        return match ($status['code']) {
            // `media_publish` va siempre tras un estado FINISHED: el presupuesto reserva margen para estas dos llamadas.
            'FINISHED' => $this->publishContainer($account, $containerId),
            'PUBLISHED' => $this->resolvePublished($account, $request, $containerId) ?? PublishResult::failed('Instagram indica que el contenedor ya se publico pero no se encuentra la publicacion.'),
            'ERROR', 'EXPIRED' => PublishResult::failed($this->statusMessage($status)),
            default => PublishResult::processing($containerId), // IN_PROGRESS u otro estado intermedio
        };
    }

    /**
     * Carrusel por pasos reanudables: 1) crear los hijos que falten, 2) esperar a que todos esten FINISHED,
     * 3) crear el padre, 4) publicar. El avance se guarda tras cada llamada con `checkpoint`.
     */
    private function advanceCarousel(SocialAccount $account, PublishRequest $request, StepBudget $budget): PublishResult
    {
        $parent = $this->containerOf($request);

        if ($parent !== null) {
            return $this->advance($account, $request, $parent, $budget);
        }

        $children = array_values((array) ($request->progress['children'] ?? []));
        $ready = array_values((array) ($request->progress['ready'] ?? []));

        $building = function () use (&$children, &$ready): PublishResult {
            return PublishResult::processing(self::BUILDING, [], ['children' => $children, 'ready' => $ready]);
        };

        // 1) Hijos que faltan (cada ID se guarda al crearlo: un fallo posterior no los pierde).
        while (count($children) < count($request->media)) {
            if (! $budget->allows()) {
                return $building();
            }

            $children[] = $this->createContainer($account, $this->mediaParams($request->media[count($children)]) + ['is_carousel_item' => 'true']);
            $budget->spent();
            $request->checkpoint(self::BUILDING, ['children' => $children, 'ready' => $ready]);
        }

        // 2) Esperar a que cada hijo termine de procesarse.
        foreach ($children as $id) {
            if (in_array($id, $ready, true)) {
                continue;
            }

            if (! $budget->allows()) {
                return $building();
            }

            $status = $this->containerStatus($account, $id);
            $budget->spent();

            if (in_array($status['code'], ['ERROR', 'EXPIRED'], true)) {
                return PublishResult::failed($this->statusMessage($status));
            }

            if ($status['code'] !== 'FINISHED') {
                return $building();
            }

            $ready[] = $id;
            $request->checkpoint(self::BUILDING, ['children' => $children, 'ready' => $ready]);
        }

        // 3) Contenedor padre (su ID sustituye a `carousel` como referencia).
        if (! $budget->allows()) {
            return $building();
        }

        $parent = $this->createContainer($account, [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => (string) $request->text,
        ]);
        $budget->spent();
        $progress = ['children' => $children, 'ready' => $ready, 'parent' => $parent];
        $request->checkpoint($parent, $progress);

        // 4) Estado del padre y, si esta listo, publicar.
        $result = $this->advance($account, new PublishRequest($request->targetId, $request->type, $request->text, $request->media, $request->options, $parent, $request->since, $progress, null), $parent, $budget);

        return $result->isProcessing() ? PublishResult::processing($parent, [], $progress) : $result;
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

    /** Localiza la publicacion de un contenedor que Instagram da por `PUBLISHED`. */
    private function resolvePublished(SocialAccount $account, PublishRequest $request, string $containerId): ?PublishResult
    {
        if ($request->type !== ContentType::InstagramStory) {
            return $this->findRecent($account, $request);
        }

        // Las historias no salen en `/media` ni llevan texto: se buscan en `/stories` (activas, 24 h) por hora.
        try {
            $data = $this->graph->get($account->external_id.'/stories', ['fields' => 'id,permalink,timestamp', 'limit' => self::RECENT_MEDIA], (string) $account->access_token);
        } catch (PublishException $e) {
            Log::notice('instagram.stories_lookup_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);
            $data = [];
        }

        $best = null;
        foreach ($data['data'] ?? [] as $item) {
            $when = isset($item['timestamp']) ? strtotime((string) $item['timestamp']) : false;

            if ($when !== false && ($request->since === null || $when >= $request->since->getTimestamp()) && ($best === null || $when > $best['when'])) {
                $best = ['when' => $when, 'item' => $item];
            }
        }

        if ($best !== null) {
            return PublishResult::published((string) $best['item']['id'], $best['item']['permalink'] ?? null);
        }

        // El contenedor consta como PUBLISHED: la historia existe aunque ya no se liste. Nunca se republica.
        return PublishResult::published($containerId, null, ['container' => $containerId], 'La historia se publico en Instagram pero no se pudo obtener su enlace.');
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

        // Las historias no llevan texto ni salen en `/media`: se resuelven por su contenedor (ver resolvePublished).
        if ($caption === '' || $request->type === ContentType::InstagramStory) {
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
