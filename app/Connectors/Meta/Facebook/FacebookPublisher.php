<?php

namespace App\Connectors\Meta\Facebook;

use App\Connectors\Contracts\FirstCommenter;
use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\Comments;
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
 *  - varias fotos:   cada foto `POST /{page}/photos` con `published=false` y luego `POST /{page}/feed` con `attached_media[i]`
 *                    ({"media_fbid": id}); los IDs subidos se guardan en `progress.photos` (viven ~24 h) para no repetir subidas al reintentar
 *  - historia foto:  foto `published=false` y `POST /{page}/photo_stories` con `photo_id` (sincrono)
 *  - historia video: `POST /{page}/video_stories` start -> subida por `file_url` -> finish -> sondeo (como el reel)
 *
 * `external_reference` mientras el destino esta `procesando` = ID del video/reel.
 *
 * Idempotencia: `findPublished` busca en las publicaciones/videos recientes de la Pagina una con el mismo
 * texto posterior a la hora programada; un video ya creado se resuelve por su ID (sin crear otro). Una historia de foto
 * no lleva texto: se reconoce por el ID de la foto subida (`progress.photos`) en `/{page}/stories` (lectura SIN VERIFICAR en la
 * documentacion): si la consulta falla o no trae los campos esperados el resultado es INCONCLUSO (transitorio, nunca «no publicada»)
 * para no duplicar; lo resuelve el smoke M-04. Un album sin texto se reconoce por las fotos subidas en los `attachments` de `/posts`.
 *
 * Las fotos subidas con `published=false` caducan en Meta a las ~24 h: `progress.photos` guarda la hora de cada subida y a partir de
 * 23 h se descartan y se vuelven a subir (nunca se manda a `/feed` un ID caducado).
 */
class FacebookPublisher implements FirstCommenter, Publisher, StatusPoller
{
    private const RECENT = 25;

    /** Segundos que se confia en una foto subida sin publicar (Meta la borra a las ~24 h). */
    private const PHOTO_TTL = 23 * 3600;

    public function __construct(private readonly GraphClient $graph = new GraphClient) {}

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
        if (($errors = FacebookPostOptions::errors($request->options, $request->type)) !== []) {
            throw new PermanentPublishException(implode(' ', $errors), 'opciones_facebook_invalidas');
        }
        $token = (string) $account->access_token;
        $page = $account->external_id;
        $media = $request->firstMedia();
        $text = (string) $request->text;

        switch ($request->type) {
            case ContentType::FacebookText:
                $params = ['message' => $text] + FacebookPostOptions::placeParams($request->options);
                if (! empty($request->options['link'])) {
                    $params['link'] = (string) $request->options['link'];
                }
                $res = $this->graph->post("$page/feed", $params, $token);

                return $this->published($res['id'] ?? null, $account);

            case ContentType::FacebookPhoto:
                $this->requireMedia($media, 'image');
                $res = $this->graph->post("$page/photos", ['url' => $media->url, 'published' => 'true'] + ($text !== '' ? ['caption' => $text] : []) + ($media->alt !== null && $media->alt !== '' ? ['alt_text_custom' => $media->alt] : []) + FacebookPostOptions::placeParams($request->options), $token);

                // `post_id` es el ID de la publicacion en el muro; `id` el de la foto.
                return $this->published($res['post_id'] ?? $res['id'] ?? null, $account);

            case ContentType::FacebookVideo:
                $this->requireMedia($media, 'video');
                $res = $this->graph->post("$page/videos", ['file_url' => $media->url] + ($text !== '' ? ['description' => $text] : []) + FacebookPostOptions::params($request->options), $token);

                return $this->processing($res['id'] ?? null);

            case ContentType::FacebookReel:
                $this->requireMedia($media, 'video');

                return $this->startUpload($account, "$page/video_reels", $media->url, $text, FacebookPostOptions::params($request->options));

            case ContentType::FacebookPhotos:
                if (count($request->media) < 2) {
                    throw new PermanentPublishException('Una publicacion de varias fotos necesita al menos 2 imagenes.', 'media_invalida');
                }
                foreach ($request->media as $item) {
                    $this->requireMedia($item, 'image'); // todo el album se valida antes de subir nada
                }
                $photos = $this->uploadPhotos($account, $request);
                $params = ($text !== '' ? ['message' => $text] : []) + FacebookPostOptions::placeParams($request->options);
                foreach (array_values($photos) as $i => $id) {
                    $params["attached_media[$i]"] = json_encode(['media_fbid' => $id]);
                }

                return $this->published($this->graph->post("$page/feed", $params, $token)['id'] ?? null, $account);

            case ContentType::FacebookStory:
                if ($media === null) {
                    throw new PermanentPublishException('Falta el archivo de la historia.', 'media_invalida');
                }
                if ($media->isVideo()) {
                    return $this->startUpload($account, "$page/video_stories", $media->url);
                }
                $photoId = array_values($this->uploadPhotos($account, $request))[0];
                $res = $this->graph->post("$page/photo_stories", ['photo_id' => $photoId], $token);
                $storyId = (string) ($res['post_id'] ?? (($res['success'] ?? false) ? $photoId : ''));
                if ($storyId === '') {
                    throw new PermanentPublishException('Facebook no devolvio el ID de la historia.', 'sin_id');
                }

                return PublishResult::published($storyId);

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

        if ($request->type === ContentType::FacebookStory) {
            return $this->findStory($account, $request);
        }

        if ($request->type === ContentType::FacebookPhotos) {
            return $this->findAlbum($account, $request);
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

    /**
     * IDs de Facebook de las fotos ya subidas por este destino que siguen vigentes (media id => id), segun `progress.photos`
     * (`{media id: {id, at}}`). Sin hora guardada, o con mas de 23 h, la foto se considera caducada.
     *
     * @return array<string, string>
     */
    private function freshPhotos(PublishRequest $request): array
    {
        $out = [];
        foreach ((array) ($request->progress['photos'] ?? []) as $key => $photo) {
            if (is_array($photo) && (string) ($photo['id'] ?? '') !== '' && isset($photo['at']) && now()->timestamp - (int) $photo['at'] <= self::PHOTO_TTL) {
                $out[(string) $key] = (string) $photo['id'];
            }
        }

        return $out;
    }

    /**
     * Sube cada imagen del destino SIN publicarla (`published=false`) y devuelve el ID de Facebook de cada una por media. Las ya
     * subidas y vigentes (`freshPhotos`) se reutilizan; cada subida nueva se guarda en el acto con su hora (`checkpoint`).
     *
     * @return array<string, string> media id => id de la foto en Facebook, en orden de publicacion
     */
    private function uploadPhotos(SocialAccount $account, PublishRequest $request): array
    {
        $fresh = $this->freshPhotos($request);
        $done = [];
        foreach ($fresh as $key => $id) {
            $done[$key] = ['id' => $id, 'at' => (int) $request->progress['photos'][$key]['at']];
        }
        $out = [];

        foreach ($request->media as $item) {
            $this->requireMedia($item, 'image');
            $key = (string) $item->id;

            if (! isset($done[$key])) {
                $res = $this->graph->post("{$account->external_id}/photos", ['url' => $item->url, 'published' => 'false'] + ($item->alt !== null && $item->alt !== '' ? ['alt_text_custom' => $item->alt] : []), (string) $account->access_token);
                if ((string) ($res['id'] ?? '') === '') {
                    throw new PermanentPublishException('Facebook no devolvio el ID de la foto subida.', 'sin_id');
                }
                $done[$key] = ['id' => (string) $res['id'], 'at' => now()->timestamp];
                $request->checkpoint(null, ['photos' => $done]);
            }
            $out[$key] = $done[$key]['id'];
        }

        return $out;
    }

    /**
     * Album ya publicado: por texto y hora (como el resto) o, sin texto, por las fotos subidas en los `attachments` de `/posts`
     * (campo `subattachments{target{id}}`, SIN VERIFICAR en esta sesion). Si hay fotos subidas y la consulta falla, es inconcluso.
     */
    private function findAlbum(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $text = trim((string) $request->text);
        $ids = array_values($this->freshPhotos($request));

        if ($text === '' && $ids === []) {
            return null; // sin texto ni fotos subidas vigentes no hay forma de reconocerla (ni nada que reconocer)
        }

        try {
            $data = $this->graph->get("{$account->external_id}/posts", ['fields' => 'id,message,permalink_url,created_time,attachments{subattachments{target{id}}}', 'limit' => self::RECENT], (string) $account->access_token);
        } catch (PublishException $e) {
            Log::notice('facebook.lookup_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            if ($ids !== []) {
                throw new TransientPublishException('No se pudo comprobar si el album de Facebook ya se publico; se reintenta sin volver a publicar.');
            }

            return null;
        }

        foreach ($data['data'] ?? [] as $item) {
            $when = isset($item['created_time']) ? strtotime((string) $item['created_time']) : false;
            $sameText = $text !== '' && trim((string) ($item['message'] ?? '')) === $text && ($request->since === null || ($when !== false && $when >= $request->since->getTimestamp()));
            $attached = [];
            foreach ((array) ($item['attachments']['data'] ?? []) as $attachment) {
                foreach ((array) ($attachment['subattachments']['data'] ?? []) as $sub) {
                    $attached[] = (string) ($sub['target']['id'] ?? '');
                }
            }

            if ($sameText || ($ids !== [] && array_diff($ids, $attached) === [])) {
                return PublishResult::published((string) $item['id'], $this->url($item['permalink_url'] ?? null));
            }
        }

        return null;
    }

    /**
     * Historia de foto ya publicada: la que usa la foto subida por este destino, en `GET /{page}/stories` (SIN VERIFICAR). Si la
     * consulta falla o la respuesta no trae los campos esperados (`data[].media_id` y `post_id`) no se sabe si salio: INCONCLUSO
     * (transitorio) para no duplicarla; una lista bien formada sin la foto, no publicada. Lo resuelve el smoke M-04.
     */
    private function findStory(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $ids = array_values($this->freshPhotos($request));

        if ($ids === []) {
            return null; // sin foto subida vigente no hay historia que reconocer
        }

        $inconclusive = new TransientPublishException('No se pudo comprobar si la historia de Facebook ya se publico (consulta /stories sin verificar, ver M-04); se reintenta sin volver a publicar.');

        try {
            $data = $this->graph->get("{$account->external_id}/stories", ['fields' => 'post_id,media_id,status,creation_time,url', 'limit' => self::RECENT], (string) $account->access_token);
        } catch (PublishException $e) {
            Log::notice('facebook.lookup_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            throw $inconclusive;
        }

        if (! is_array($data['data'] ?? null)) {
            throw $inconclusive;
        }

        foreach ($data['data'] as $item) {
            if (! is_array($item) || ! array_key_exists('media_id', $item) || ! array_key_exists('post_id', $item)) {
                throw $inconclusive;
            }
            if (in_array((string) $item['media_id'], $ids, true)) {
                return PublishResult::published((string) $item['post_id'], $this->url($item['url'] ?? null));
            }
        }

        return null;
    }

    /** Reel o historia de video en 3 pasos: abrir la sesion, indicar el fichero por URL y cerrar publicandolo. */
    private function startUpload(SocialAccount $account, string $edge, string $url, string $description = '', array $extra = []): PublishResult
    {
        $token = (string) $account->access_token;

        $start = $this->graph->post($edge, ['upload_phase' => 'start'], $token);
        $videoId = (string) ($start['video_id'] ?? '');
        $uploadUrl = (string) ($start['upload_url'] ?? '');

        if ($videoId === '' || $uploadUrl === '') {
            throw new PermanentPublishException('Facebook no abrio la sesion de subida del video.', 'sin_sesion');
        }

        $this->graph->postToUrl($uploadUrl, ['Authorization' => 'OAuth '.$token, 'file_url' => $url]);

        $this->graph->post($edge, [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
        ] + ($description !== '' ? ['description' => $description] : []) + $extra, $token);

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
