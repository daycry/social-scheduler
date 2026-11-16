<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\Ai\AiProviders;

/**
 * Datos con los que se abre un diseño: la marca (kit, recursos, carpetas, tipografías) y el propio diseño con su rol, sus exportaciones
 * y, para quien no es el dueño, todo reescrito hacia las rutas de ficheros compartidos. Una sola orquestacion para la web (`web`) y la API
 * (`api`): cada una devuelve su contrato, sin que los controladores repitan la logica.
 */
class StudioEditor
{
    public function __construct(
        private readonly StudioService $studio,
        private readonly StudioAssets $assets,
        private readonly StudioExports $exports,
        private readonly StudioCollab $collab,
        private readonly StudioSharedFiles $shared,
        private readonly StudioFonts $fonts,
        private readonly AiProviders $ai,
    ) {}

    /**
     * Propiedades de la marca para la web: kit, recursos, carpetas y tipografias (las de la marca mas las del kit).
     *
     * @return array<string, mixed>
     */
    public function brandProps(User $user, Brand $brand): array
    {
        $this->assets->assertBrand($user, $brand);
        $kit = $this->studio->kit($user, $brand);
        $kitFonts = $this->fonts->many($user, $brand, array_values(array_filter($kit['font_ids'] ?? [])))
            ->map(fn ($font) => $this->fonts->present($font))->values()->all();

        return ['brand' => $brand->only(['id', 'name', 'timezone']), 'kit' => $kit,
            'assets' => $this->assets->list($user, $brand)->map(fn ($asset) => $this->assets->present($brand, $asset))->all(),
            'folders' => $this->studio->folders($user, $brand),
            'fonts' => collect(array_merge($this->fonts->listing($user, $brand)['fonts'], $kitFonts))->unique('id')->values()->all()];
    }

    /**
     * Recursos de la marca con la URL de su fichero en la API.
     *
     * @return list<array<string, mixed>>
     */
    public function apiAssets(User $user, Brand $brand): array
    {
        return $this->assets->list($user, $brand)->map(fn ($asset) => $this->assets->present($brand, $asset)
            + ['file_url' => route('api.studio.assets.file', ['brand' => $brand, 'media' => $asset->id], false)])->all();
    }

    /**
     * Propiedades del editor para la web (y su JSON): la marca del dueno mas el diseño, el acceso de quien lo abre y su historial de exportaciones.
     *
     * @return array<string, mixed>
     */
    public function web(User $actor, Design $design, int $exportsPage): array
    {
        $role = $this->collab->role($actor, $design);
        $user = $this->collab->authorize($actor, $design, 'view');
        $design = $this->studio->owned($user, $design);
        $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($design->brand_id);
        $history = $this->exports->paginated($user, $design, $exportsPage);

        $props = $this->brandProps($user, $brand);
        $ids = array_values(array_filter($this->studio->kit($user, $brand)['font_ids'] ?? []));
        $needed = array_merge($this->fonts->neededFor($user, $brand, $design->document), $this->fonts->many($user, $brand, $ids)->map(fn ($font) => $this->fonts->present($font))->values()->all());
        $props['fonts'] = collect(array_merge($props['fonts'], $needed))->unique('id')->values()->all();
        $presented = $this->studio->present($actor, $design);
        $access = $this->collab->summary($role, $design);

        if ($role !== 'owner') {
            $routes = ['asset' => fn (int $id) => route('studio.shared.asset', ['design' => $design->id, 'media' => $id], false), 'font' => fn (int $id) => route('studio.shared.font', ['design' => $design->id, 'font' => $id], false)];
            $rewritten = $this->shared->rewrite($design, $role, $props['assets'], $props['fonts'], $routes);
            $props['assets'] = $rewritten['assets'];
            $props['fonts'] = $role === 'editor' ? $rewritten['fonts'] : array_values(array_filter($rewritten['fonts'], fn (array $font) => in_array($font['id'], StudioFonts::idsFromDocument($design->document), true)));
            $presented['fonts'] = $this->shared->rewrite($design, $role, [], $presented['fonts'], $routes)['fonts'];
            $props['folders'] = [];
            // Lectores y comentaristas no necesitan la paleta ni las tipografías del kit para ver el diseño.
            $props['kit'] = ['palette' => $role === 'editor' ? $props['kit']['palette'] : [], 'fonts' => $role === 'editor' ? $props['kit']['fonts'] : [], 'font_ids' => $role === 'editor' ? ($props['kit']['font_ids'] ?? []) : [], 'logos' => [], 'guidelines' => '', 'voice' => ''];
            $history = null;
        }

        return $props + ['design' => $presented, 'access' => $access, 'ai_configured' => $role === 'owner' && $this->ai->anyConfigured(),
            'exports' => $history ? $this->exports->presentMany($user, $history->getCollection()) : [],
            'exports_pagination' => $history ? $this->exports->pageMetadata($history) : self::emptyPagination()];
    }

    /**
     * Respuesta de `GET designs/{design}` de la API: el diseño presentado con su kit, recursos y exportaciones.
     *
     * @return array<string, mixed>
     */
    public function api(User $actor, Design $design, int $exportsPage): array
    {
        $role = $this->collab->role($actor, $design);
        $owner = $this->collab->authorize($actor, $design, 'view');
        $design = $this->studio->owned($owner, $design);
        $brand = Brand::withoutUserScope()->where('user_id', $owner->id)->findOrFail($design->brand_id);
        $history = $this->exports->paginated($owner, $design, $exportsPage);
        $presented = $this->studio->present($actor, $design, true);
        $assets = $this->apiAssets($owner, $brand);
        $kit = $this->studio->kit($owner, $brand);
        $exports = $this->exports->presentMany($owner, $history->getCollection());
        $pagination = $this->exports->pageMetadata($history);
        if ($role !== 'owner') {
            $routes = ['asset' => fn (int $id) => route('api.studio.shared.asset', ['design' => $design->id, 'media' => $id], false), 'font' => fn (int $id) => route('api.studio.shared.font', ['design' => $design->id, 'font' => $id], false)];
            $assets = $this->shared->rewrite($design, $role, $assets, [], $routes, true)['assets'];
            $presented['fonts'] = $this->shared->rewrite($design, $role, [], $presented['fonts'], $routes, true)['fonts'];
            $kit = ['palette' => $role === 'editor' ? $kit['palette'] : [], 'fonts' => $role === 'editor' ? $kit['fonts'] : [], 'logos' => [], 'guidelines' => '', 'voice' => ''];
            $exports = [];
            $pagination = self::emptyPagination();
        }

        return $presented + ['kit' => $kit, 'assets' => $assets, 'exports' => $exports, 'exports_pagination' => $pagination];
    }

    /** @return array{current_page: int, last_page: int, per_page: int, total: int, has_more: bool} */
    private static function emptyPagination(): array
    {
        return ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0, 'has_more' => false];
    }
}
