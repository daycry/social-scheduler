<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Exporta una revisión de un diseño a varios formatos de una vez: una exportación de imagen por formato, cada una con el documento
 * ajustado al lienzo pedido, que se renderiza con el mismo pipeline y la misma cola que cualquier exportación y deja su fichero en la biblioteca.
 *
 * Es idempotente: cada exportación lleva una huella (diseño, revisión, páginas, formato, modo, tamaño y marca de agua). Repetir el lote
 * reutiliza las que están en curso o listas con su fichero en la biblioteca y solo vuelve a crear las que fallaron o cuyo fichero se borró.
 */
final class StudioBatchExport
{
    public function __construct(
        private readonly StudioService $studio,
        private readonly StudioRenderer $renderer,
        private readonly StudioExports $exports,
        private readonly StudioWatermark $watermarks,
    ) {}

    /** @return array{exports: list<array<string, mixed>>, created: int, reused: int} en el orden de los formatos pedidos */
    public function start(User $user, Design $design, array $data): array
    {
        $design = $this->studio->owned($user, $design);
        $max = (int) config('studio.batch.max_formats', 8);
        $data = Validator::make($data, [
            'revision' => ['required', 'integer', 'min:1'],
            'pages' => ['required', 'array', 'min:1', 'max:20'], 'pages.*' => ['required', 'string', 'max:64', 'distinct'],
            'format' => ['sometimes', Rule::in(StudioWatermark::FORMATS)],
            'mode' => ['sometimes', Rule::in(['canvas', 'contain', 'cover'])],
            'quality' => ['sometimes', 'numeric', 'min:0.1', 'max:1'],
            'watermark' => ['sometimes', 'nullable', 'boolean'],
            'formats' => ['required', 'array', 'min:1', 'max:'.$max], 'formats.*' => ['required', 'array:width,height'],
            'formats.*.width' => ['required', 'integer', 'min:40', 'max:8000'], 'formats.*.height' => ['required', 'integer', 'min:40', 'max:8000'],
        ])->validate();
        $sizes = array_map(fn (array $format) => (int) $format['width'].'x'.(int) $format['height'], $data['formats']);
        if (! array_is_list($data['formats']) || count(array_unique($sizes)) !== count($sizes)) {
            throw ValidationException::withMessages(['formats' => ['Cada formato debe tener un tamaño distinto.']]);
        }
        $format = $data['format'] ?? 'png';
        $mode = $data['mode'] ?? 'contain';
        $brand = Brand::ownedBy($user->id)->findOrFail($design->brand_id);
        $watermark = $this->watermarks->snapshot($this->studio->kit($user, $brand)['watermark'], $format, $data['watermark'] ?? null);

        return DB::transaction(function () use ($user, $design, $data, $format, $mode, $watermark): array {
            // Serializa los lotes del usuario: dos envíos simultáneos no crean exportaciones duplicadas ni rebasan el tope de exportaciones activas.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $plan = [];
            foreach ($data['formats'] as $index => $size) {
                $fit = ['width' => (int) $size['width'], 'height' => (int) $size['height'], 'mode' => $mode];
                $fingerprint = hash('sha256', json_encode([$design->id, (int) $data['revision'], $data['pages'], $format, $fit, (float) ($data['quality'] ?? 0.94), $watermark], JSON_THROW_ON_ERROR));
                $plan[$index] = ['fit' => $fit, 'fingerprint' => $fingerprint, 'export' => $this->reusable($user, $design, $fingerprint)];
            }
            $missing = count(array_filter($plan, fn (array $item) => $item['export'] === null));
            $active = DesignExport::ownedBy($user->id)->whereIn('status', ['pending', 'processing'])->count();
            if ($missing > 0 && $active + $missing > (int) config('studio.batch.max_active', 24)) {
                throw ValidationException::withMessages(['formats' => ['Hay demasiadas exportaciones en curso. Espera a que terminen antes de exportar más formatos.']]);
            }
            $request = ['revision' => (int) $data['revision'], 'pages' => $data['pages'], 'format' => $format, 'watermark' => $watermark !== null] + Arr::only($data, ['quality']);
            $ids = [];
            foreach ($plan as $item) {
                $ids[] = ($item['export'] ?? $this->renderer->export($user, $design, $request, ['fit' => $item['fit'], 'batch' => $item['fingerprint']]))->id;
            }

            $rows = DesignExport::ownedBy($user->id)->select(['id', 'user_id', 'brand_id', 'design_id', 'revision', 'format', 'pages', 'media_ids', 'options', 'status', 'error', 'disk', 'path', 'render_progress', 'created_at', 'updated_at'])
                ->whereIn('id', $ids)->get()->keyBy('id');

            return ['exports' => $this->exports->presentMany($user, collect($ids)->map(fn (int $id) => $rows[$id])), 'created' => $missing, 'reused' => count($plan) - $missing];
        });
    }

    /**
     * La exportación de esta huella que sigue valiendo (en curso, o lista con todos sus ficheros en la biblioteca), o null.
     * Una fallida, cancelada u obsoleta se desvincula del lote para que no vuelva a coincidir.
     */
    private function reusable(User $user, Design $design, string $fingerprint): ?DesignExport
    {
        $candidates = DesignExport::ownedBy($user->id)->where('design_id', $design->id)->where('options->batch', $fingerprint)->orderByDesc('id')->get();
        $reusable = null;
        foreach ($candidates as $candidate) {
            if ($reusable === null && $this->valid($user, $candidate)) {
                $reusable = $candidate;

                continue;
            }
            $candidate->forceFill(['options' => Arr::except($candidate->options, ['batch'])])->save();
        }

        return $reusable;
    }

    private function valid(User $user, DesignExport $export): bool
    {
        if (in_array($export->status, ['pending', 'processing'], true)) {
            return true;
        }
        $ids = $export->media_ids ?? [];

        return $export->status === 'ready' && $ids !== [] && MediaAsset::ownedBy($user->id)->whereIn('id', $ids)->count() === count($ids);
    }
}
