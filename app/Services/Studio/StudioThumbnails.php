<?php

namespace App\Services\Studio;

use App\Jobs\Studio\RenderStudioThumbnail;
use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Miniaturas del listado del estudio (T-35). La miniatura es la primera pagina del diseno reducida a `MAX_SIDE` px, en JPEG, guardada en el
 * disco `local` y cacheada por revision: `studio_designs.thumbnail_revision` dice que revision se renderizo y, si no coincide con la actual
 * (el diseno se edito), esta caducada. Editar o autoguardar NO renderiza nada (no bloquea el guardado): el listado, al verla caducada, encola
 * `RenderStudioThumbnail` en la cola `media` (bajo demanda, unico por diseno) y muestra el hueco hasta que esta lista. Un render fallido aplaza
 * esa revision unos minutos para no insistir en cada visita. El render reutiliza el mismo Chromium de las exportaciones.
 */
class StudioThumbnails
{
    public const MAX_SIDE = 480;

    /** Disenos sin miniatura que un listado encola como mucho (el resto se encola en visitas siguientes). */
    private const QUEUE_PER_LISTING = 12;

    private const RETRY_AFTER_FAILURE_MINUTES = 10;

    public function __construct(private readonly StudioRenderer $renderer) {}

    public static function enabled(): bool
    {
        return (bool) config('studio.thumbnails.enabled', true);
    }

    /**
     * Anade `thumbnail_url` (null si no hay miniatura vigente) a cada fila del listado y encola las que faltan.
     *
     * @param  list<array<string, mixed>>  $rows  filas del listado (`id`, `revision`, `deleted_at`)
     * @return list<array<string, mixed>>
     */
    public function decorate(User $user, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $stored = DB::table('studio_designs')->where('user_id', $user->id)->whereIn('id', array_column($rows, 'id'))->get(['id', 'thumbnail_path', 'thumbnail_revision'])->keyBy('id');
        $queued = 0;
        $expires = now()->startOfHour()->addHours(2); // misma URL durante la hora: el navegador la reutiliza
        foreach ($rows as $i => $row) {
            $current = $stored->get($row['id']);
            $fresh = $current !== null && $current->thumbnail_path !== null && (int) $current->thumbnail_revision === (int) $row['revision'];
            $rows[$i]['thumbnail_url'] = $fresh
                ? URL::temporarySignedRoute('studio.designs.thumbnail', $expires, ['design' => $row['id'], 'owner' => $user->id, 'v' => $row['revision']]) : null;
            if (! $fresh && self::enabled() && ($row['deleted_at'] ?? null) === null && $queued < self::QUEUE_PER_LISTING && ! Cache::has($this->failedKey((int) $row['id'], (int) $row['revision']))) {
                RenderStudioThumbnail::dispatch((int) $row['id']);
                $queued++;
            }
        }

        return $rows;
    }

    /** Renderiza la miniatura de la revision actual del diseno (la que lee al empezar). No lanza: un fallo solo aplaza esa revision. */
    public function generate(int $designId): void
    {
        $design = Design::withoutUserScope()->find($designId);
        if ($design === null || ! self::enabled()) {
            return;
        }
        $revision = (int) $design->revision;
        if ($design->thumbnail_path !== null && (int) $design->thumbnail_revision === $revision || Cache::has($this->failedKey($designId, $revision))) {
            return;
        }
        $disk = Storage::disk('local');
        $directory = 'studio-thumbnails/'.$design->user_id;
        // Carpeta de trabajo con la forma que exige `StudioChartFiles` (`studio-exports/{usuario}/{n}/{uuid}`): aqui `{n}` es el diseno y la carpeta se borra al acabar.
        $work = 'studio-exports/'.$design->user_id.'/'.$design->id.'/'.Str::uuid();
        try {
            $brand = Brand::withoutUserScope()->where('user_id', $design->user_id)->findOrFail($design->brand_id);
            $user = User::findOrFail($design->user_id);
            $document = $design->document;
            $rendered = $this->renderer->previewPage($user, $brand, $document, (string) $document['pages'][0]['id'], self::MAX_SIDE, $work);
            $path = $directory.'/'.$design->id.'-r'.$revision.'.jpg';
            $disk->makeDirectory($directory);
            if (! $disk->move($rendered, $path)) {
                throw new StudioRenderFailure('No se pudo guardar la miniatura.');
            }
            $previous = $design->thumbnail_path;
            // Query builder: no toca `updated_at` (la fecha del listado es la de la ultima edicion) ni `revision`.
            DB::table('studio_designs')->where('id', $design->id)->update(['thumbnail_path' => $path, 'thumbnail_revision' => $revision]);
            if ($previous !== null && $previous !== $path) {
                $disk->delete($previous);
            }
        } catch (\Throwable $e) {
            Cache::put($this->failedKey($designId, $revision), true, now()->addMinutes(self::RETRY_AFTER_FAILURE_MINUTES));
            Log::warning('studio.thumbnail_failed', ['design' => $designId, 'error' => $e instanceof StudioRenderFailure ? $e->getMessage() : $e::class]);
        } finally {
            $disk->deleteDirectory($work);
        }
    }

    private function failedKey(int $designId, int $revision): string
    {
        return 'studio:thumbnail:failed:'.$designId.':'.$revision;
    }
}
