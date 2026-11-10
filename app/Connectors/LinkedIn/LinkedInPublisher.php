<?php

namespace App\Connectors\LinkedIn;

use App\Connectors\Contracts\FirstCommenter;
use App\Connectors\Contracts\Publisher;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\CommentNotAllowedException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Storage;

/**
 * Publicacion en LinkedIn (T-18) con la Posts API (`POST /rest/posts`): texto, una imagen, varias imagenes (`multiImage`, 2 a 20) y video.
 * La media se SUBE desde el disco de la app (Images/Videos API: `initializeUpload` -> PUT -> [`finalizeUpload` en video]); LinkedIn no
 * descarga URL ajenas, asi que no hay fetch remoto de media. El autor es `meta.author_urn` (perfil o pagina). El id de la publicacion es
 * la cabecera `x-restli-id` (`urn:li:share:…` / `urn:li:ugcPost:…`).
 *
 * Idempotencia: los URN de la media subida se guardan en el acto (`PublishRequest::checkpoint`) y un reintento NO sube de nuevo;
 * `findPublished` busca entre las ultimas publicaciones del autor la de mismo texto creada desde `since`. El primer comentario usa
 * `socialActions/{urn}/comments`.
 */
class LinkedInPublisher implements FirstCommenter, Publisher
{
    /** Caracteres reservados del formato de texto de LinkedIn (`#` se deja: asi se crean los hashtags). */
    private const RESERVED = ['\\', '|', '{', '}', '@', '[', ']', '(', ')', '<', '>', '*', '_', '~'];

    /** Cuantas publicaciones recientes se revisan al buscar un duplicado. */
    private const RECENT = 10;

    public function __construct(private readonly LinkedInClient $client = new LinkedInClient) {}

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $this->assertSupported($request);
        $token = (string) $account->access_token;
        if ($token === '') {
            throw new ReconnectRequiredException('La cuenta de LinkedIn no tiene token: reconecta la cuenta.');
        }
        $author = $this->author($account);

        $content = $this->content($account, $request, $author, $token);
        $body = [
            'author' => $author,
            'commentary' => self::escape((string) $request->text),
            'visibility' => $this->visibility($account, $request),
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => ($request->options['linkedin_disable_reshare'] ?? false) === true,
        ] + ($content !== null ? ['content' => $content] : []);

        $response = $this->client->api('POST', 'rest/posts', $token, $body);
        $urn = $response->header('x-restli-id') ?: (string) ($response->json('id') ?? '');
        if ($urn === '') {
            throw new PermanentPublishException('LinkedIn acepto la publicacion pero no devolvio su identificador.', 'sin_id');
        }

        return PublishResult::published($urn, 'https://www.linkedin.com/feed/update/'.$urn.'/', ['visibility' => $body['visibility']]);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $this->assertSupported($request);
        $author = $this->author($account);
        $response = $this->client->api('GET', 'rest/posts', (string) $account->access_token, query: [
            'author' => $author, 'q' => 'author', 'count' => self::RECENT, 'sortBy' => 'LAST_MODIFIED',
        ], headers: ['X-RestLi-Method' => 'FINDER'], read: true);

        $text = self::escape((string) $request->text);
        $since = $request->since?->getTimestamp();
        foreach ((array) ($response->json('elements') ?? []) as $post) {
            $created = isset($post['createdAt']) ? (int) floor(((int) $post['createdAt']) / 1000) : null;
            if (($post['author'] ?? null) === $author && (string) ($post['commentary'] ?? '') === $text
                && ($since === null || ($created !== null && $created >= $since)) && ! empty($post['id'])) {
                return PublishResult::published((string) $post['id'], 'https://www.linkedin.com/feed/update/'.$post['id'].'/');
            }
        }

        return null;
    }

    public function comment(SocialAccount $account, string $externalId, string $text): string
    {
        $response = $this->client->api('POST', 'rest/socialActions/'.rawurlencode($externalId).'/comments', (string) $account->access_token, [
            'actor' => $this->author($account),
            'message' => ['text' => self::escape($text)],
        ]);
        $id = (string) ($response->json('commentUrn') ?? $response->json('id') ?? $response->header('x-restli-id'));
        if ($id === '') {
            throw new CommentNotAllowedException('LinkedIn no devolvio el comentario creado.', 'no_admitido');
        }

        return $id;
    }

    public function findOwnComment(SocialAccount $account, string $externalId, string $text): ?string
    {
        $response = $this->client->api('GET', 'rest/socialActions/'.rawurlencode($externalId).'/comments', (string) $account->access_token, query: ['count' => 50], read: true);
        $author = $this->author($account);
        $wanted = self::escape($text);
        foreach ((array) ($response->json('elements') ?? []) as $comment) {
            if (($comment['actor'] ?? null) === $author && (string) ($comment['message']['text'] ?? '') === $wanted) {
                return (string) ($comment['commentUrn'] ?? $comment['id'] ?? '') ?: null;
            }
        }

        return null;
    }

    /** Escapa los caracteres reservados del texto (los enlaces y los `#hashtag` siguen funcionando). */
    public static function escape(string $text): string
    {
        $out = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $out .= in_array($char, self::RESERVED, true) ? '\\'.$char : $char;
        }

        return $out;
    }

    // ------------------------------------------------------------------ interno

    private function assertSupported(PublishRequest $request): void
    {
        if ($request->type->network()->value !== 'linkedin') {
            throw new PermanentPublishException('LinkedIn no puede publicar contenido de '.$request->type->network()->label().'.', 'tipo_no_admitido');
        }
        $max = (int) config('social.linkedin.text_max', 3000);
        if (mb_strlen((string) $request->text) > $max) {
            throw new PermanentPublishException("LinkedIn admite hasta $max caracteres por publicacion.", 'texto_largo');
        }
    }

    private function author(SocialAccount $account): string
    {
        $author = (string) ($account->meta['author_urn'] ?? '');
        if (! preg_match('/^urn:li:(person|organization):[A-Za-z0-9_-]{1,40}$/D', $author)) {
            throw new PermanentPublishException('La cuenta de LinkedIn no tiene autor valido: reconecta la cuenta.', 'sin_autor');
        }

        return $author;
    }

    private function visibility(SocialAccount $account, PublishRequest $request): string
    {
        $wanted = (string) ($request->options['linkedin_visibility'] ?? 'PUBLIC');
        if ($wanted === 'CONNECTIONS' && ($account->meta['kind'] ?? 'profile') === 'page') {
            throw new PermanentPublishException('Las paginas de LinkedIn publican siempre en abierto.', 'visibilidad');
        }

        return in_array($wanted, LinkedInPostOptions::VISIBILITIES, true) ? $wanted : 'PUBLIC';
    }

    /** Sube la media (o reutiliza los URN ya subidos) y devuelve el bloque `content` de la publicacion. */
    private function content(SocialAccount $account, PublishRequest $request, string $author, string $token): ?array
    {
        if ($request->type === ContentType::LinkedInText) {
            return null;
        }

        $assets = (array) ($request->progress['assets'] ?? []);
        if (count($assets) !== count($request->media)) {
            $assets = [];
            foreach ($request->media as $media) {
                $assets[] = $media->isVideo() ? $this->uploadVideo($media, $author, $token) : $this->uploadImage($media, $author, $token);
                $request->checkpoint(null, ['assets' => $assets]);
            }
        }

        if ($request->type === ContentType::LinkedInCarousel) {
            return ['multiImage' => ['images' => array_map(
                fn (string $urn, PublishMedia $m) => ['id' => $urn] + ($m->alt !== null && $m->alt !== '' ? ['altText' => $m->alt] : []),
                $assets, $request->media,
            )]];
        }

        $media = $request->media[0];

        return ['media' => ['id' => $assets[0]] + ($media->alt !== null && $media->alt !== '' && ! $media->isVideo() ? ['altText' => $media->alt] : [])];
    }

    private function uploadImage(PublishMedia $media, string $author, string $token): string
    {
        $this->assertReadable($media);
        $init = $this->client->api('POST', 'rest/images', $token, ['initializeUploadRequest' => ['owner' => $author]], ['action' => 'initializeUpload'])->json('value') ?? [];
        if (empty($init['uploadUrl']) || empty($init['image'])) {
            throw new PermanentPublishException('LinkedIn no devolvio donde subir la imagen.', 'sin_subida');
        }
        $this->client->upload((string) $init['uploadUrl'], (string) Storage::disk($media->disk)->get($media->path), $token);

        return (string) $init['image'];
    }

    private function uploadVideo(PublishMedia $media, string $author, string $token): string
    {
        $this->assertReadable($media);
        $size = (int) ($media->size ?: Storage::disk($media->disk)->size($media->path));
        $init = $this->client->api('POST', 'rest/videos', $token, ['initializeUploadRequest' => [
            'owner' => $author, 'fileSizeBytes' => $size, 'uploadCaptions' => false, 'uploadThumbnail' => false,
        ]], ['action' => 'initializeUpload'])->json('value') ?? [];
        if (empty($init['video']) || empty($init['uploadInstructions'])) {
            throw new PermanentPublishException('LinkedIn no devolvio donde subir el video.', 'sin_subida');
        }

        $stream = Storage::disk($media->disk)->readStream($media->path);
        if (! is_resource($stream)) {
            throw new PermanentPublishException('No se pudo leer el video del almacenamiento.', 'media_ilegible');
        }
        $etags = [];
        try {
            foreach ((array) $init['uploadInstructions'] as $part) {
                $first = (int) ($part['firstByte'] ?? 0);
                $length = (int) ($part['lastByte'] ?? 0) - $first + 1;
                if ($length < 1 || fseek($stream, $first) !== 0) {
                    throw new PermanentPublishException('LinkedIn devolvio un reparto de partes de video invalido.', 'sin_subida');
                }
                $etags[] = $this->client->upload((string) $part['uploadUrl'], (string) fread($stream, $length), $token);
            }
        } finally {
            fclose($stream);
        }

        $this->client->api('POST', 'rest/videos', $token, ['finalizeUploadRequest' => [
            'video' => $init['video'], 'uploadToken' => (string) ($init['uploadToken'] ?? ''), 'uploadedPartIds' => array_values(array_filter($etags)),
        ]], ['action' => 'finalizeUpload']);

        return (string) $init['video'];
    }

    private function assertReadable(PublishMedia $media): void
    {
        if ($media->disk === null || $media->path === null || ! Storage::disk($media->disk)->exists($media->path)) {
            throw new PermanentPublishException('La media no esta disponible en el almacenamiento para subirla a LinkedIn.', 'media_no_disponible');
        }
    }
}
