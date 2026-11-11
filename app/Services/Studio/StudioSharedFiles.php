<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recursos del propietario que un colaborador o un enlace necesitan para ver el diseño. Un lector, un comentarista o un
 * enlace solo acceden a lo que el documento referencia; un editor, a toda la biblioteca de la marca del diseño.
 */
class StudioSharedFiles
{
    public function __construct(private readonly StudioAssets $assets, private readonly StudioFonts $fonts) {}

    public function asset(User $owner, Design $design, string $role, int $media, ?string $range): StreamedResponse
    {
        abort_unless($role === 'editor' || in_array($media, StudioCollab::assetIds($design->document), true), 404);

        return $this->assets->file($owner, $this->brand($owner, $design), $media, $range);
    }

    public function font(User $owner, Design $design, string $role, int $font): StreamedResponse
    {
        $brand = $this->brand($owner, $design);
        abort_unless($role === 'editor' || in_array($font, StudioFonts::idsFromDocument($design->document), true), 404);

        return $this->fonts->file($owner, $brand, $font);
    }

    /**
     * Sustituye las URLs privadas de la marca por las del propio diseño compartido.
     *
     * @param  array<int, array<string, mixed>>  $assets
     * @param  array<int, array<string, mixed>>  $fonts
     * @param  array{asset: callable(int): string, font: callable(int): string}  $routes
     * @return array{assets: array<int, array<string, mixed>>, fonts: array<int, array<string, mixed>>}
     */
    public function rewrite(Design $design, string $role, array $assets, array $fonts, array $routes, bool $api = false): array
    {
        $referenced = StudioCollab::assetIds($design->document);
        $assets = collect($assets)->filter(fn (array $asset) => $role === 'editor' || in_array($asset['id'], $referenced, true))
            ->map(fn (array $asset) => array_replace($asset, ['preview_url' => $routes['asset']($asset['id'])] + ($api ? ['file_url' => $routes['asset']($asset['id'])] : [])))->values()->all();
        $fonts = collect($fonts)->map(fn (array $font) => array_replace($font, ['file_url' => $routes['font']($font['id'])]))->values()->all();

        return ['assets' => $assets, 'fonts' => $fonts];
    }

    private function brand(User $owner, Design $design): Brand
    {
        return Brand::withoutUserScope()->where('user_id', $owner->id)->findOrFail($design->brand_id);
    }
}
