<?php

namespace App\Services\Sites;

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Site;
use App\Models\SiteToken;
use App\Models\SmartLink;
use App\Models\Studio\DesignExport;
use App\Models\User;
use App\Services\SmartLinkService;
use App\Services\Studio\StudioService;
use App\Support\SafeUrl;
use App\Support\SiteRichText;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sitios (T-15): constructor (borrador), publicacion (instantanea separada), acceso y analitica agregada.
 *
 * Decision de diseno: un sitio es un modelo PROPIO (paginas con bloques en JSON) y no un documento del estudio ni una extension
 * de SmartLinks. El documento del estudio es un lienzo de diseno (pagina = lamina exportable) y SmartLinks es una sola pagina plana
 * con bloques en filas; un sitio necesita varias paginas, navegacion y una version publicada inmutable. Se REUTILIZA lo
 * que ya es seguro: el saneado de texto y el id de YouTube de `SmartLinkService`, `SafeUrl`, las exportaciones del estudio
 * (el diseno se incrusta como la imagen ya exportada), el kit de marca (tema) y el patron de pagina publica sin sesion con CSP.
 */
class SiteService
{
    public const MAX_PAGES = 12;

    public const MAX_BLOCKS = 40;

    public const MAX_TOKENS = 20;

    public const MAX_MESSAGES = 500;

    public const MAX_SITES_PER_BRAND = 5;

    public const BLOCK_TYPES = ['heading', 'text', 'image', 'gallery', 'button', 'youtube', 'contact', 'design', 'faq', 'poll'];

    public const PAGE_SLUG = '[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?';

    public function create(User $user, Brand $brand, string $title, string $slug): Site
    {
        if ($brand->user_id !== $user->id) {
            throw new PostValidationException(['brand' => ['La marca no pertenece al usuario.']]);
        }
        if (Site::withoutUserScope()->where('brand_id', $brand->id)->count() >= self::MAX_SITES_PER_BRAND) {
            throw new PostValidationException(['title' => ['Una marca puede tener hasta '.self::MAX_SITES_PER_BRAND.' sitios.']]);
        }
        $this->assertSlug($slug, null);

        try {
            return Site::create([
                'user_id' => $user->id, 'brand_id' => $brand->id, 'slug' => $slug, 'title' => SmartLinkService::clean($title, 100) ?: $brand->name,
                'draft' => ['pages' => [['key' => $this->key(), 'slug' => 'inicio', 'title' => 'Inicio', 'menu' => true, 'blocks' => [
                    ['key' => $this->key(), 'type' => 'heading', 'text' => SmartLinkService::clean($title, 120) ?: $brand->name, 'level' => 2],
                ]]]],
            ])->refresh();
        } catch (UniqueConstraintViolationException) {
            throw new PostValidationException(['slug' => ['Ese enlace ya está en uso.']]);
        }
    }

    /**
     * Guarda el BORRADOR (nunca la version publicada). Todo se normaliza y se sanea aqui; lo que sale es lo unico que se guarda.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(User $user, Site $site, array $data): Site
    {
        $this->own($user, $site);
        $errors = [];
        $slug = (string) ($data['slug'] ?? $site->slug);
        try {
            $this->assertSlug($slug, $site->id);
        } catch (PostValidationException $e) {
            $errors += $e->errors();
        }

        $pages = $this->normalizePages((array) ($data['pages'] ?? []), $user, $site, $errors);
        if ($errors !== []) {
            throw new PostValidationException($errors);
        }

        $site->forceFill([
            'slug' => $slug,
            'title' => SmartLinkService::clean($data['title'] ?? $site->title, 100) ?: $site->title,
            'description' => ($d = SmartLinkService::clean($data['description'] ?? '', 300)) !== '' ? $d : null,
            'theme_source' => ($data['theme_source'] ?? $site->theme_source) === 'custom' ? 'custom' : 'kit',
            'theme_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($data['theme_color'] ?? '')) === 1 ? $data['theme_color'] : $site->theme_color,
            'theme_mode' => ($data['theme_mode'] ?? $site->theme_mode) === 'dark' ? 'dark' : 'light',
            'draft' => ['pages' => $pages],
            'revision' => $site->revision + 1,
        ]);

        try {
            $site->save();
        } catch (UniqueConstraintViolationException) {
            throw new PostValidationException(['slug' => ['Ese enlace ya está en uso.']]);
        }

        return $site;
    }

    /** Publica: copia el borrador (y el tema resuelto) a una instantanea inmutable. Lo editado despues no cambia nada hasta repetirlo. */
    public function publish(User $user, Site $site): Site
    {
        $this->own($user, $site);
        $pages = $site->draft['pages'] ?? [];
        if ($pages === []) {
            throw new PostValidationException(['pages' => ['El sitio necesita al menos una página.']]);
        }
        if ($site->access_mode === Site::ACCESS_PASSWORD && ! $site->password_hash) {
            throw new PostValidationException(['access' => ['Define una contraseña antes de publicar con acceso por contraseña.']]);
        }
        $ids = $this->mediaIds($pages);
        $ready = $ids === [] ? [] : MediaAsset::withoutUserScope()->where('user_id', $user->id)->where('kind', MediaAsset::KIND_IMAGE)
            ->where('status', MediaAsset::STATUS_READY)->whereIn('id', $ids)->pluck('id')->all();
        if (array_diff($ids, $ready) !== []) {
            throw new PostValidationException(['pages' => ['Alguna imagen del sitio ya no está disponible. Revisa las imágenes antes de publicar.']]);
        }

        $site->forceFill([
            'published' => ['title' => $site->title, 'description' => $site->description, 'theme' => $this->theme($user, $site),
                'noindex' => $site->noindex, 'pages' => $pages, 'media' => $ids, 'revision' => $site->revision],
            'is_published' => true, 'published_at' => now(), 'published_revision' => $site->revision,
        ])->save();

        return $site;
    }

    /** Despublica con efecto inmediato: `/s/{slug}` pasa a 404 en la siguiente peticion. La instantanea se conserva para republicar. */
    public function unpublish(User $user, Site $site): Site
    {
        $this->own($user, $site);
        $site->forceFill(['is_published' => false])->save();

        return $site;
    }

    public function delete(User $user, Site $site): void
    {
        $this->own($user, $site);
        $site->delete();
    }

    /** Modo de acceso, contrasena (hash) y noindex. Cambiar el modo o la contrasena cierra todas las sesiones de acceso emitidas. */
    public function updateAccess(User $user, Site $site, string $mode, ?string $password, bool $noindex): Site
    {
        $this->own($user, $site);
        if (! in_array($mode, Site::ACCESS_MODES, true)) {
            throw new PostValidationException(['access_mode' => ['Modo de acceso no válido.']]);
        }
        if ($password !== null && $password !== '' && (mb_strlen($password) < 8 || mb_strlen($password) > 128)) {
            throw new PostValidationException(['password' => ['La contraseña debe tener entre 8 y 128 caracteres.']]);
        }
        $hasPassword = $site->password_hash || ($password !== null && $password !== '');
        if ($mode === Site::ACCESS_PASSWORD && ! $hasPassword) {
            throw new PostValidationException(['password' => ['Define una contraseña para este modo de acceso.']]);
        }

        $changed = $mode !== $site->access_mode || ($password !== null && $password !== '');
        $site->forceFill([
            'access_mode' => $mode, 'noindex' => $noindex,
            'password_hash' => $password !== null && $password !== '' ? Hash::make($password) : $site->password_hash,
            'access_version' => $site->access_version + ($changed ? 1 : 0),
        ])->save();

        return $site;
    }

    /** @return array{0: SiteToken, 1: string} el texto claro solo existe en esta respuesta. */
    public function createToken(User $user, Site $site, string $label, ?int $days): array
    {
        $this->own($user, $site);
        $label = SmartLinkService::clean($label, 60);
        if ($label === '') {
            throw new PostValidationException(['label' => ['Pon un nombre al enlace.']]);
        }
        if ($days !== null && ($days < 1 || $days > 365)) {
            throw new PostValidationException(['days' => ['La caducidad va de 1 a 365 días.']]);
        }
        if (SiteToken::query()->where('site_id', $site->id)->whereNull('revoked_at')->count() >= self::MAX_TOKENS) {
            throw new PostValidationException(['label' => ['Máximo '.self::MAX_TOKENS.' enlaces activos. Revoca alguno.']]);
        }
        $plain = 'st_'.Str::random(40);
        $token = SiteToken::create(['site_id' => $site->id, 'label' => $label, 'token_hash' => hash('sha256', $plain), 'expires_at' => $days ? now()->addDays($days) : null]);

        return [$token, $plain];
    }

    /** Revoca un enlace: sus sesiones dejan de valer en la siguiente peticion (la sesion comprueba el token). */
    public function revokeToken(User $user, Site $site, SiteToken $token): void
    {
        $this->own($user, $site);
        abort_unless($token->site_id === $site->id, 404);
        $token->forceFill(['revoked_at' => $token->revoked_at ?? now()])->save();
    }

    /** Cierra todo acceso concedido (sesiones por contrasena y por enlace) y revoca todos los enlaces. */
    public function revokeAllAccess(User $user, Site $site): void
    {
        $this->own($user, $site);
        DB::transaction(function () use ($site) {
            SiteToken::query()->where('site_id', $site->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $site->forceFill(['access_version' => $site->access_version + 1])->save();
        });
    }

    public function findToken(Site $site, string $plain): ?SiteToken
    {
        if ($plain === '' || strlen($plain) > 100) {
            return null;
        }

        return SiteToken::query()->where('site_id', $site->id)->where('token_hash', hash('sha256', $plain))->first();
    }

    // ---- Tema --------------------------------------------------------------------------------------------------------

    /** Tema resuelto (colores validos y contraste del texto sobre el acento). Con `kit`, el primer color de la paleta de la marca. */
    public function theme(User $user, Site $site): array
    {
        $accent = null;
        $font = null;
        if ($site->theme_source === 'kit') {
            try {
                $kit = app(StudioService::class)->kit($user, $site->brand()->firstOrFail());
            } catch (\Throwable) {
                $kit = [];
            }
            foreach ((array) ($kit['palette'] ?? []) as $c) {
                if (is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1) {
                    $accent = strtolower($c);
                    break;
                }
            }
            $first = (array) ($kit['fonts'] ?? []);
            $font = is_string($first[0] ?? null) && preg_match('/^[A-Za-z0-9 \-]{1,60}$/', $first[0]) === 1 ? $first[0] : null;
        }
        $accent ??= preg_match('/^#[0-9a-fA-F]{6}$/', (string) $site->theme_color) === 1 ? strtolower($site->theme_color) : '#4f46e5';
        $dark = $site->theme_mode === 'dark';

        return [
            'accent' => $accent, 'on_accent' => self::contrast($accent, '#ffffff') >= self::contrast($accent, '#111827') ? '#ffffff' : '#111827',
            'mode' => $dark ? 'dark' : 'light', 'bg' => $dark ? '#111827' : '#ffffff', 'fg' => $dark ? '#f9fafb' : '#111827',
            'muted' => $dark ? '#d1d5db' : '#4b5563', 'card' => $dark ? '#1f2937' : '#f3f4f6', 'font' => $font,
        ];
    }

    private static function contrast(string $a, string $b): float
    {
        $l = function (string $hex): float {
            $c = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, sscanf($hex, '#%02x%02x%02x'));

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$x, $y] = [$l($a), $l($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    // ---- Normalizacion del borrador -----------------------------------------------------------------------------------

    /**
     * @param  array<int, mixed>  $input
     * @param  array<string, list<string>>  $errors
     * @return list<array<string, mixed>>
     */
    private function normalizePages(array $input, User $user, Site $site, array &$errors): array
    {
        if ($input === [] || ! array_is_list($input)) {
            $errors['pages'][] = 'El sitio necesita al menos una página.';

            return [];
        }
        if (count($input) > self::MAX_PAGES) {
            $errors['pages'][] = 'Máximo '.self::MAX_PAGES.' páginas.';

            return [];
        }

        $keys = [];
        $slugs = [];
        foreach ($input as $page) {
            $k = is_array($page) && is_string($page['key'] ?? null) && preg_match('/^[a-f0-9]{8}$/', $page['key']) === 1 ? $page['key'] : $this->key();
            $keys[$k] = true;
        }
        $media = $this->ownedMedia($input, $user);
        $pages = [];
        foreach ($input as $i => $page) {
            $page = (array) $page;
            $key = is_string($page['key'] ?? null) && preg_match('/^[a-f0-9]{8}$/', $page['key']) === 1 && ! in_array($page['key'], array_column($pages, 'key'), true) ? $page['key'] : $this->key();
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

            $blocksIn = (array) ($page['blocks'] ?? []);
            if (count($blocksIn) > self::MAX_BLOCKS) {
                $errors["pages.$i.blocks"][] = 'Máximo '.self::MAX_BLOCKS.' bloques por página.';
                $blocksIn = array_slice($blocksIn, 0, self::MAX_BLOCKS);
            }
            $blocks = [];
            $contacts = 0;
            foreach (array_values($blocksIn) as $j => $b) {
                [$attrs, $blockErrors] = $this->normalizeBlock((array) $b, $user, $site, $media, array_map(strval(...), array_keys($keys)));
                foreach ($blockErrors as $field => $messages) {
                    $errors["pages.$i.blocks.$j.$field"] = $messages;
                }
                $contacts += ($attrs['type'] ?? null) === 'contact' ? 1 : 0;
                $attrs['key'] = is_string($b['key'] ?? null) && preg_match('/^[a-f0-9]{8}$/', $b['key']) === 1 && ! in_array($b['key'], array_column($blocks, 'key'), true) ? $b['key'] : $this->key();
                $blocks[] = $attrs;
            }
            if ($contacts > 1) {
                $errors["pages.$i.blocks"][] = 'Solo puede haber un formulario de contacto por página.';
            }
            $pages[] = ['key' => $key, 'slug' => $slug, 'title' => $title, 'menu' => (bool) ($page['menu'] ?? true), 'blocks' => $blocks];
        }

        if (strlen((string) json_encode($pages)) > 400_000) {
            $errors['pages'][] = 'El sitio es demasiado grande.';
        }

        return $pages;
    }

    /**
     * @param  array<string, mixed>  $b
     * @param  array{media: array<int, true>, exports: array<int, array<string, mixed>>}  $owned
     * @param  list<string>  $pageKeys
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    private function normalizeBlock(array $b, User $user, Site $site, array $owned, array $pageKeys): array
    {
        $type = $b['type'] ?? null;
        if (! in_array($type, self::BLOCK_TYPES, true)) {
            return [['type' => 'text', 'body' => ''], ['type' => ['Tipo de bloque no válido.']]];
        }
        $e = [];
        $out = ['type' => $type];
        $mediaOk = fn ($id) => is_numeric($id) && isset($owned['media'][(int) $id]);

        switch ($type) {
            case 'heading':
                $out['text'] = SmartLinkService::clean($b['text'] ?? '', 120);
                $out['level'] = (int) ($b['level'] ?? 2) === 3 ? 3 : 2;
                if ($out['text'] === '') {
                    $e['text'][] = 'El título es obligatorio.';
                }
                break;
            case 'text':
                $out['body'] = SiteRichText::clean($b['body'] ?? '');
                if ($out['body'] === '') {
                    $e['body'][] = 'El texto es obligatorio.';
                }
                break;
            case 'image':
                $out['media_id'] = (int) ($b['media_id'] ?? 0);
                $out['alt'] = SmartLinkService::clean($b['alt'] ?? '', 150);
                $out['caption'] = SmartLinkService::clean($b['caption'] ?? '', 150);
                if (! $mediaOk($out['media_id'])) {
                    $e['media_id'][] = 'Elige una imagen tuya.';
                }
                if ($out['alt'] === '') {
                    $e['alt'][] = 'Describe la imagen (texto alternativo).';
                }
                break;
            case 'gallery':
                $items = [];
                foreach (array_slice((array) ($b['items'] ?? []), 0, 12) as $it) {
                    $it = (array) $it;
                    $items[] = ['media_id' => (int) ($it['media_id'] ?? 0), 'alt' => SmartLinkService::clean($it['alt'] ?? '', 150)];
                }
                $out['items'] = $items;
                if ($items === []) {
                    $e['items'][] = 'Añade al menos una imagen.';
                }
                foreach ($items as $it) {
                    if (! $mediaOk($it['media_id'])) {
                        $e['items'][] = 'Todas las imágenes deben ser tuyas.';
                        break;
                    }
                    if ($it['alt'] === '') {
                        $e['items'][] = 'Cada imagen necesita su texto alternativo.';
                        break;
                    }
                }
                break;
            case 'button':
                $out['label'] = SmartLinkService::clean($b['label'] ?? '', 60);
                $out['kind'] = ($b['kind'] ?? 'url') === 'page' ? 'page' : 'url';
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
                break;
            case 'youtube':
                $out['url'] = (string) ($b['url'] ?? '');
                $out['video_id'] = SmartLinkService::youtubeId($out['url']);
                $out['title'] = SmartLinkService::clean($b['title'] ?? '', 100);
                if ($out['video_id'] === null) {
                    $e['url'][] = 'Usa la URL de un video de YouTube (youtube.com o youtu.be).';
                }
                if ($out['title'] === '') {
                    $e['title'][] = 'Pon un título al video (lo leen los lectores de pantalla).';
                }
                break;
            case 'contact':
                $out['title'] = SmartLinkService::clean($b['title'] ?? '', 80);
                $out['cta'] = SmartLinkService::clean($b['cta'] ?? '', 30) ?: 'Enviar';
                $out['consent'] = SmartLinkService::clean($b['consent'] ?? '', 200);
                break;
            case 'design':
                $export = $owned['exports'][(int) ($b['export_id'] ?? 0)] ?? null;
                $page = (int) ($b['page'] ?? 0);
                $out['export_id'] = (int) ($b['export_id'] ?? 0);
                $out['page'] = $page;
                $out['alt'] = SmartLinkService::clean($b['alt'] ?? '', 150);
                $out['media_id'] = $export['media'][$page] ?? 0;
                if (! $export || ! $mediaOk($out['media_id']) || (int) $export['brand_id'] !== $site->brand_id) {
                    $e['export_id'][] = 'Elige una exportación de imagen de esta marca.';
                }
                if ($out['alt'] === '') {
                    $e['alt'][] = 'Describe el diseño (texto alternativo).';
                }
                break;
            case 'faq':
                $out['title'] = SmartLinkService::clean($b['title'] ?? '', 80);
                $items = [];
                foreach (array_slice((array) ($b['items'] ?? []), 0, 10) as $it) {
                    $it = (array) $it;
                    $items[] = ['q' => SmartLinkService::clean($it['q'] ?? '', 150), 'a' => SiteRichText::clean($it['a'] ?? '', 1000)];
                }
                $out['items'] = $items;
                if ($items === [] || collect($items)->contains(fn ($i) => $i['q'] === '' || $i['a'] === '')) {
                    $e['items'][] = 'Cada pregunta necesita su respuesta (al menos una).';
                }
                break;
            case 'poll':
                $out['question'] = SmartLinkService::clean($b['question'] ?? '', 150);
                $options = array_values(array_filter(array_map(fn ($o) => SmartLinkService::clean($o, 60), array_slice((array) ($b['options'] ?? []), 0, 6)), fn ($o) => $o !== ''));
                $out['options'] = $options;
                if ($out['question'] === '') {
                    $e['question'][] = 'La pregunta es obligatoria.';
                }
                if (count($options) < 2) {
                    $e['options'][] = 'Pon al menos dos opciones.';
                }
                break;
        }

        return [$out, $e];
    }

    /**
     * Medios y exportaciones propios que aparecen en el borrador (una consulta cada uno).
     *
     * @param  array<int, mixed>  $pages
     * @return array{media: array<int, true>, exports: array<int, array<string, mixed>>}
     */
    private function ownedMedia(array $pages, User $user): array
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
        $exports = [];
        if ($exportIds !== []) {
            foreach (DesignExport::withoutUserScope()->where('user_id', $user->id)->where('status', 'ready')->whereIn('id', array_unique($exportIds))->get() as $x) {
                $exports[$x->id] = ['brand_id' => $x->brand_id, 'media' => array_values((array) $x->media_ids)];
                $ids = array_merge($ids, array_map('intval', (array) $x->media_ids));
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        $owned = $ids === [] ? [] : MediaAsset::withoutUserScope()->where('user_id', $user->id)->where('kind', MediaAsset::KIND_IMAGE)
            ->where('status', MediaAsset::STATUS_READY)->whereIn('id', $ids)->pluck('id')->all();

        return ['media' => array_fill_keys($owned, true), 'exports' => $exports];
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<int>
     */
    public function mediaIds(array $pages): array
    {
        $ids = [];
        foreach ($pages as $page) {
            foreach ($page['blocks'] ?? [] as $b) {
                match ($b['type'] ?? null) {
                    'image', 'design' => $ids[] = (int) $b['media_id'],
                    'gallery' => array_push($ids, ...array_map(fn ($i) => (int) $i['media_id'], $b['items'] ?? [])),
                    default => null,
                };
            }
        }

        return array_values(array_unique($ids));
    }

    // ---- Analitica, formulario y encuesta (publico) ---------------------------------------------------------------------

    public function recordVisit(Site $site, string $pageKey): void
    {
        $key = ['site_id' => $site->id, 'page_key' => $pageKey, 'date' => now('UTC')->toDateString()];
        if (DB::table('site_visits')->where($key)->increment('count') > 0) {
            return;
        }
        try {
            DB::table('site_visits')->insert($key + ['count' => 1]);
        } catch (QueryException) {
            DB::table('site_visits')->where($key)->increment('count');
        }
    }

    /** @return array{total: int, by_page: array<string, int>, daily: list<array{date: string, views: int}>} */
    public function stats(Site $site, int $days = 30): array
    {
        $rows = DB::table('site_visits')->where('site_id', $site->id)->where('date', '>=', now('UTC')->subDays($days - 1)->toDateString())->get();
        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now('UTC')->subDays($i)->toDateString();
            $daily[] = ['date' => $d, 'views' => (int) $rows->where('date', $d)->sum('count')];
        }

        return ['total' => (int) $rows->sum('count'), 'by_page' => $rows->groupBy('page_key')->map(fn ($r) => (int) $r->sum('count'))->all(), 'daily' => $daily];
    }

    public function addMessage(Site $site, string $pageKey, string $blockKey, string $name, string $email, string $message): bool
    {
        $name = SmartLinkService::clean($name, 100);
        $email = mb_substr(trim($email), 0, 254);
        $message = SiteRichText::clean($message, 2000);
        if ($name === '' || $message === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email) === 1) {
            return false;
        }
        if (DB::table('site_messages')->where('site_id', $site->id)->count() >= self::MAX_MESSAGES) {
            return false;
        }
        DB::table('site_messages')->insert(['site_id' => $site->id, 'page_key' => $pageKey, 'block_key' => $blockKey, 'name' => $name, 'email' => $email, 'message' => $message, 'created_at' => now()]);

        return true;
    }

    public static function optionsHash(array $options): string
    {
        return substr(sha1((string) json_encode(array_values($options))), 0, 20);
    }

    public function vote(Site $site, array $block, int $option): bool
    {
        if ($option < 0 || $option >= count($block['options'] ?? [])) {
            return false;
        }
        $key = ['site_id' => $site->id, 'block_key' => $block['key'], 'options_hash' => self::optionsHash($block['options']), 'option_index' => $option];
        if (DB::table('site_poll_votes')->where($key)->increment('count') > 0) {
            return true;
        }
        try {
            DB::table('site_poll_votes')->insert($key + ['count' => 1]);
        } catch (QueryException) {
            DB::table('site_poll_votes')->where($key)->increment('count');
        }

        return true;
    }

    /** @return list<int> votos por opcion (solo los de las opciones vigentes del bloque). */
    public function pollResults(Site $site, array $block): array
    {
        $counts = DB::table('site_poll_votes')->where('site_id', $site->id)->where('block_key', $block['key'])
            ->where('options_hash', self::optionsHash($block['options']))->pluck('count', 'option_index');

        return array_map(fn ($i) => (int) ($counts[$i] ?? 0), array_keys($block['options']));
    }

    private function own(User $user, Site $site): void
    {
        abort_unless($site->user_id === $user->id, 404);
    }

    private function assertSlug(string $slug, ?int $ignore): void
    {
        if (preg_match('/^'.SmartLink::SLUG_PATTERN.'$/', $slug) !== 1) {
            throw new PostValidationException(['slug' => ['Usa de 3 a 40 caracteres: minúsculas, números y guiones (sin guion al principio ni al final).']]);
        }
        if (Site::withoutUserScope()->where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            throw new PostValidationException(['slug' => ['Ese enlace ya está en uso.']]);
        }
    }

    private function key(): string
    {
        return bin2hex(random_bytes(4));
    }
}
