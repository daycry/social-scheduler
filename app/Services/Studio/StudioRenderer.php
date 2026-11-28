<?php

namespace App\Services\Studio;

use App\Jobs\Studio\RenderStudioDesign;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\User;
use App\Services\JobFailureNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudioRenderer
{
    public function __construct(
        private readonly StudioService $studio,
        private readonly StudioExports $exports,
        private readonly StudioAssets $assets,
        private readonly StudioFrames $frames,
        private readonly StudioAvLifecycle $av,
        private readonly StudioAvQueue $avQueue,
        private readonly StudioExportFiles $files,
        private readonly StudioRenderResources $resources,
        private readonly JobFailureNotifier $notifier,
        private readonly StudioWatermark $watermarks,
    ) {}

    /**
     * Crea la exportación y encola su render.
     *
     * @param  array<string, mixed>  $internal  opciones que fija el servidor y no la petición: `fit` ({width, height, mode}: redimensiona el
     *                                          documento al renderizarlo) y `batch` (huella que identifica la exportación dentro de un lote)
     */
    public function export(User $user, Design $design, array $data, array $internal = []): DesignExport
    {
        $design = $this->studio->owned($user, $design);
        $data = Validator::make($data, [
            'revision' => ['required', 'integer', 'min:1'],
            'format' => ['required', Rule::in(['png', 'jpeg', 'webp', 'svg', 'pdf', 'pptx', 'mp4', 'gif'])],
            'pages' => ['required', 'array', 'min:1', 'max:100'], 'pages.*' => ['required', 'string', 'max:64', 'distinct'],
            'frame_times' => ['sometimes', 'array', 'max:100'],
            'scale' => ['sometimes', 'numeric', 'min:0.1', 'max:4'], 'quality' => ['sometimes', 'numeric', 'min:0.1', 'max:1'], 'transparent' => ['sometimes', 'boolean'],
            'watermark' => ['sometimes', 'nullable', 'boolean'],
        ])->validate();
        $native = in_array($data['format'], ['mp4', 'gif'], true);
        if ($native && ($data['frame_times'] ?? []) !== []) {
            throw ValidationException::withMessages(['frame_times' => ['MP4 y GIF exportan la secuencia completa; no admiten fotogramas estáticos.']]);
        }
        if ($native && ($data['transparent'] ?? false)) {
            throw ValidationException::withMessages(['transparent' => ['La exportación audiovisual no admite transparencia.']]);
        }
        $revision = $this->exports->revision($user, $design, (int) $data['revision']);
        if (! array_is_list($data['pages']) || array_diff($data['pages'], array_column($revision->document['pages'], 'id')) !== []) {
            throw ValidationException::withMessages(['pages' => ['Selecciona páginas existentes en la revisión indicada.']]);
        }
        $frameTimes = $this->frames->validate($revision->document, $data['pages'], $data['frame_times'] ?? []);
        $frameOptions = array_key_exists('frame_times', $data) ? ['frame_times' => $frameTimes] : [];
        $scale = (float) ($data['scale'] ?? 1);
        $fit = $internal['fit'] ?? null;
        $width = (int) round(($fit['width'] ?? $revision->document['width']) * $scale);
        $height = (int) round(($fit['height'] ?? $revision->document['height']) * $scale);
        $watermark = $this->watermarks->snapshot($this->studio->kit($user, Brand::ownedBy($user->id)->findOrFail($design->brand_id))['watermark'], $data['format'], $data['watermark'] ?? null);
        if ($watermark !== null && $width * $height > (int) config('studio.watermark.max_pixels', 25_000_000)) {
            throw ValidationException::withMessages(['watermark' => ['La imagen es demasiado grande para aplicar la marca de agua. Reduce la escala o desactívala.']]);
        }
        if ($native && ((int) ceil($width / 2) * 2 * (int) ceil($height / 2) * 2 > 16000000 || $width > 16000 || $height > 16000)) {
            throw ValidationException::withMessages(['scale' => ['El fotograma audiovisual admite hasta 16 megapíxeles y 16000 píxeles por lado.']]);
        }
        if (! $native && ($width > 16000 || $height > 16000 || $width * $height * count($data['pages']) > 64000000)) {
            throw ValidationException::withMessages(['scale' => ['El trabajo completo admite hasta 64 megapíxeles y 16000 píxeles por lado. Exporta menos páginas o reduce la escala.']]);
        }

        $extra = array_filter(['fit' => $fit, 'batch' => $internal['batch'] ?? null, 'watermark' => $watermark]);

        return DB::transaction(function () use ($user, $design, $data, $frameOptions, $native, $extra): DesignExport {
            $export = DesignExport::create(['user_id' => $user->id, 'brand_id' => $design->brand_id, 'design_id' => $design->id,
                'revision' => $data['revision'], 'format' => $data['format'], 'pages' => $data['pages'], 'status' => 'pending',
                'options' => ['scale' => (float) ($data['scale'] ?? 1), 'quality' => (float) ($data['quality'] ?? 0.94), 'transparent' => (bool) ($data['transparent'] ?? false)] + $frameOptions + $extra]);
            // Con la fila ya insertada la fusion y la poda ven la revision como fijada; si desaparecio entre la validacion y el insert, se revierte con el 409.
            $this->exports->revision($user, $design, (int) $data['revision']);
            if ($native) {
                $this->av->initialize($user, $export);
                DB::afterCommit(fn () => $this->avQueue->enqueue($export->id));
            } else {
                RenderStudioDesign::dispatch($export->id)->afterCommit();
            }

            return $export->refresh();
        });
    }

    public function render(int $exportId): void
    {
        // Claim once: a duplicate queue delivery never renders or creates media twice.
        if (DesignExport::withoutUserScope()->whereKey($exportId)->where('status', 'pending')->update(['status' => 'processing']) !== 1) {
            return;
        }
        $export = DesignExport::withoutUserScope()->findOrFail($exportId);
        $directory = 'studio-exports/'.$export->user_id.'/'.$export->id.'/'.Str::uuid();
        $createdMedia = [];
        try {
            $this->produce($export, $directory, $createdMedia);
        } catch (\Throwable $e) {
            $this->abandon($export, $e, $createdMedia, $directory);
        }
    }

    /**
     * Fases del render de una exportacion ya reclamada: carga, renderizado, ficheros y media, paquete descargable y cierre.
     *
     * @param  list<MediaAsset>  $createdMedia  se rellena segun se crean, para que `abandon()` pueda limpiarlos si algo falla despues
     */
    private function produce(DesignExport $export, string $directory, array &$createdMedia): void
    {
        $user = User::findOrFail($export->user_id);
        $design = Design::ownedBy($user->id)->findOrFail($export->design_id);
        $revision = $this->exports->revision($user, $design, $export->revision);
        // Una exportación de lote renderiza el documento ya ajustado al formato pedido; el resto, la revisión tal cual.
        $document = isset($export->options['fit']) ? StudioAutomation::resize($revision->document, (int) $export->options['fit']['width'], (int) $export->options['fit']['height'], (string) $export->options['fit']['mode']) : $revision->document;
        $times = array_column($this->frames->validate($document, $export->pages, $export->options['frame_times'] ?? []), 'time', 'page_id');
        $brand = Brand::ownedBy($user->id)->findOrFail($export->brand_id);
        // PPTX: Chromium renderiza cada pagina como PNG y `StudioPptxExporter` las empaqueta (sin crear media en la biblioteca).
        $manifest = $this->execute($user, $brand, $document, $export->pages, $times, ($export->format === 'pptx' || isset($export->options['watermark'])) ? 'png' : $export->format, array_intersect_key($export->options, array_flip(['scale', 'quality', 'transparent', 'frame_times'])), $directory);
        $files = $manifest['files'] ?? [];
        if (count($files) !== ($export->format === 'pdf' ? 1 : count($export->pages))) {
            throw new StudioRenderFailure('El renderizador devolvió un número de archivos incorrecto.');
        }

        [$paths, $mediaIds] = $this->files->storeFiles($export, $user, $revision->name, $document, $files, $directory, $createdMedia);
        $download = $this->files->packageDownload($export, $revision, $paths, $directory);

        DB::transaction(function () use ($user, $brand, $mediaIds, $export, $download): void {
            foreach ($mediaIds as $id) {
                $this->assets->attach($user, $brand, $id);
            }
            $export->forceFill(['status' => 'ready', 'disk' => 'local', 'path' => $download, 'media_ids' => $mediaIds, 'error' => null])->save();
            DB::afterCommit(fn () => $this->notifier->exportDone($export));
        });
    }

    /**
     * El render fallo: la exportacion queda `failed` (con el motivo si es de dominio), se avisa y se limpia lo creado en este intento.
     *
     * @param  list<MediaAsset>  $createdMedia
     */
    private function abandon(DesignExport $export, \Throwable $e, array $createdMedia, string $directory): void
    {
        $export->forceFill(['status' => 'failed', 'error' => $e instanceof StudioRenderFailure ? Str::limit($e->getMessage(), 350) : 'No se pudo renderizar esta revisión. Revisa sus recursos y la configuración del renderizador.'])->save();
        $this->notifier->export($export);
        $this->files->discard($createdMedia, $directory);
    }

    /**
     * Renderiza UNA pagina (la del instante 0) como JPEG reducido a `$maxSide` px en el lado mayor, dentro de `$directory` del disco `local`.
     * No crea media ni exportaciones: es la miniatura del listado. Devuelve la ruta relativa del fichero.
     */
    public function previewPage(User $user, Brand $brand, array $document, string $pageId, int $maxSide, string $directory): string
    {
        $times = array_column($this->frames->validate($document, [$pageId], []), 'time', 'page_id');
        $scale = max(0.1, round(min(1, $maxSide / max((int) $document['width'], (int) $document['height'])), 4));
        Storage::disk('local')->makeDirectory($directory);
        $manifest = $this->execute($user, $brand, $document, [$pageId], $times, 'jpeg', ['scale' => $scale, 'quality' => 0.82, 'transparent' => false], $directory);
        $path = $directory.'/page-001.jpg';
        if (count($manifest['files'] ?? []) !== 1 || ! Storage::disk('local')->exists($path) || Storage::disk('local')->size($path) < 1 || Storage::disk('local')->size($path) > 2_000_000) {
            throw new StudioRenderFailure('El renderizador no devolvió una miniatura válida.');
        }

        return $path;
    }

    /**
     * Ejecuta Chromium sobre las paginas elegidas de un documento y deja los ficheros en `$directory` (disco `local`). Lo comparten las
     * exportaciones y las miniaturas. Devuelve el manifiesto de `render.mjs`.
     *
     * @param  list<string>  $pageIds
     * @param  array<string, float|int>  $times  segundo local de cada pagina (documento v2)
     * @param  array<string, mixed>  $options  scale, quality, transparent, frame_times
     * @return array<string, mixed>
     */
    private function execute(User $user, Brand $brand, array $document, array $pageIds, array $times, string $format, array $options, string $directory): array
    {
        $disk = Storage::disk('local');
        ['assets' => $assets, 'fonts' => $fonts, 'chartFiles' => $chartFiles] = $this->resources->prepare($user, $brand, $document, $pageIds, $times, $directory);
        $disk->makeDirectory($directory);
        $input = ['document' => $document, 'assets' => $assets, 'fonts' => $fonts, 'brandId' => (int) $brand->id, 'chartFiles' => $chartFiles,
            'format' => $format, 'pageIds' => $pageIds] + $options;
        $result = Process::path(base_path())->timeout(100)->input(json_encode($input, JSON_THROW_ON_ERROR))->run([
            config('studio.node', 'node'), base_path('scripts/studio/render.mjs'), $disk->path($directory),
        ]);
        if (! $result->successful()) {
            throw new StudioRenderFailure('El renderizador no pudo completar el archivo. Comprueba Node, Chromium y npm run build en el servidor.');
        }

        return json_decode($result->output(), true, 32, JSON_THROW_ON_ERROR);
    }
}
