<?php

namespace App\Connectors\Meta\Facebook;

use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Meta\GraphClient;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

/**
 * Publicacion en Paginas de Facebook con el token de Pagina (`pages_manage_posts`), Graph v23.0:
 *  - texto / enlace: `POST /{page}/feed`           (sincrono)
 *  - foto:           `POST /{page}/photos` (url)   (sincrono)
 *  - video:          `POST /{page}/videos` (file_url) -> procesamiento asincrono, se sondea `status.video_status`
 *  - reel:           `POST /{page}/video_reels` start -> subida por `file_url` -> finish -> sondeo
 *
 * `external_reference` mientras el destino esta `procesando` = ID del video/reel.
 *
 * Idempotencia: `findPublished` busca en las publicaciones/videos recientes de la Pagina una con el mismo
 * texto posterior a la hora programada; un video ya creado se resuelve por su ID (sin crear otro).
 */
class FacebookPublisher implements Publisher, StatusPoller
{
    private const RECENT = 25;

    public function __construct(private readonly GraphClient $graph = new GraphClient) {}

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $token = (string) $account->access_token;
        $page = $account->external_id;
        $media = $request->firstMedia();
        $text = (string) $request->text;

        switch ($request->type) {
            case ContentType::FacebookText:
                $params = ['message' => $text];
                if (! empty($request->options['link'])) {
                    $params['link'] = (string) $request->options['link'];
                }
                $res = $this->graph->post("$page/feed", $params, $token);

                return $this->published($res['id'] ?? null, $account);

            case ContentType::FacebookPhoto:
                $this->requireMedia($media, 'image');
                $res = $this->graph->post("$page/photos", ['url' => $media->url, 'published' => 'true'] + ($text !== '' ? ['caption' => $text] : []), $token);

                // `post_id` es el ID de la publicacion en el muro; `id` el de la foto.
                return $this->published($res['post_id'] ?? $res['id'] ?? null, $account);

            case ContentType::FacebookVideo:
                $this->requireMedia($media, 'video');
                $res = $this->graph->post("$page/videos", ['file_url' => $media->url] + ($text !== '' ? ['description' => $text] : []), $token);

                return $this->processing($res['id'] ?? null);

            case ContentType::FacebookReel:
                $this->requireMedia($media, 'video');

                return $this->startReel($account, $media->url, $text);

            default:
                throw new PermanentPublishException('Tipo de contenido no admitido en Facebook.', 'tipo_no_admitido');
        }
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        if ($request->reference === null) {
            throw new PermanentPublishException('Falta la referencia del video de Facebook.', 'sin_referencia');
        }

        $data = $this->graph->get($request->reference, ['fields' => 'status,permalink_url'], (string) $account->access_token);
        $status = $data['status'] ?? [];
        $video = (string) ($status['video_status'] ?? '');
        $publishing = (string) ($status['publishing_phase']['status'] ?? '');

        if ($video === 'ready' || $publishing === 'complete') {
            return PublishResult::published($request->reference, $this->url($data['permalink_url'] ?? null));
        }

        if ($video === 'error' || in_array('error', [(string) ($status['processing_phase']['status'] ?? ''), (string) ($status['uploading_phase']['status'] ?? ''), $publishing], true)) {
            $detail = $status['processing_phase']['error']['message'] ?? $status['uploading_phase']['error']['message'] ?? $status['publishing_phase']['error']['message'] ?? null;

            return PublishResult::failed('Facebook no pudo procesar el video'.($detail ? ': '.mb_substr((string) $detail, 0, 200) : '.'));
        }

        return PublishResult::processing($request->reference);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        // Un video/reel ya creado se resuelve por su ID: nunca se crea otro.
        if ($request->reference !== null) {
            return $this->poll($account, $request);
        }

        $text = trim((string) $request->text);

        if ($text === '') {
            return null; // sin texto no hay forma fiable de reconocerla
        }

        $edge = match ($request->type) {
            ContentType::FacebookVideo, ContentType::FacebookReel => ['videos', 'description'],
            default => ['posts', 'message'],
        };

        try {
            $data = $this->graph->get("{$account->external_id}/{$edge[0]}", ['fields' => "id,{$edge[1]},permalink_url,created_time", 'limit' => self::RECENT], (string) $account->access_token);
        } catch (PublishException $e) {
            Log::notice('facebook.lookup_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            return null;
        }

        foreach ($data['data'] ?? [] as $item) {
            $when = isset($item['created_time']) ? strtotime((string) $item['created_time']) : false;

            if (trim((string) ($item[$edge[1]] ?? '')) === $text && ($request->since === null || ($when !== false && $when >= $request->since->getTimestamp()))) {
                return PublishResult::published((string) $item['id'], $this->url($item['permalink_url'] ?? null));
            }
        }

        return null;
    }

    /** Reel en 3 pasos: abrir la sesion, indicar el fichero por URL y cerrar publicandolo. */
    private function startReel(SocialAccount $account, string $url, string $description): PublishResult
    {
        $token = (string) $account->access_token;
        $page = $account->external_id;

        $start = $this->graph->post("$page/video_reels", ['upload_phase' => 'start'], $token);
        $videoId = (string) ($start['video_id'] ?? '');
        $uploadUrl = (string) ($start['upload_url'] ?? '');

        if ($videoId === '' || $uploadUrl === '') {
            throw new PermanentPublishException('Facebook no abrio la sesion de subida del reel.', 'sin_sesion');
        }

        $this->graph->postToUrl($uploadUrl, ['Authorization' => 'OAuth '.$token, 'file_url' => $url]);

        $this->graph->post("$page/video_reels", [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
        ] + ($description !== '' ? ['description' => $description] : []), $token);

        return PublishResult::processing($videoId);
    }

    private function published(mixed $id, SocialAccount $account): PublishResult
    {
        $id = (string) $id;

        if ($id === '') {
            throw new PermanentPublishException('Facebook no devolvio el ID de la publicacion.', 'sin_id');
        }

        // El enlace al muro se construye con el ID `pagina_publicacion`.
        return PublishResult::published($id, 'https://www.facebook.com/'.str_replace('_', '/posts/', $id));
    }

    private function processing(mixed $id): PublishResult
    {
        if ((string) $id === '') {
            throw new PermanentPublishException('Facebook no devolvio el ID del video.', 'sin_id');
        }

        return PublishResult::processing((string) $id);
    }

    private function requireMedia(?object $media, string $kind): void
    {
        if ($media === null || $media->kind !== $kind) {
            throw new PermanentPublishException('Falta el archivo de '.($kind === 'video' ? 'video' : 'imagen').' de la publicacion.', 'media_invalida');
        }
    }

    private function url(?string $link): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }

        return str_starts_with($link, '/') ? 'https://www.facebook.com'.$link : $link;
    }
}
