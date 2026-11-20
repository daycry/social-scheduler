<?php

namespace App\Connectors\Google;

use App\Connectors\Contracts\FirstCommenter;
use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Models\SocialAccount;

/**
 * Publicacion en YouTube con la Data API v3 (decision G1 = A) y cliente HTTP propio. Es la fachada del conector:
 *  - `YouTubeClient`: peticiones, errores de la API y cupo diario (~1.600 ud por subida; agotado -> `QuotaExceededException`
 *    y decide `QuotaPolicy`: reprogramar o fallar).
 *  - `YouTubeResumableUpload`: `videos.insert` con subida REANUDABLE, en trozos leidos en streaming del disco de media. Un job
 *    dura como maximo 110 s: la subida se corta tras `upload_budget_seconds` y el sondeo la CONTINUA (referencia `subiendo`;
 *    la URL de sesion se guarda en cache por destino) hasta completarla.
 *  - `YouTubeVideoMetadata`: titulo, descripcion, tags y visibilidad pedida (`public` no se fuerza a privado). Short = video
 *    vertical <= 3 min (lo valida `config/media_specs.php`); se anade `#Shorts` salvo `options.shorts_hashtag = false`.
 *  - `YouTubeVideoStatus`: sondeo y búsqueda por el tag del destino.
 *  - `YouTubeComments`: primer comentario (`FirstCommenter`) e inbox.
 * Tras subir, el destino queda `procesando` y se sondea `status.uploadStatus` / `processingDetails`. `external_reference` pasa
 * a ser el ID del video en cuanto existe.
 *
 * Idempotencia: cada video lleva el tag propio `ss-<post_target_id>` (`snippet.tags`); `findPublished` mira los ultimos
 * videos del canal (lista de subidas, posteriores a `since`) y pide sus tags (`videos.list`): solo el video marcado con
 * el tag DE ESTE DESTINO cuenta como "ya publicado" (dos videos con el mismo titulo no se confunden). El ID del video se
 * guarda con `checkpoint` en cuanto se conoce, y un reintento reutiliza la sesion reanudable cacheada (no abre otra ni
 * vuelve a cobrar cuota).
 */
class YouTubePublisher implements FirstCommenter, Publisher, StatusPoller
{
    private readonly YouTubeResumableUpload $upload;

    private readonly YouTubeComments $comments;

    private readonly YouTubeVideoStatus $status;

    public function __construct(YouTubeClient $client = new YouTubeClient)
    {
        $this->status = new YouTubeVideoStatus($client);
        $this->upload = new YouTubeResumableUpload($client, $this->status);
        $this->comments = new YouTubeComments($client);
    }

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        return $this->upload->start($account, $request, YouTubeVideoMetadata::videoOf($request));
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        if ($request->reference === null) {
            throw new PermanentPublishException('Falta la referencia del video de YouTube.', 'sin_referencia');
        }

        if ($request->reference === PublishResult::UPLOADING) {
            return $this->upload->resume($account, $request);
        }

        return $this->status->poll($account, $request->reference, $request->type);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        if ($request->reference !== null) {
            return $this->poll($account, $request);
        }

        return $this->status->findByTarget($account, $request);
    }

    /** Primer comentario del video publicado (`YouTubeComments::create`). */
    public function comment(SocialAccount $account, string $externalId, string $text): string
    {
        return $this->comments->create($account, $externalId, $text);
    }

    /** Comentario propio con ese texto, o null (`YouTubeComments::findOwn`). */
    public function findOwnComment(SocialAccount $account, string $externalId, string $text): ?string
    {
        return $this->comments->findOwn($account, $externalId, $text);
    }
}
