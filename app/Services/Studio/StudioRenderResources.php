<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/** Prepara los recursos privados para un render de páginas o miniatura del Estudio. */
final class StudioRenderResources
{
    public function __construct(
        private readonly StudioAssets $assets,
        private readonly StudioFonts $fonts,
        private readonly StudioFrames $frames,
        private readonly StudioChartFiles $charts,
    ) {}

    /**
     * @param  list<string>  $pageIds
     * @param  array<string, float|int>  $times
     * @return array{assets: list<array<string, mixed>>, fonts: list<array<string, mixed>>, chartFiles: array}
     */
    public function prepare(User $user, Brand $brand, array $document, array $pageIds, array $times, string $directory): array
    {
        [$sampledPages, $assetIds, $fontIds] = $this->sampleReferences($document, $pageIds, $times);
        $chartFiles = $this->charts->materialize($user, $brand, ['pages' => $sampledPages], $directory);
        $bytes = 0;
        $assets = $this->inlineAssets($user, $brand, $assetIds, $bytes);
        $fonts = $this->inlineFonts($user, $brand, $fontIds, $bytes);

        return ['assets' => $assets, 'fonts' => $fonts, 'chartFiles' => $chartFiles];
    }

    /**
     * Elementos de las paginas pedidas (muestreados en su instante si el documento es animado) y los ids de recursos y fuentes que citan.
     *
     * @return array{0: list<array{elements: array<int, mixed>}>, 1: array<int, true>, 2: array<int, true>} paginas muestreadas, ids de recursos e ids de fuentes
     */
    private function sampleReferences(array $document, array $pageIds, array $times): array
    {
        $assetIds = [];
        $fontIds = [];
        if (! empty($document['timeline']['captions']['cues']) && isset($document['timeline']['captions']['style']['font_id'])) {
            $fontIds[(int) $document['timeline']['captions']['style']['font_id']] = true;
        }
        $walk = function (array $elements) use (&$walk, &$assetIds, &$fontIds): void {
            foreach ($elements as $element) {
                if ($element['type'] === 'text' && isset($element['font_id'])) {
                    $fontIds[(int) $element['font_id']] = true;
                }
                if ($element['type'] === 'chart' && isset($element['chart']['style']['font_id'])) {
                    $fontIds[(int) $element['chart']['style']['font_id']] = true;
                }
                if (isset($element['asset_id'])) {
                    $assetIds[(int) $element['asset_id']] = true;
                }
                if ($element['type'] === 'group') {
                    $walk($element['elements']);
                }
            }
        };
        $sampledPages = [];
        foreach ($document['pages'] as $page) {
            if (in_array($page['id'], $pageIds, true)) {
                $elements = ($document['schema_version'] ?? 1) === 2
                    ? $this->frames->elements($page['elements'], $times[$page['id']] ?? 0, $page['duration'])
                    : $page['elements'];
                $walk($elements);
                $sampledPages[] = ['elements' => $elements];
            }
        }

        return [$sampledPages, $assetIds, $fontIds];
    }

    /**
     * Recursos del diseno incrustados como `data:` para el renderizador; `$bytes` acumula el tope de 64 MB compartido con las fuentes.
     *
     * @param  array<int, true>  $assetIds
     * @return list<array<string, mixed>>
     */
    private function inlineAssets(User $user, Brand $brand, array $assetIds, int &$bytes): array
    {
        $assets = [];
        foreach ($this->assets->many($user, $brand, array_keys($assetIds)) as $asset) {
            $source = Storage::disk($asset->disk);
            $size = $source->size($asset->path);
            $bytes += $size;
            if ($bytes > 64000000) {
                throw new StudioRenderFailure('Los recursos del trabajo superan 64 MB. Reduce su tamaño.');
            }
            $content = $source->get($asset->path);
            if (! is_string($content) || strlen($content) !== $size) {
                throw new StudioRenderFailure('No se pudo leer un recurso del diseño.');
            }
            $assets[] = $asset->only(['id', 'kind', 'mime', 'width', 'height', 'duration']) + ['preview_url' => 'data:'.$asset->mime.';base64,'.base64_encode($content)];
        }

        return $assets;
    }

    /**
     * Fuentes privadas del diseno verificadas (tamano, firma WOFF2 y hash) e incrustadas; comparten el tope de bytes con los recursos.
     *
     * @param  array<int, true>  $fontIds
     * @return list<array<string, mixed>>
     */
    private function inlineFonts(User $user, Brand $brand, array $fontIds, int &$bytes): array
    {
        $fonts = [];
        foreach ($this->fonts->many($user, $brand, array_keys($fontIds)) as $font) {
            $source = Storage::disk($font->disk);
            $size = $source->size($font->path);
            $bytes += $size;
            if ($size !== $font->size || $size > 8 * 1024 * 1024 || $bytes > 64000000) {
                throw new StudioRenderFailure('Las fuentes o los recursos del trabajo superan su tamaño permitido.');
            }
            $content = $source->get($font->path);
            if (! is_string($content) || strlen($content) !== $size || substr($content, 0, 4) !== 'wOF2' || ! hash_equals($font->sha256, hash('sha256', $content))) {
                throw new StudioRenderFailure('No se pudo verificar una fuente privada del diseño.');
            }
            $metadata = $this->fonts->present($font);
            $metadata['file_url'] = 'data:font/woff2;base64,'.base64_encode($content);
            $fonts[] = $metadata;
        }

        return $fonts;
    }
}
