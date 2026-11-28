<?php

namespace App\Connectors\Threads;

use App\Connectors\Contracts\FirstCommenter;
use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Support\ContainerPublishFlow;
use App\Enums\ContentType;
use App\Models\SocialAccount;

/**
 * Publicacion en Threads: valida la peticion (tipo, texto, tamano del carrusel) y delega el flujo de contenedores
 * (`POST /{user}/threads` -> espera de `status` -> `POST /{user}/threads_publish`, carrusel reanudable, idempotencia) en
 * `ContainerPublishFlow` con los ganchos de `ThreadsContainers`.
 * Tipos: texto, imagen, video y carrusel.
 */
class ThreadsPublisher implements FirstCommenter, Publisher, StatusPoller
{
    public const CAROUSEL_MIN = 2;

    public const CAROUSEL_MAX = 20;

    private readonly ContainerPublishFlow $flow;

    public function __construct(private readonly ThreadsClient $client = new ThreadsClient)
    {
        $this->flow = new ContainerPublishFlow(new ThreadsContainers($client));
    }

    /** Primer comentario: respuesta propia a la publicacion (`reply_to_id`), con la logica de `ThreadsComments`. */
    public function comment(SocialAccount $account, string $externalId, string $text): string
    {
        return (new ThreadsComments($this->client))->create($account, $externalId, $text);
    }

    public function findOwnComment(SocialAccount $account, string $externalId, string $text): ?string
    {
        return (new ThreadsComments($this->client))->findOwn($account, $externalId, $text);
    }

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $this->assertSupported($request);

        return $this->flow->publish($account, $request);
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        return $this->flow->poll($account, $request);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        return $this->flow->findPublished($account, $request);
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
