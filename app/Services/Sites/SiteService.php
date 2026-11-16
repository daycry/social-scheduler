<?php

namespace App\Services\Sites;

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Site;
use App\Models\SmartLink;
use App\Models\User;
use App\Services\SmartLinkService;
use App\Services\Studio\StudioService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Sitios (T-15): constructor (borrador), publicacion (instantanea separada) y tema. La normalizacion de bloques vive en `SiteBlockNormalizer`, el acceso en `SiteAccess`
 * y las visitas, mensajes y encuestas publicas en `SitePublicInteractions`.
 *
 * Decision de diseno: un sitio es un modelo PROPIO (paginas con bloques en JSON) y no un documento del estudio ni una extension
 * de SmartLinks. El documento del estudio es un lienzo de diseno (pagina = lamina exportable) y SmartLinks es una sola pagina plana
 * con bloques en filas; un sitio necesita varias paginas, navegacion y una version publicada inmutable. Se REUTILIZA lo
 * que ya es seguro: el saneado de texto y el id de YouTube de `SmartLinkService`, `SafeUrl`, las exportaciones del estudio
 * (el diseno se incrusta como la imagen ya exportada), el kit de marca (tema) y el patron de pagina publica sin sesion con CSP.
 */
class SiteService
{
    public const MAX_SITES_PER_BRAND = 5;

    public function __construct(private readonly SiteBlockNormalizer $normalizer) {}

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
                'draft' => ['pages' => [['key' => SiteBlockNormalizer::newKey(), 'slug' => 'inicio', 'title' => 'Inicio', 'menu' => true, 'blocks' => [
                    ['key' => SiteBlockNormalizer::newKey(), 'type' => 'heading', 'text' => SmartLinkService::clean($title, 120) ?: $brand->name, 'level' => 2],
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

        $pages = $this->normalizer->pages((array) ($data['pages'] ?? []), $user, $site, $errors);
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

    /**
     * Representacion unica de un sitio para la API v1 y el MCP. Nunca incluye tokens de acceso: solo se muestran al crearlos en la app.
     *
     * @return array<string, mixed>
     */
    public function present(Site $s): array
    {
        return [
            'id' => $s->id, 'brand_id' => $s->brand_id, 'slug' => $s->slug, 'title' => $s->title, 'published' => $s->is_published,
            'access_mode' => $s->access_mode, 'noindex' => $s->noindex, 'url' => $s->url(),
            'accessible' => $s->is_published && $s->access_mode === Site::ACCESS_PUBLIC,
            'published_at' => $s->published_at?->toIso8601String(), 'has_unpublished_changes' => $s->is_published && $s->published_revision !== $s->revision,
        ];
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
        $ready = $ids === [] ? [] : MediaAsset::ownedBy($user->id)->where('kind', MediaAsset::KIND_IMAGE)
            ->where('status', MediaAsset::STATUS_READY)->whereIn('id', $ids)->pluck('id')->all();
        if (array_diff($ids, $ready) !== []) {
            throw new PostValidationException(['pages' => ['Alguna imagen del sitio ya no está disponible. Revisa las imágenes antes de publicar.']]);
        }

        // La direccion del borrador pasa a ser la publica solo aqui; la anterior queda reservada para este sitio y redirige.
        $old = $site->public_slug;
        if ($old !== null && $old !== $site->slug) {
            DB::table('site_slug_history')->insertOrIgnore(['site_id' => $site->id, 'slug' => $old, 'created_at' => now()]);
        }
        DB::table('site_slug_history')->where('site_id', $site->id)->where('slug', $site->slug)->delete();

        $site->forceFill([
            'public_slug' => $site->slug,
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

    private function own(User $user, Site $site): void
    {
        abort_unless($site->user_id === $user->id, 404);
    }

    private function assertSlug(string $slug, ?int $ignore): void
    {
        if (preg_match('/^'.SmartLink::SLUG_PATTERN.'$/', $slug) !== 1) {
            throw new PostValidationException(['slug' => ['Usa de 3 a 40 caracteres: minúsculas, números y guiones (sin guion al principio ni al final).']]);
        }
        $taken = Site::withoutUserScope()->where(fn ($q) => $q->where('slug', $slug)->orWhere('public_slug', $slug))->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()
            || DB::table('site_slug_history')->where('slug', $slug)->when($ignore, fn ($q) => $q->where('site_id', '!=', $ignore))->exists();
        if ($taken) {
            throw new PostValidationException(['slug' => ['Ese enlace ya está en uso.']]);
        }
    }
}
