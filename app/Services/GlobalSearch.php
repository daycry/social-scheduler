<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\Campaign;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\Site;
use App\Models\SmartLink;
use App\Models\Studio\Design;
use App\Models\Studio\DesignShare;
use App\Models\User;
use App\Services\Studio\StudioCollab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Búsqueda global de la paleta de comandos (T-85, F-11): contenido del usuario por texto o nombre, con la MISMA visibilidad que el listado
 * de cada cosa (no se inventa otra regla):
 *  - Publicaciones: las propias fuera de la papelera, y las pendientes de aprobar de las marcas donde es aprobador (la bandeja de aprobaciones,
 *    `ApprovalService::queryFor`); el resto de publicaciones de una marca ajena no se listan en ninguna parte.
 *  - Diseños: los propios, los compartidos directamente y los de marcas donde es aprobador (`StudioCollab::sharedWith`).
 *  - Marcas, archivos, campañas, plantillas, sitios y smart links: solo los propios (ningún listado muestra los de otro usuario).
 *
 * Una consulta por tipo (fija, con `limit`) y marca/zona horaria por JOIN, sin N+1. El LIKE escapa `%`, `_` y `!`; SQLite ignora mayúsculas solo en
 * ASCII y MySQL según la collation de la columna (con `utf8mb4_unicode_ci` ignora también los acentos).
 */
class GlobalSearch
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 100;

    public const PER_TYPE = 5;

    public const TOTAL = 20;

    private const TITLE_LENGTH = 120;

    /** Tipos en el orden en que se muestran. */
    private const TYPES = ['post', 'brand', 'design', 'media', 'campaign', 'template', 'site', 'smartlink'];

    /** Tipos de fichero que lista la biblioteca (imagen, video y PDF); el audio es de uso interno del estudio. */
    private const MEDIA_KINDS = [MediaAsset::KIND_IMAGE, MediaAsset::KIND_VIDEO, MediaAsset::KIND_PDF];

    private const MEDIA_LABELS = [MediaAsset::KIND_IMAGE => 'Imagen', MediaAsset::KIND_VIDEO => 'Vídeo', MediaAsset::KIND_PDF => 'PDF'];

    /**
     * @return list<array{type: string, id: int, title: string, brand: ?string, date: ?string, status: ?string, url: string, external: bool}>
     */
    public function search(User $user, string $term): array
    {
        $byType = [
            'post' => $this->posts($user, $term),
            'brand' => $this->brands($user, $term),
            'design' => $this->designs($user, $term),
            'media' => $this->media($user, $term),
            'campaign' => $this->campaigns($user, $term),
            'template' => $this->templates($user, $term),
            'site' => $this->sites($user, $term),
            'smartlink' => $this->smartLinks($user, $term),
        ];

        return $this->share($byType);
    }

    /**
     * Reparte el tope total por turnos entre los tipos, para que uno con muchas coincidencias no deje a los demás sin hueco,
     * y devuelve el resultado agrupado en el orden de los tipos.
     *
     * @param  array<string, list<array<string, mixed>>>  $byType
     * @return list<array<string, mixed>>
     */
    private function share(array $byType): array
    {
        $taken = array_fill_keys(self::TYPES, 0);
        $budget = self::TOTAL;
        while ($budget > 0) {
            $grew = false;
            foreach (self::TYPES as $type) {
                if ($budget > 0 && $taken[$type] < count($byType[$type])) {
                    $taken[$type]++;
                    $budget--;
                    $grew = true;
                }
            }
            if (! $grew) {
                break;
            }
        }

        return array_merge(...array_map(fn (string $type) => array_slice($byType[$type], 0, $taken[$type]), self::TYPES));
    }

    /** @return list<array<string, mixed>> */
    private function posts(User $user, string $term): array
    {
        $approverBrands = BrandCollaborator::query()->approverFor($user->id)->select('brand_id');

        return Post::withoutUserScope()
            ->leftJoin('brands', 'brands.id', '=', 'posts.brand_id')
            ->where(fn (Builder $q) => $q->where('posts.user_id', $user->id)
                ->orWhere(fn (Builder $q) => $q->where('posts.approval_status', Post::APPROVAL_PENDING)->whereIn('posts.brand_id', $approverBrands)))
            ->whereLikeEscaped('posts.text', $term)
            ->orderByDesc('posts.id')->limit(self::PER_TYPE)
            ->get(['posts.id', 'posts.user_id', 'posts.text', 'posts.status', 'posts.scheduled_at', 'brands.name as brand_name', 'brands.timezone as brand_timezone'])
            ->map(function (Post $post) use ($user) {
                $own = $post->user_id === $user->id;

                return $this->row('post', $post->id, $this->title((string) $post->text), $own ? route('posts.edit', $post, false) : route('approvals.index', absolute: false), [
                    'brand' => $post->brand_name,
                    'date' => $post->scheduled_at?->copy()->setTimezone($post->brand_timezone ?: 'UTC')->format('Y-m-d H:i'),
                    'status' => $own ? $post->status->label() : 'Pendiente de aprobar',
                ]);
            })->all();
    }

    /** @return list<array<string, mixed>> */
    private function brands(User $user, string $term): array
    {
        return Brand::ownedBy($user)->whereLikeEscaped('name', $term)->orderBy('name')->orderBy('id')->limit(self::PER_TYPE)->get(['id', 'name'])
            ->map(fn (Brand $brand) => $this->row('brand', $brand->id, $brand->name, route('brands.show', $brand, false)))->all();
    }

    /** @return list<array<string, mixed>> */
    private function designs(User $user, string $term): array
    {
        $shared = DesignShare::query()->where('user_id', $user->id)->whereIn('role', StudioCollab::ROLES)->select('design_id');

        return Design::withoutUserScope()
            ->leftJoin('brands', 'brands.id', '=', 'studio_designs.brand_id')
            ->where(fn (Builder $q) => $q->where('studio_designs.user_id', $user->id)
                ->orWhereIn('studio_designs.id', $shared)
                ->orWhereExists(Design::brandApproverGrant($user->id)->selectRaw('1')))
            ->whereLikeEscaped('studio_designs.name', $term)
            ->orderByDesc('studio_designs.id')->limit(self::PER_TYPE)
            ->get(['studio_designs.id', 'studio_designs.user_id', 'studio_designs.name', 'studio_designs.is_template', 'studio_designs.updated_at', 'brands.name as brand_name', 'brands.timezone as brand_timezone'])
            ->map(fn (Design $design) => $this->row('design', $design->id, $design->name, route('studio.designs.edit', $design, false), [
                'brand' => $design->brand_name,
                'date' => $design->updated_at?->copy()->setTimezone($design->brand_timezone ?: 'UTC')->format('Y-m-d H:i'),
                'status' => $design->is_template ? 'Plantilla' : ($design->user_id === $user->id ? null : 'Compartido'),
            ]))->all();
    }

    /** @return list<array<string, mixed>> */
    private function media(User $user, string $term): array
    {
        return MediaAsset::ownedBy($user)->where('status', MediaAsset::STATUS_READY)->whereIn('kind', self::MEDIA_KINDS)
            ->whereLikeEscaped('original_name', $term)->orderByDesc('id')->limit(self::PER_TYPE)->get(['id', 'original_name', 'kind'])
            ->map(fn (MediaAsset $asset) => $this->row('media', $asset->id, $asset->original_name, route('media.file', $asset, false), [
                'status' => self::MEDIA_LABELS[$asset->kind] ?? null,
                'external' => true,
            ]))->all();
    }

    /** @return list<array<string, mixed>> */
    private function campaigns(User $user, string $term): array
    {
        return Campaign::ownedBy($user)->leftJoin('brands', 'brands.id', '=', 'campaigns.brand_id')
            ->whereLikeEscaped('campaigns.name', $term)->orderByDesc('campaigns.id')->limit(self::PER_TYPE)
            ->get(['campaigns.id', 'campaigns.name', 'campaigns.starts_on', 'campaigns.archived_at', 'brands.name as brand_name'])
            ->map(fn (Campaign $campaign) => $this->row('campaign', $campaign->id, $campaign->name, route('campaigns.show', $campaign->id, false), [
                'brand' => $campaign->brand_name,
                'date' => $campaign->starts_on?->format('Y-m-d'),
                'status' => $campaign->archived_at === null ? null : 'Archivada',
            ]))->all();
    }

    /** @return list<array<string, mixed>> */
    private function templates(User $user, string $term): array
    {
        return PostTemplate::ownedBy($user)->leftJoin('brands', 'brands.id', '=', 'post_templates.brand_id')
            ->whereLikeEscaped('post_templates.name', $term)->orderByDesc('post_templates.id')->limit(self::PER_TYPE)
            ->get(['post_templates.id', 'post_templates.brand_id', 'post_templates.name', 'brands.name as brand_name'])
            ->map(fn (PostTemplate $template) => $this->row('template', $template->id, $template->name, route('library.index', ['brand_id' => $template->brand_id], false), ['brand' => $template->brand_name]))->all();
    }

    /** @return list<array<string, mixed>> */
    private function sites(User $user, string $term): array
    {
        return Site::ownedBy($user)->leftJoin('brands', 'brands.id', '=', 'sites.brand_id')
            ->whereLikeEscaped('sites.title', $term)->orderByDesc('sites.id')->limit(self::PER_TYPE)
            ->get(['sites.id', 'sites.title', 'sites.is_published', 'brands.name as brand_name'])
            ->map(fn (Site $site) => $this->row('site', $site->id, $site->title, route('sites.edit', $site, false), [
                'brand' => $site->brand_name,
                'status' => $site->is_published ? 'Publicado' : 'Borrador',
            ]))->all();
    }

    /** @return list<array<string, mixed>> */
    private function smartLinks(User $user, string $term): array
    {
        return SmartLink::ownedBy($user)->leftJoin('brands', 'brands.id', '=', 'smart_links.brand_id')
            ->whereLikeEscaped('smart_links.title', $term)->orderByDesc('smart_links.id')->limit(self::PER_TYPE)
            ->get(['smart_links.id', 'smart_links.brand_id', 'smart_links.title', 'smart_links.published', 'brands.name as brand_name'])
            ->map(fn (SmartLink $link) => $this->row('smartlink', $link->id, $link->title, route('smartlinks.index', ['brand_id' => $link->brand_id], false), [
                'brand' => $link->brand_name,
                'status' => $link->published ? 'Publicado' : 'Sin publicar',
            ]))->all();
    }

    /** Una sola línea y de longitud acotada: el texto de una publicación puede ser largo y de varias líneas. */
    private function title(string $text): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), self::TITLE_LENGTH);
    }

    /**
     * @param  array{brand?: ?string, date?: ?string, status?: ?string, external?: bool}  $context
     * @return array{type: string, id: int, title: string, brand: ?string, date: ?string, status: ?string, url: string, external: bool}
     */
    private function row(string $type, int $id, string $title, string $url, array $context = []): array
    {
        return ['type' => $type, 'id' => $id, 'title' => $title, 'brand' => $context['brand'] ?? null, 'date' => $context['date'] ?? null,
            'status' => $context['status'] ?? null, 'url' => $url, 'external' => $context['external'] ?? false];
    }
}
