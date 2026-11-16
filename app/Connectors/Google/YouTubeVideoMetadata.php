<?php

namespace App\Connectors\Google;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Enums\ContentType;

/**
 * Lo que se declara de un video al subirlo (`videos.insert`): validacion de la peticion, titulo, descripcion, tags y estado.
 * El tag propio de cada destino (`targetTag`) es la base de la idempotencia: `YouTubePublisher::findPublished` reconoce
 * SU video aunque otro lleve el mismo titulo.
 */
final class YouTubeVideoMetadata
{
    private const CATEGORY_PEOPLE_BLOGS = '22';

    private const PRIVACY = ['public', 'unlisted', 'private'];

    /** Limite de `snippet.tags` de YouTube: 500 caracteres en total (las comas cuentan; un tag con espacio suma 2 por las comillas). */
    private const TAGS_LIMIT = 500;

    /** El video de la peticion; lanza si el tipo no es de YouTube o falta el archivo. */
    public static function videoOf(PublishRequest $request): PublishMedia
    {
        $media = $request->firstMedia();

        if (! in_array($request->type, [ContentType::YouTubeVideo, ContentType::YouTubeShort], true)) {
            throw new PermanentPublishException('Tipo de contenido no admitido en YouTube.', 'tipo_no_admitido');
        }

        if ($media === null || ! $media->isVideo()) {
            throw new PermanentPublishException('Falta el archivo de video de la publicacion.', 'media_invalida');
        }

        return $media;
    }

    /**
     * Cuerpo de `videos.insert` y parametros de la consulta de apertura de la sesion reanudable.
     *
     * @return array{body: array<string, mixed>, query: string}
     */
    public static function insertPayload(PublishRequest $request): array
    {
        $privacy = (string) ($request->options['privacy'] ?? 'public');
        $options = $request->options;
        $category = is_scalar($options['category_id'] ?? null) ? (string) $options['category_id'] : '';
        $meta = [
            'snippet' => [
                'title' => self::title($request),
                'description' => self::description($request),
                'categoryId' => preg_match('/^\d{1,3}$/', $category) === 1 ? $category : self::CATEGORY_PEOPLE_BLOGS,
                // El marcador propio del destino va SIEMPRE primero (base de la idempotencia): los tags del usuario se recortan para no expulsarlo.
                'tags' => self::tagsFor($request->targetId, (array) ($options['tags'] ?? [])),
            ],
            'status' => [
                'privacyStatus' => in_array($privacy, self::PRIVACY, true) ? $privacy : 'private',
                'selfDeclaredMadeForKids' => self::flag($options['made_for_kids'] ?? null, false),
            ],
        ];

        // Etiqueta de contenido sintetico (`status.containsSyntheticMedia`): solo si se declara; sin ella no se envia el campo.
        if (self::flag($options['contains_synthetic_media'] ?? null, false)) {
            $meta['status']['containsSyntheticMedia'] = true;
        }

        // `notifySubscribers` es un parametro de la consulta (defecto `true` en la API): solo se envia para desactivarlo.
        $query = 'uploadType=resumable&part=snippet,status'.(self::flag($options['notify_subscribers'] ?? null, true) === false ? '&notifySubscribers=false' : '');

        return ['body' => $meta, 'query' => $query];
    }

    /** Titulo: `options.title` o la primera linea del texto (YouTube admite 100 caracteres y no `<` `>`). */
    private static function title(PublishRequest $request): string
    {
        $raw = trim((string) ($request->options['title'] ?? ''));

        if ($raw === '') {
            $raw = trim((string) strtok(trim((string) $request->text), "\r\n"));
        }

        $title = trim(mb_substr(str_replace(['<', '>'], '', $raw), 0, 100));

        return $title !== '' ? $title : 'Sin titulo';
    }

    private static function description(PublishRequest $request): string
    {
        $text = str_replace(['<', '>'], '', (string) $request->text);

        if ($request->type === ContentType::YouTubeShort
            && self::flag($request->options['shorts_hashtag'] ?? null, true)
            && ! str_contains(mb_strtolower($text), '#shorts')) {
            $text = rtrim(mb_strcut($text, 0, 5000 - 9, 'UTF-8'))."\n\n#Shorts"; // deja sitio al hashtag
        }

        // Limite de YouTube: 5.000 BYTES (no caracteres) de UTF-8; mb_strcut corta sin partir un caracter.
        return mb_strcut($text, 0, 5000, 'UTF-8');
    }

    /**
     * Tag propio del destino (`snippet.tags`, publico): permite reconocer SU video aunque otro lleve el mismo titulo. Es OPACO
     * (HMAC corto con APP_KEY): no expone el id secuencial interno (CWE-200). Rotar APP_KEY cambia el tag: solo afectaria a la
     * idempotencia de un destino con subida en curso en ese instante.
     */
    public static function targetTag(int $targetId): string
    {
        return 'ss-'.substr(hash_hmac('sha256', 'youtube-target:'.$targetId, (string) config('app.key')), 0, 16);
    }

    /**
     * Tags de la subida: el marcador del destino SIEMPRE el primero y los del usuario recortados al hueco que queda, de modo
     * que ni muchos tags ni tags largos o con unicode lo expulsen (`findPublished` reconoce el video por el). Limpia `<` `>` y
     * comas y los caracteres de control (saltos de linea), descarta vacios, duplicados y el propio marcador.
     *
     * @param  list<mixed>  $userTags
     * @return list<string>
     */
    public static function tagsFor(int $targetId, array $userTags): array
    {
        $marker = self::targetTag($targetId);
        $tags = [$marker];
        $used = self::tagCost($marker);
        $seen = [mb_strtolower($marker) => true];

        foreach ($userTags as $raw) {
            if (! is_string($raw)) {
                continue;
            }

            // Sin saltos de linea ni caracteres de control (la API los rechaza o los trunca): se descartan junto a `<`, `>` y la coma.
            $tag = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', str_replace(['<', '>', ','], '', $raw)));
            $key = mb_strtolower($tag);

            if ($tag === '' || isset($seen[$key])) {
                continue;
            }

            $cost = self::tagCost($tag);

            if ($used + $cost > self::TAGS_LIMIT) {
                continue; // no cabe: se omite (un tag mas corto posterior si puede entrar)
            }

            $seen[$key] = true;
            $tags[] = $tag;
            $used += $cost;
        }

        return $tags;
    }

    /** Coste de un tag en el limite: sus caracteres, 2 mas si lleva espacio (comillas) y 1 de la coma que lo separa. */
    private static function tagCost(string $tag): int
    {
        return mb_strlen($tag) + (str_contains($tag, ' ') ? 2 : 0) + 1;
    }

    /** Booleano tolerante (`1`, `"0"`, `true`...): la web y el MCP pueden guardar valores equivalentes sin convertir. Sin valor, el defecto. */
    private static function flag(mixed $value, bool $default): bool
    {
        return $value === null ? $default : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default);
    }
}
