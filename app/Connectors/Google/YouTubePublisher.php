<?php

namespace App\Connectors\Google;

use App\Connectors\Contracts\FirstCommenter;
use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

/**
 * Publicacion en YouTube con la Data API v3 (decision G1 = A) y cliente HTTP propio. Es la fachada del conector:
 *  - `YouTubeClient`: peticiones, errores de la API y cupo diario (~1.600 ud por subida; agotado -> `QuotaExceededException`
 *    y decide `QuotaPolicy`: reprogramar o fallar).
 *  - `YouTubeResumableUpload`: `videos.insert` con subida REANUDABLE, en trozos leidos en streaming del disco de media. Un job
 *    dura como maximo 110 s: la subida se corta tras `upload_budget_seconds` y el sondeo la CONTINUA (referencia `subiendo`;
 *    la URL de sesion se guarda en cache por destino) hasta completarla.
 *  - `YouTubeVideoMetadata`: titulo, descripcion, tags y visibilidad pedida (`public` no se fuerza a privado). Short = video
 *    vertical <= 3 min (lo valida `config/media_specs.php`); se anade `#Shorts` salvo `options.shorts_hashtag = false`.
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
    /** Motivos de rechazo/fallo de YouTube -> complemento legible. */
    private const REASONS = [
        'copyright' => 'por derechos de autor',
        'duplicate' => 'porque el video ya estaba subido',
        'inappropriate' => 'por contenido inapropiado',
        'length' => 'por su duracion',
        'termsOfUse' => 'por incumplir las condiciones de uso',
        'trademark' => 'por una marca registrada',
        'claim' => 'por una reclamacion de derechos',
        'legal' => 'por motivos legales',
        'uploaderAccountClosed' => 'porque la cuenta esta cerrada',
        'uploaderAccountSuspended' => 'porque la cuenta esta suspendida',
        'codec' => 'por un codec no admitido',
        'unsupportedCodec' => 'por un codec no admitido',
        'conversion' => 'por un error de conversion',
        'emptyFile' => 'porque el archivo esta vacio',
        'invalidFile' => 'porque el archivo no es valido',
        'tooSmall' => 'porque el archivo es demasiado pequeno',
        'uploadAborted' => 'porque la subida se aborto',
    ];

    private readonly YouTubeResumableUpload $upload;

    private readonly YouTubeComments $comments;

    public function __construct(private readonly YouTubeClient $client = new YouTubeClient)
    {
        $this->upload = new YouTubeResumableUpload($client, fn (SocialAccount $account, PublishRequest $request) => $this->findPublished($account, $request));
        $this->comments = new YouTubeComments($client);
    }

    /** ¿El canal concedio el permiso de comentar? (ver `YouTubeComments::canComment`) */
    public static function canComment(SocialAccount $account): bool
    {
        return YouTubeComments::canComment($account);
    }

    /** Olvida la sesion de subida reanudable cacheada de un destino (al empezar de cero tras un error). */
    public static function forgetUploadSession(int $targetId): void
    {
        YouTubeResumableUpload::forgetSession($targetId);
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

        $data = $this->client->get($account, 'videos', ['part' => 'status,processingDetails', 'id' => $request->reference]);
        $item = $data['items'][0] ?? null;

        if ($item === null) {
            return PublishResult::processing($request->reference); // aun no visible; el sondeo vence por tiempo
        }

        $status = $item['status'] ?? [];
        $upload = (string) ($status['uploadStatus'] ?? '');
        $processing = (string) ($item['processingDetails']['processingStatus'] ?? '');

        if (in_array($upload, ['failed', 'rejected', 'deleted'], true) || in_array($processing, ['failed', 'terminated'], true)) {
            $reason = (string) ($status['rejectionReason'] ?? $status['failureReason'] ?? $item['processingDetails']['processingFailureReason'] ?? '');
            $why = self::REASONS[$reason] ?? ($reason !== '' ? "({$reason})" : '');

            return PublishResult::failed(match ($upload) {
                'rejected' => 'YouTube rechazo el video '.($why !== '' ? $why : 'tras revisarlo').'.',
                'deleted' => 'El video se elimino de YouTube antes de terminar de procesarse.',
                default => 'YouTube no pudo procesar el video'.($why !== '' ? ' '.$why : '').'.',
            });
        }

        if ($upload === 'processed' || $processing === 'succeeded') {
            return PublishResult::published($request->reference, $this->watchUrl($request->reference, $request->type));
        }

        return PublishResult::processing($request->reference);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        // Una subida en curso o un video ya creado se resuelven por su referencia: nunca se crea otro.
        if ($request->reference !== null) {
            return $this->poll($account, $request);
        }

        $tag = YouTubeVideoMetadata::targetTag($request->targetId);
        $found = null;

        try {
            // La lista de subidas de un canal es la playlist cuyo ID sustituye el prefijo UC por UU. Se pagina (de la mas
            // reciente a la mas antigua) hasta pasar `since`, sin pagina siguiente o `lookup_max_pages`; cada pagina cuesta
            // 1 ud (playlistItems.list) + 1 ud (videos.list): tope 2 x paginas (revision F4-5 i2 N5).
            $pageToken = null;
            $maxPages = max(1, (int) config('social.youtube.lookup_max_pages', 3));

            for ($page = 0; $page < $maxPages && $found === null; $page++) {
                $query = ['part' => 'snippet', 'maxResults' => 50, 'playlistId' => 'UU'.substr($account->external_id, 2)];
                if ($pageToken !== null) {
                    $query['pageToken'] = $pageToken;
                }

                $recent = $this->client->get($account, 'playlistItems', $query);

                $ids = [];
                $reachedOlder = false;
                foreach ($recent['items'] ?? [] as $item) {
                    $snippet = $item['snippet'] ?? [];
                    $when = isset($snippet['publishedAt']) ? strtotime((string) $snippet['publishedAt']) : false;
                    $videoId = (string) ($snippet['resourceId']['videoId'] ?? '');

                    if ($videoId !== '' && ($request->since === null || ($when !== false && $when >= $request->since->getTimestamp()))) {
                        $ids[] = $videoId;
                    } elseif ($request->since !== null && $when !== false && $when < $request->since->getTimestamp()) {
                        $reachedOlder = true;
                    }
                }

                if ($ids !== []) {
                    // Los tags no vienen en la lista de subidas: se piden (1 ud de cuota) y solo cuenta el video con el tag de ESTE destino.
                    $videos = $this->client->get($account, 'videos', ['part' => 'snippet', 'id' => implode(',', $ids)]);

                    foreach ($videos['items'] ?? [] as $video) {
                        $id = (string) ($video['id'] ?? '');

                        if ($id !== '' && in_array($tag, (array) ($video['snippet']['tags'] ?? []), true)) {
                            $found = $id;
                            break;
                        }
                    }
                }

                $pageToken = $recent['nextPageToken'] ?? null;

                if ($reachedOlder || ! is_string($pageToken) || $pageToken === '') {
                    break;
                }
            }
        } catch (PublishException $e) {
            Log::notice('youtube.lookup_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            return null;
        }

        if ($found === null) {
            return null;
        }

        return $this->poll($account, new PublishRequest($request->targetId, $request->type, $request->text, $request->media, $request->options, $found, $request->since));
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

    /** Borra un video del canal (scope `youtube`; `youtube.upload` no lo permite). `false` si ya no existia. */
    public function deleteVideo(SocialAccount $account, string $videoId): bool
    {
        $response = $this->client->send($account, fn (PendingRequest $http) => $http->delete(YouTubeClient::API.'/videos?'.http_build_query(['id' => $videoId])), false);

        if ($response->status() === 204) {
            return true;
        }

        if ($response->status() === 404) {
            return false;
        }

        throw $this->client->exceptionFor($response);
    }

    private function watchUrl(string $id, ContentType $type): string
    {
        return $type === ContentType::YouTubeShort ? "https://www.youtube.com/shorts/{$id}" : "https://www.youtube.com/watch?v={$id}";
    }
}
