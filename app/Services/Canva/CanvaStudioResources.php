<?php

namespace App\Services\Canva;

use App\Models\Brand;
use App\Models\Canva\CanvaJob;
use App\Models\MediaAsset;
use App\Models\Studio\StudioAsset;

/** Vincula una salida validada de Canva a los Recursos de la marca de origen. */
class CanvaStudioResources
{
    public function attach(MediaAsset $asset, string $digest): void
    {
        if (! $asset->isReady() || ! in_array($asset->kind, [MediaAsset::KIND_IMAGE, MediaAsset::KIND_VIDEO], true)) {
            return;
        }

        $job = CanvaJob::ownedBy($asset->user_id)->where('kind', CanvaJob::KIND_EXPORT)
            ->find($asset->origin['job_id'] ?? null);
        if ($job === null || ! Brand::ownedBy($asset->user_id)->whereKey($job->brand_id)->exists()) {
            return;
        }

        $page = array_search($asset->id, array_map('intval', $job->output_media_ids ?? []), true);
        if ($page === false) {
            return;
        }

        // El orden conserva páginas distintas aunque sus bytes coincidan.
        $key = hash('sha256', "{$job->design_id}:{$page}:{$digest}");
        $now = now();
        // Si coincide, conserva el archivo original y las fechas del recurso.
        StudioAsset::query()->toBase()->upsert([[
            'brand_id' => $job->brand_id,
            'canva_content_key' => $key,
            'user_id' => $asset->user_id,
            'media_asset_id' => $asset->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['brand_id', 'canva_content_key'], ['canva_content_key']);
    }
}
