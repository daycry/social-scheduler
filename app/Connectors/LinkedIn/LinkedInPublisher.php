<?php

namespace App\Connectors\LinkedIn;

use App\Connectors\Contracts\FirstCommenter;
use App\Connectors\Contracts\Publisher;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\CommentNotAllowedException;
use App\Connectors\Exceptions\CommentSearchInconclusiveException;
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

    /** Comentarios que se revisan al buscar el primer comentario propio. */
    private const COMMENTS_PAGE = 50;

    public function __construct(private readonly LinkedInClient $client = new LinkedInClient) {}

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $this->assertSupported($request);
        $token = (string) $account->access_token;
        if ($token === '') {
            throw new ReconnectRequiredException('La cuenta de LinkedIn no tiene token: reconecta la cuenta.');
        }
        $author = $this->author($account);

        [$content, $assets] = $this->content($account, $request, $author, $token);
        // Desde aqui un fallo de red puede haber dejado la publicacion creada sin que lo sepamos: se anota antes de enviar para que un
        // reintento sin forma de comprobarlo (perfil sin `r_member_social`) no cree un duplicado a ciegas (ver `findPublished`).
        $request->checkpoint(null, ['assets' => $assets, 'posting' => true]);
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

        $request->checkpoint($urn, ['assets' => $assets]);

        return PublishResult::published($urn, 'https://www.linkedin.com/feed/update/'.$urn.'/', ['visibility' => $body['visibility']]);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $this->assertSupported($request);
        $author = $this->author($account);
        // Un id ya guardado (la creacion respondio pero el job no llego a cerrarse) es la prueba mas fiable y no necesita permisos de lectura.
        if ($request->reference !== null && preg_match('/^urn:li:(share|ugcPost):\d+$/D', $request->reference)) {
            return PublishResult::published($request->reference, 'https://www.linkedin.com/feed/update/'.$request->reference.'/');
        }
        // Listar las publicaciones del autor exige `r_member_social` (perfil) o `r_organization_social` (pagina). Sin el permiso NO se busca.
        if (! self::canRead($account)) {
            return $this->unverifiable($request);
        }
        try {
            $response = $this->client->api('GET', 'rest/posts', (string) $account->access_token, query: [
                'author' => $author, 'q' => 'author', 'count' => self::RECENT, 'sortBy' => 'LAST_MODIFIED',
            ], headers: ['X-RestLi-Method' => 'FINDER'], read: true);
        } catch (PermanentPublishException $e) {
            // Un 403 de la busqueda NO es un fallo de la publicacion: es «no verificable» (no se publica a ciegas ni se falla para siempre).
            if ($e->errorCode === 'permisos') {
                return $this->unverifiable($request);
            }
            throw $e;
        }

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

    /**
     * ¿Puede esta cuenta dejar y localizar el primer comentario? En una pagina si; en un perfil solo con `r_member_social` (sin el no se puede
     * comprobar si ya existe, y un reintento duplicaria el comentario). La UI desactiva el campo con `commentNote`.
     */
    public static function canComment(SocialAccount $account): bool
    {
        return self::canRead($account);
    }

    public static function commentNote(SocialAccount $account): ?string
    {
        return self::canComment($account) ? null : 'El primer comentario en un perfil personal de LinkedIn necesita el permiso r_member_social, que LinkedIn concede solo a apps aprobadas. Sin el no se puede evitar un comentario duplicado en un reintento, asi que esta desactivado.';
    }

    /** ¿Tiene la cuenta permiso para leer sus publicaciones y comentarios? Paginas: Community Management; perfil: `r_member_social`. */
    public static function canRead(SocialAccount $account): bool
    {
        if (($account->meta['kind'] ?? 'profile') === 'page') {
            return true;
        }

        return in_array('r_member_social', (array) $account->scopes, true);
    }

    /**
     * No hay forma de saber si LinkedIn ya publico. Si un intento anterior llego a enviar la peticion (`progress.posting`) y no hay id guardado,
     * se falla con un mensaje claro en vez de duplicar; si nunca se envio nada, se puede publicar.
     */
    private function unverifiable(PublishRequest $request): ?PublishResult
    {
        if (($request->progress['posting'] ?? false) === true) {
            throw new PermanentPublishException('No se puede comprobar en LinkedIn si la publicacion ya salio (falta el permiso de lectura) y un intento anterior pudo enviarla. Comprueba tu perfil: si esta publicada, marca «Ya esta publicada»; si no, publica de nuevo.', 'no_verificable');
        }

        return null;
    }

    public function comment(SocialAccount $account, string $externalId, string $text): string
    {
        if (! self::canComment($account)) {
            throw new CommentNotAllowedException((string) self::commentNote($account), 'permisos');
        }
        $response = $this->client->api('POST', 'rest/socialActions/'.rawurlencode($externalId).'/comments', (string) $account->access_token, [
            'actor' => $this->author($account),
            // El comentario va SIN el escape de los posts (`commentary` usa el formato «little text»; `message.text` de socialActions es texto plano).
            // ⚠️ A confirmar en el smoke real: si LinkedIn interpretara los reservados, habria que escapar aqui tambien.
            'message' => ['text' => $text],
        ]);
        $id = (string) ($response->json('commentUrn') ?? $response->json('id') ?? $response->header('x-restli-id'));
        if ($id === '') {
            throw new CommentNotAllowedException('LinkedIn no devolvio el comentario creado.', 'no_admitido');
        }

        return $id;
    }

    public function findOwnComment(SocialAccount $account, string $externalId, string $text): ?string
    {
        if (! self::canComment($account)) {
            throw new CommentNotAllowedException((string) self::commentNote($account), 'permisos');
        }
        $response = $this->client->api('GET', 'rest/socialActions/'.rawurlencode($externalId).'/comments', (string) $account->access_token, query: ['count' => self::COMMENTS_PAGE], read: true);
        $author = $this->author($account);
        $elements = (array) ($response->json('elements') ?? []);
        foreach ($elements as $comment) {
            if (($comment['actor'] ?? null) === $author && (string) ($comment['message']['text'] ?? '') === $text) {
                return (string) ($comment['commentUrn'] ?? $comment['id'] ?? '') ?: null;
            }
        }
        // Una pagina llena sin coincidencia: puede haber mas comentarios y no se sabe si ya existe. No se crea otro a ciegas.
        if (count($elements) >= self::COMMENTS_PAGE) {
            throw new CommentSearchInconclusiveException('No se pudo comprobar si el comentario ya existe: la publicacion tiene mas de '.self::COMMENTS_PAGE.' comentarios. Comprueba en LinkedIn si ya esta; si lo esta, pulsa «Ya esta publicado».', 'busqueda_inconclusa');
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
    private function content(SocialAccount $account, PublishRequest $request, string $author, string $token): array
    {
        if ($request->type === ContentType::LinkedInText) {
            return [null, []];
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
            return [['multiImage' => ['images' => array_map(
                fn (string $urn, PublishMedia $m) => ['id' => $urn] + ($m->alt !== null && $m->alt !== '' ? ['altText' => $m->alt] : []),
                $assets, $request->media,
            )]], $assets];
        }

        $media = $request->media[0];

        return [['media' => ['id' => $assets[0]] + ($media->alt !== null && $media->alt !== '' && ! $media->isVideo() ? ['altText' => $media->alt] : [])], $assets];
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
        // Portada propia del destino (T-34): `uploadThumbnail` devuelve `thumbnailUploadUrl`; sin portada LinkedIn genera la miniatura.
        $cover = $media->cover;
        if ($cover !== null) {
            $this->assertReadable($cover);
        }
        $init = $this->client->api('POST', 'rest/videos', $token, ['initializeUploadRequest' => [
            'owner' => $author, 'fileSizeBytes' => $size, 'uploadCaptions' => false, 'uploadThumbnail' => $cover !== null,
        ]], ['action' => 'initializeUpload'])->json('value') ?? [];
        if (empty($init['video']) || empty($init['uploadInstructions']) || ($cover !== null && empty($init['thumbnailUploadUrl']))) {
            throw new PermanentPublishException('LinkedIn no devolvio donde subir el video'.($cover !== null ? ' y su portada' : '').'.', 'sin_subida');
        }
        if ($cover !== null) {
            $this->client->upload((string) $init['thumbnailUploadUrl'], (string) Storage::disk($cover->disk)->get($cover->path), $token, ['media-type-family' => 'STILLIMAGE']);
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
