<?php

namespace App\Connectors\Meta\Instagram;

use App\Connectors\Contracts\FirstCommenter;
use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Meta\Comments;
use App\Connectors\Meta\GraphClient;
use App\Connectors\Support\ContainerPublishFlow;
use App\Enums\ContentType;
use App\Models\SocialAccount;

/**
 * Publicacion en Instagram (cuenta profesional enlazada a una Pagina): valida la peticion (tipo, opciones, audio) y delega el
 * flujo de contenedores (`/{ig}/media` -> espera de `status_code` -> `/{ig}/media_publish`, carrusel reanudable, idempotencia)
 * en `ContainerPublishFlow` con los ganchos de `InstagramContainers`. Tipos: imagen, carrusel, reel y story. Necesita
 * `instagram_content_publish`.
 */
class InstagramPublisher implements FirstCommenter, Publisher, StatusPoller
{
    private readonly ContainerPublishFlow $flow;

    public function __construct(private readonly GraphClient $graph = new GraphClient)
    {
        $this->flow = new ContainerPublishFlow(new InstagramContainers($graph));
    }

    public function comment(SocialAccount $account, string $externalId, string $text): string
    {
        return (new Comments($this->graph))->create($account, $externalId, $text);
    }

    public function findOwnComment(SocialAccount $account, string $externalId, string $text): ?string
    {
        return (new Comments($this->graph))->findOwn($account, $externalId, $text);
    }

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $this->assertSupported($request);
        $this->assertPostOptions($request);
        $selection = $request->options['instagram_audio'] ?? null;
        $audioErrors = InstagramAudio::selectionErrors($selection, $request->type, $account, checkAvailability: false);
        if ($audioErrors !== []) {
            throw new PermanentPublishException(implode(' ', $audioErrors), 'audio_no_admitido');
        }
        $budget = $request->budget();

        if ($selection !== null) {
            try {
                (new InstagramAudio($this->graph))->get($account, $selection['audio_id'], forPublishing: true);
            } catch (\InvalidArgumentException $e) {
                throw new PermanentPublishException($e->getMessage(), 'audio_no_disponible');
            }
            $budget->spent();
        }

        return $this->flow->publish($account, $request);
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $this->assertPostOptions($request);

        return $this->flow->poll($account, $request);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        return $this->flow->findPublished($account, $request);
    }

    private function assertPostOptions(PublishRequest $request): void
    {
        if (($errors = InstagramPostOptions::errors($request->options, $request->type)) !== []) {
            throw new PermanentPublishException(implode(' ', $errors), 'opciones_instagram_invalidas');
        }
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
}
