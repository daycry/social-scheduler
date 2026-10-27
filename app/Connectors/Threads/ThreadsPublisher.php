<?php

namespace App\Connectors\Threads;

use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Support\StepBudget;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

/**
 * Publicacion en Threads (T1): reproduce la maquina de estados de contenedor de `InstagramPublisher` SIN heredar de ella ni tocarla:
 *   contenedor `POST /{user}/threads` -> espera de `status` -> `POST /{user}/threads_publish`.
 * Tipos: texto, imagen, video y carrusel. La media la descarga Threads de una URL publica firmada (`PublishRequestFactory`).
 *
 * Referencia (`external_reference`) mientras el destino esta `procesando`:
 *   `carousel`   carrusel en construccion; el avance (hijos creados / listos, padre) vive en `progress` del destino
 *   `ID`         contenedor creado (unico o padre del carrusel); se publica cuando `status` = FINISHED
 * Cada ID se persiste EN EL ACTO (`PublishRequest::checkpoint`) antes del paso siguiente: un timeout en `threads_publish` o un worker
 * caido nunca pierde el contenedor ni obliga a recrearlo. Un carrusel se divide en pasos reanudables (crear hijos -> sondear hijos ->
 * crear padre -> publicar), cada uno acotado por `StepBudget` (< `$timeout` del job).
 *
 * Estados del contenedor (documentados): IN_PROGRESS, FINISHED, ERROR, EXPIRED (24 h) y PUBLISHED, con `error_message`.
 */
class ThreadsPublisher implements Publisher, StatusPoller
{
    /** Referencia mientras un carrusel aun crea/espera a sus hijos (el avance vive en `progress`). */
    private const BUILDING = 'carousel';

    public const CAROUSEL_MIN = 2;

    public const CAROUSEL_MAX = 20;

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

    public function __construct(private readonly ThreadsClient $client = new ThreadsClient) {}

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $this->assertSupported($request);
        $budget = $request->budget();

        if ($request->type === ContentType::ThreadsCarousel) {
            return $this->advanceCarousel($account, $request, $budget);
        }

        $containerId = $this->createContainer($account, $this->containerParams($request));
        $request->checkpoint($containerId);
        $budget->spent();

        return $this->advance($account, $request, $containerId, $budget);
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        if ($request->reference === null) {
            throw new PermanentPublishException('Falta la referencia del contenedor de Threads.', 'sin_referencia');
        }

        $budget = $request->budget();

        return $request->reference === self::BUILDING
            ? $this->advanceCarousel($account, $request, $budget)
            : $this->advance($account, $request, $request->reference, $budget);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $container = $this->containerOf($request);

        // Un contenedor ya publicado (o a punto) se resuelve por su estado, sin crear otro.
        if ($container === null) {
            return null; // carrusel aun en construccion o sin contenedor: `publish` retoma desde `progress`
        }

        $status = $this->containerStatus($account, $container);
        $request->budget()->spent();

        return match ($status['code']) {
            'PUBLISHED' => $this->resolvePublished($container),
            // `not_published`: el contenedor existe pero nada salio aun (lo usa la reconciliacion tras una edicion).
            'FINISHED', 'IN_PROGRESS' => PublishResult::processing($container, ['not_published' => true]),
            default => null,
        };
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
        // Tras FINISHED vienen SIEMPRE `threads_publish` y el enlace sin volver a preguntar: se reservan las 2 llamadas.
        if (! $budget->allows(after: 2)) {
            return PublishResult::processing($containerId);
        }

        $status = $this->containerStatus($account, $containerId);
        $budget->spent();

        return match ($status['code']) {
            'FINISHED' => $this->publishContainer($account, $containerId, $budget),
            // PUBLISHED es concluyente: el destino se da por publicado aunque no se localice la publicacion concreta.
            'PUBLISHED' => $this->resolvePublished($containerId),
            'ERROR', 'EXPIRED' => throw new PermanentPublishException($this->statusMessage($status), $status['code'] === 'EXPIRED' ? 'contenedor_caducado' : 'contenedor_error'),
            default => PublishResult::processing($containerId), // IN_PROGRESS u otro estado intermedio
        };
    }

    /**
     * Carrusel por pasos reanudables: 1) crear los hijos que falten, 2) esperar a que todos esten FINISHED, 3) crear el padre,
     * 4) publicar. El avance se guarda tras cada llamada con `checkpoint`.
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
                throw new PermanentPublishException($this->statusMessage($status), $status['code'] === 'EXPIRED' ? 'contenedor_caducado' : 'contenedor_error');
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
        ] + (trim((string) $request->text) !== '' ? ['text' => (string) $request->text] : []));
        $budget->spent();
        $progress = ['children' => $children, 'ready' => $ready, 'parent' => $parent];
        $request->checkpoint($parent, $progress);

        // 4) Estado del padre y, si esta listo, publicar.
        $result = $this->advance($account, new PublishRequest($request->targetId, $request->type, $request->text, $request->media, $request->options, $parent, $request->since, $progress, null), $parent, $budget);

        return $result->isProcessing() ? PublishResult::processing($parent, [], $progress) : $result;
    }

    private function publishContainer(SocialAccount $account, string $containerId, StepBudget $budget): PublishResult
    {
        $published = $this->client->post($account->external_id.'/threads_publish', ['creation_id' => $containerId], (string) $account->access_token);
        $budget->spent();
        $id = (string) ($published['id'] ?? '');

        if ($id === '') {
            throw new PermanentPublishException('Threads no devolvio el ID de la publicacion.', 'sin_id');
        }

        // El enlace es accesorio: sin presupuesto para otra llamada se omite (la publicacion ya existe).
        return PublishResult::published($id, $budget->allows() ? $this->permalink($account, $id) : null, ['container' => $containerId]);
    }

    /**
     * Un contenedor `PUBLISHED` es concluyente: se da por publicado con el ID del contenedor (y una nota) y nunca por fallido, para que
     * reprogramarlo no duplique. Localizar la publicacion concreta es cosa de `findRecent` (T-58).
     */
    private function resolvePublished(string $containerId): PublishResult
    {
        return PublishResult::published(
            $containerId,
            null,
            ['container' => $containerId, 'external_id_kind' => 'container'],
            'Se publico en Threads pero no se pudo obtener el enlace ni el ID de la publicacion.',
            ['external_id_kind' => 'container'],
        );
    }

    /** @return array<string, string> */
    private function containerParams(PublishRequest $request): array
    {
        $media = $request->firstMedia();
        $text = trim((string) $request->text);

        return match ($request->type) {
            ContentType::ThreadsText => ['media_type' => 'TEXT', 'text' => (string) $request->text],
            ContentType::ThreadsImage, ContentType::ThreadsVideo => $this->mediaParams($media) + ($text !== '' ? ['text' => (string) $request->text] : []),
            default => throw new PermanentPublishException('Tipo de contenido no admitido en Threads.', 'tipo_no_admitido'),
        };
    }

    /** @return array<string, string> */
    private function mediaParams(PublishMedia $media): array
    {
        return $media->isVideo()
            ? ['media_type' => 'VIDEO', 'video_url' => $media->url]
            : ['media_type' => 'IMAGE', 'image_url' => $media->url];
    }

    /** @param array<string, string> $params */
    private function createContainer(SocialAccount $account, array $params): string
    {
        $created = $this->client->post($account->external_id.'/threads', $params, (string) $account->access_token);
        $id = (string) ($created['id'] ?? '');

        if ($id === '') {
            throw new PermanentPublishException('Threads no devolvio el contenedor de media.', 'sin_contenedor');
        }

        return $id;
    }

    // ------------------------------------------------------------------ consultas

    /** @return array{code: string, detail: ?string} */
    private function containerStatus(SocialAccount $account, string $containerId): array
    {
        $data = $this->client->get($containerId, ['fields' => 'status,error_message'], (string) $account->access_token);

        return ['code' => (string) ($data['status'] ?? 'IN_PROGRESS'), 'detail' => isset($data['error_message']) ? (string) $data['error_message'] : null];
    }

    /** @param array{code: string, detail: ?string} $status */
    private function statusMessage(array $status): string
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

    private function permalink(SocialAccount $account, string $mediaId): ?string
    {
        try {
            $data = $this->client->get($mediaId, ['fields' => 'permalink'], (string) $account->access_token, true);

            return $data['permalink'] ?? null;
        } catch (PublishException $e) {
            Log::notice('threads.permalink_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            return null; // la publicacion ya existe: el enlace es accesorio
        }
    }

    private function assertSupported(PublishRequest $request): void
    {
        $media = $request->media;

        if ($request->type === ContentType::ThreadsCarousel) {
            if (count($media) < self::CAROUSEL_MIN || count($media) > self::CAROUSEL_MAX) {
                throw new PermanentPublishException('Un carrusel de Threads necesita entre '.self::CAROUSEL_MIN.' y '.self::CAROUSEL_MAX.' archivos.', 'carrusel_invalido');
            }

            return;
        }

        if ($request->type === ContentType::ThreadsText) {
            if (trim((string) $request->text) === '') {
                throw new PermanentPublishException('Una publicacion de texto de Threads necesita texto.', 'texto_vacio');
            }

            return;
        }

        if (! in_array($request->type, [ContentType::ThreadsImage, ContentType::ThreadsVideo], true) || $request->firstMedia() === null) {
            throw new PermanentPublishException('Tipo de contenido no admitido en Threads.', 'tipo_no_admitido');
        }
    }
}
