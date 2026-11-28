<?php

namespace App\Services\Sites;

use App\Models\MediaAsset;
use App\Models\Site;
use App\Models\Studio\DesignExport;
use App\Models\User;
use App\Services\SmartLinkService;
use App\Support\SafeUrl;
use App\Support\SiteRichText;

/**
 * Normalizacion del borrador de un sitio (T-15): paginas y bloques. Todo lo que entra se sanea aqui y lo que sale es lo unico que
 * se guarda. Hay un normalizador privado por tipo de bloque; cada uno devuelve `[atributos, errores]` y nunca lanza.
 */
class SiteBlockNormalizer
{
    public const MAX_PAGES = 12;

    public const MAX_BLOCKS = 40;

    public const BLOCK_TYPES = ['heading', 'text', 'image', 'gallery', 'button', 'youtube', 'contact', 'design', 'faq', 'poll'];

    public const PAGE_SLUG = '[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?';

    /** Clave publica de una pagina o bloque (8 hex). */
    public static function newKey(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * @param  array<int, mixed>  $input
     * @param  array<string, list<string>>  $errors
     * @return list<array<string, mixed>>
     */
    public function pages(array $input, User $user, Site $site, array &$errors): array
    {
        if ($input === [] || ! array_is_list($input)) {
            $errors['pages'][] = 'El sitio necesita al menos una página.';

            return [];
        }
        if (count($input) > self::MAX_PAGES) {
            $errors['pages'][] = 'Máximo '.self::MAX_PAGES.' páginas.';

            return [];
        }

        $pageKeys = $this->pageKeys($input);
        $media = $this->ownedMedia($input, $user);
        $pages = [];
        $slugs = [];
        foreach ($input as $i => $page) {
            $pages[] = $this->page((array) $page, $i, $site, $media, $pageKeys, $pages, $slugs, $errors);
        }

        if (strlen((string) json_encode($pages)) > 400_000) {
            $errors['pages'][] = 'El sitio es demasiado grande.';
        }

        return $pages;
    }

    /**
     * Claves de pagina que un boton puede enlazar: las del borrador (o una nueva si falta o no es valida).
     *
     * @param  array<int, mixed>  $input
     * @return list<string>
     */
    private function pageKeys(array $input): array
    {
        $keys = [];
        foreach ($input as $page) {
            $keys[$this->validKey($page)] = true;
        }

        return array_map(strval(...), array_keys($keys));
    }

    private function validKey(mixed $page): string
    {
        return is_array($page) && is_string($page['key'] ?? null) && preg_match('/^[a-f0-9]{8}$/', $page['key']) === 1 ? $page['key'] : self::newKey();
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array{media: array<int, true>, exports: array<int, array<string, mixed>>}  $media
     * @param  list<string>  $pageKeys
     * @param  list<array<string, mixed>>  $done  paginas ya normalizadas (para no repetir claves)
     * @param  array<string, true>  $slugs
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    private function page(array $page, int $i, Site $site, array $media, array $pageKeys, array $done, array &$slugs, array &$errors): array
    {
        $key = is_string($page['key'] ?? null) && preg_match('/^[a-f0-9]{8}$/', $page['key']) === 1 && ! in_array($page['key'], array_column($done, 'key'), true) ? $page['key'] : self::newKey();
        $title = SmartLinkService::clean($page['title'] ?? '', 80);
        $slug = strtolower(trim((string) ($page['slug'] ?? '')));
        if ($title === '') {
            $errors["pages.$i.title"][] = 'La página necesita un título.';
        }
        if (preg_match('/^'.self::PAGE_SLUG.'$/', $slug) !== 1) {
            $errors["pages.$i.slug"][] = 'La dirección de la página usa minúsculas, números y guiones (hasta 40).';
        } elseif (isset($slugs[$slug])) {
            $errors["pages.$i.slug"][] = 'Dos páginas no pueden tener la misma dirección.';
        }
        $slugs[$slug] = true;

        return ['key' => $key, 'slug' => $slug, 'title' => $title, 'menu' => (bool) ($page['menu'] ?? true), 'blocks' => $this->blocks((array) ($page['blocks'] ?? []), $i, $site, $media, $pageKeys, $errors)];
    }

    /**
     * @param  array<int, mixed>  $blocksIn
     * @param  array{media: array<int, true>, exports: array<int, array<string, mixed>>}  $media
     * @param  list<string>  $pageKeys
     * @param  array<string, list<string>>  $errors
     * @return list<array<string, mixed>>
     */
    private function blocks(array $blocksIn, int $i, Site $site, array $media, array $pageKeys, array &$errors): array
    {
        if (count($blocksIn) > self::MAX_BLOCKS) {
            $errors["pages.$i.blocks"][] = 'Máximo '.self::MAX_BLOCKS.' bloques por página.';
            $blocksIn = array_slice($blocksIn, 0, self::MAX_BLOCKS);
        }
        $blocks = [];
        $contacts = 0;
        foreach (array_values($blocksIn) as $j => $b) {
            [$attrs, $blockErrors] = $this->block((array) $b, $site, $media, $pageKeys);
            foreach ($blockErrors as $field => $messages) {
                $errors["pages.$i.blocks.$j.$field"] = $messages;
            }
            $contacts += ($attrs['type'] ?? null) === 'contact' ? 1 : 0;
            $attrs['key'] = is_string($b['key'] ?? null) && preg_match('/^[a-f0-9]{8}$/', $b['key']) === 1 && ! in_array($b['key'], array_column($blocks, 'key'), true) ? $b['key'] : self::newKey();
            $blocks[] = $attrs;
        }
        if ($contacts > 1) {
            $errors["pages.$i.blocks"][] = 'Solo puede haber un formulario de contacto por página.';
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $b
     * @param  array{media: array<int, true>, exports: array<int, array<string, mixed>>}  $owned
     * @param  list<string>  $pageKeys
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    private function block(array $b, Site $site, array $owned, array $pageKeys): array
    {
        $type = $b['type'] ?? null;
        if (! in_array($type, self::BLOCK_TYPES, true)) {
            return [['type' => 'text', 'body' => ''], ['type' => ['Tipo de bloque no válido.']]];
        }

        return match ($type) {
            'heading' => $this->heading($b),
            'text' => $this->text($b),
            'image' => $this->image($b, $owned),
            'gallery' => $this->gallery($b, $owned),
            'button' => $this->button($b, $pageKeys),
            'youtube' => $this->youtube($b),
            'contact' => $this->contact($b),
            'design' => $this->design($b, $site, $owned),
            'faq' => $this->faq($b),
            'poll' => $this->poll($b),
        };
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
    private function heading(array $b): array
    {
        $out = ['type' => 'heading', 'text' => SmartLinkService::clean($b['text'] ?? '', 120), 'level' => (int) ($b['level'] ?? 2) === 3 ? 3 : 2];

        return [$out, $out['text'] === '' ? ['text' => ['El título es obligatorio.']] : []];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
    private function text(array $b): array
    {
        $out = ['type' => 'text', 'body' => SiteRichText::clean($b['body'] ?? '')];

        return [$out, $out['body'] === '' ? ['body' => ['El texto es obligatorio.']] : []];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
    private function image(array $b, array $owned): array
    {
        $e = [];
        $out = ['type' => 'image', 'media_id' => (int) ($b['media_id'] ?? 0), 'alt' => SmartLinkService::clean($b['alt'] ?? '', 150), 'caption' => SmartLinkService::clean($b['caption'] ?? '', 150)];
        if (! $this->mediaOk($owned, $out['media_id'])) {
            $e['media_id'][] = 'Elige una imagen tuya.';
        }
        if ($out['alt'] === '') {
            $e['alt'][] = 'Describe la imagen (texto alternativo).';
        }

        return [$out, $e];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
    private function gallery(array $b, array $owned): array
    {
        $items = [];
        foreach (array_slice((array) ($b['items'] ?? []), 0, 12) as $it) {
            $it = (array) $it;
            $items[] = ['media_id' => (int) ($it['media_id'] ?? 0), 'alt' => SmartLinkService::clean($it['alt'] ?? '', 150)];
        }
        $e = [];
        if ($items === []) {
            $e['items'][] = 'Añade al menos una imagen.';
        }
        foreach ($items as $it) {
            if (! $this->mediaOk($owned, $it['media_id'])) {
                $e['items'][] = 'Todas las imágenes deben ser tuyas.';
                break;
            }
            if ($it['alt'] === '') {
                $e['items'][] = 'Cada imagen necesita su texto alternativo.';
                break;
            }
        }

        return [['type' => 'gallery', 'items' => $items], $e];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
    private function button(array $b, array $pageKeys): array
    {
        $e = [];
        $out = ['type' => 'button', 'label' => SmartLinkService::clean($b['label'] ?? '', 60), 'kind' => ($b['kind'] ?? 'url') === 'page' ? 'page' : 'url'];
        if ($out['label'] === '') {
            $e['label'][] = 'El texto del botón es obligatorio.';
        }
        if ($out['kind'] === 'page') {
            $out['page'] = (string) ($b['page'] ?? '');
            if (! in_array($out['page'], $pageKeys, true)) {
                $e['page'][] = 'Elige una página del sitio.';
            }
        } else {
            $out['url'] = (string) ($b['url'] ?? '');
            if (! SafeUrl::isSafe($out['url'])) {
                $e['url'][] = 'La URL debe empezar por http:// o https://.';
            }
        }

        return [$out, $e];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
    private function youtube(array $b): array
    {
        $e = [];
        $out = ['type' => 'youtube', 'url' => (string) ($b['url'] ?? '')];
        $out['video_id'] = SmartLinkService::youtubeId($out['url']);
        $out['title'] = SmartLinkService::clean($b['title'] ?? '', 100);
        if ($out['video_id'] === null) {
            $e['url'][] = 'Usa la URL de un video de YouTube (youtube.com o youtu.be).';
        }
        if ($out['title'] === '') {
            $e['title'][] = 'Pon un título al video (lo leen los lectores de pantalla).';
        }

        return [$out, $e];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
    private function contact(array $b): array
    {
        return [[
            'type' => 'contact',
            'title' => SmartLinkService::clean($b['title'] ?? '', 80),
            'cta' => SmartLinkService::clean($b['cta'] ?? '', 30) ?: 'Enviar',
            'consent' => SmartLinkService::clean($b['consent'] ?? '', 200),
        ], []];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
    private function design(array $b, Site $site, array $owned): array
    {
        $e = [];
        $export = $owned['exports'][(int) ($b['export_id'] ?? 0)] ?? null;
        $page = (int) ($b['page'] ?? 0);
        $out = ['type' => 'design', 'export_id' => (int) ($b['export_id'] ?? 0), 'page' => $page, 'alt' => SmartLinkService::clean($b['alt'] ?? '', 150)];
        $out['media_id'] = $export['media'][$page] ?? 0;
        if (! $export || ! $this->mediaOk($owned, $out['media_id']) || (int) $export['brand_id'] !== $site->brand_id) {
            $e['export_id'][] = 'Elige una exportación de imagen de esta marca.';
        }
        if ($out['alt'] === '') {
            $e['alt'][] = 'Describe el diseño (texto alternativo).';
        }

        return [$out, $e];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
    private function faq(array $b): array
    {
        $items = [];
        foreach (array_slice((array) ($b['items'] ?? []), 0, 10) as $it) {
            $it = (array) $it;
            $items[] = ['q' => SmartLinkService::clean($it['q'] ?? '', 150), 'a' => SiteRichText::clean($it['a'] ?? '', 1000)];
        }
        $e = [];
        if ($items === [] || collect($items)->contains(fn ($i) => $i['q'] === '' || $i['a'] === '')) {
            $e['items'][] = 'Cada pregunta necesita su respuesta (al menos una).';
        }

        return [['type' => 'faq', 'title' => SmartLinkService::clean($b['title'] ?? '', 80), 'items' => $items], $e];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
    private function poll(array $b): array
    {
        $e = [];
        $out = ['type' => 'poll', 'question' => SmartLinkService::clean($b['question'] ?? '', 150)];
        $out['options'] = array_values(array_filter(array_map(fn ($o) => SmartLinkService::clean($o, 60), array_slice((array) ($b['options'] ?? []), 0, 6)), fn ($o) => $o !== ''));
        if ($out['question'] === '') {
            $e['question'][] = 'La pregunta es obligatoria.';
        }
        if (count($out['options']) < 2) {
            $e['options'][] = 'Pon al menos dos opciones.';
        }

        return [$out, $e];
    }

    /** @param  array{media: array<int, true>, exports: array<int, array<string, mixed>>}  $owned */
    private function mediaOk(array $owned, mixed $id): bool
    {
        return is_numeric($id) && isset($owned['media'][(int) $id]);
    }

    /**
     * Medios y exportaciones propios que aparecen en el borrador (una consulta cada uno).
     *
     * @param  array<int, mixed>  $pages
     * @return array{media: array<int, true>, exports: array<int, array<string, mixed>>}
     */
    private function ownedMedia(array $pages, User $user): array
    {
        [$ids, $exportIds] = $this->referencedIds($pages);
        $exports = [];
        if ($exportIds !== []) {
            foreach (DesignExport::ownedBy($user->id)->where('status', 'ready')->whereIn('id', array_unique($exportIds))->get() as $x) {
                $exports[$x->id] = ['brand_id' => $x->brand_id, 'media' => array_values((array) $x->media_ids)];
                $ids = array_merge($ids, array_map('intval', (array) $x->media_ids));
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        $owned = $ids === [] ? [] : MediaAsset::ownedBy($user->id)->where('kind', MediaAsset::KIND_IMAGE)
            ->where('status', MediaAsset::STATUS_READY)->whereIn('id', $ids)->pluck('id')->all();

        return ['media' => array_fill_keys($owned, true), 'exports' => $exports];
    }

    /**
     * Ids de medios y de exportaciones que cita el borrador, tal como llegan (aun sin comprobar la propiedad).
     *
     * @param  array<int, mixed>  $pages
     * @return array{0: list<int>, 1: list<int>}
     */
    private function referencedIds(array $pages): array
    {
        $ids = [];
        $exportIds = [];
        foreach ($pages as $page) {
            foreach ((array) (((array) $page)['blocks'] ?? []) as $b) {
                $b = (array) $b;
                if (($b['type'] ?? null) === 'image' && is_numeric($b['media_id'] ?? null)) {
                    $ids[] = (int) $b['media_id'];
                }
                if (($b['type'] ?? null) === 'gallery') {
                    foreach ((array) ($b['items'] ?? []) as $it) {
                        $ids[] = (int) (((array) $it)['media_id'] ?? 0);
                    }
                }
                if (($b['type'] ?? null) === 'design' && is_numeric($b['export_id'] ?? null)) {
                    $exportIds[] = (int) $b['export_id'];
                }
            }
        }

        return [$ids, $exportIds];
    }
}
