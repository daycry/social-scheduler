<?php

namespace App\Http\Controllers;

use App\Analytics\Period;
use App\Models\Brand;
use App\Models\Report;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\Reports\TooManyActiveReports;
use App\Services\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informes (T-40): elegir marca y rango, generar PDF/CSV en la cola `reports`, ver el estado y descargar con enlace
 * firmado y caducable. En la web solo ve y gestiona informes propios (el UserScope hace que uno ajeno responda 404).
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): Response
    {
        $brands = Brand::query()->orderBy('name')->get(['id', 'name']);
        $names = $brands->pluck('name', 'id');
        $period = Period::make(null, null)->toArray();

        // Un pending/processing atascado se muestra como fallido (lo marca de verdad `reports:prune`).
        $items = Report::query()->latest('id')->limit(20)->get()->map(fn (Report $r) => [
            'id' => $r->id, 'brand' => $names[$r->brand_id] ?? '—', 'format' => $r->format,
            'status' => $r->isStuck() ? Report::STATUS_FAILED : $r->status,
            'from' => $r->from->format('Y-m-d'), 'to' => $r->to->format('Y-m-d'), 'compare' => $r->compare,
            'size' => $r->size, 'error' => $r->isStuck() ? ReportService::STUCK_ERROR : $r->error,
            'created_at' => $r->created_at?->toIso8601String(),
            // Enlace estable: al pulsarlo se genera la URL firmada en ese momento (no caduca en pantalla).
            'download_url' => $r->isReady() ? route('reports.link', $r) : null,
            'deletable' => ! $r->isActive(),
        ]);

        return Inertia::render('Reports/Index', [
            'brands' => $brands->map(fn (Brand $b) => ['id' => $b->id, 'name' => $b->name])->all(),
            'reports' => $items->all(),
            'defaults' => ['from' => $period['from'], 'to' => $period['to'], 'compare' => 'none'],
            'flash' => ['success' => session('success')],
            'polling' => $items->contains(fn ($r) => in_array($r['status'], [Report::STATUS_PENDING, Report::STATUS_PROCESSING], true)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'compare' => ['nullable', 'in:'.implode(',', Period::COMPARE_MODES)],
            'formats' => ['required', 'array', 'min:1'],
            'formats.*' => ['in:'.implode(',', Report::FORMATS)],
        ]);

        // El UserScope limita a las marcas del usuario: una ajena no existe para el.
        $brand = Brand::query()->findOrFail($data['brand_id']);
        $compare = $data['compare'] ?? 'none';

        // Rango y comparacion se validan aqui (error de formulario), no se dejan para que el informe falle en la cola.
        try {
            $period = Period::make($data['from'], $data['to']);
            $period->comparison($compare);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['from' => $e->getMessage()]);
        }

        try {
            $this->reports->enqueue($request->user(), $brand, $data['formats'], $period, $compare);
        } catch (TooManyActiveReports $e) {
            return back()->withErrors(['formats' => $e->getMessage()]);
        }

        return to_route('reports.index')->with('success', 'Informe en cola. Se generara en unos instantes.');
    }

    /** Genera un enlace firmado nuevo (5 min) y redirige a el: el boton Descargar nunca tiene un enlace caducado. */
    public function link(Report $report): RedirectResponse
    {
        Gate::authorize('view', $report);
        abort_unless($report->isReady(), 404);

        return redirect()->to($report->signedDownloadUrl());
    }

    public function destroy(Report $report): RedirectResponse
    {
        Gate::authorize('delete', $report);

        abort_if($report->isActive(), 409, 'El informe aun se esta generando.');

        $report->delete(); // el modelo borra tambien el fichero

        return to_route('reports.index')->with('success', 'Informe eliminado.');
    }

    /**
     * Descarga SOLO con firma (sin sesion, para web y agentes MCP). La firma cubre el id y el dueno; aqui se comprueba que el
     * informe es de ese dueno y esta `ready`, y la descarga queda auditada.
     */
    public function download(int $report, int $owner): StreamedResponse
    {
        $model = Report::withoutUserScope()->find($report);
        $user = User::find($owner);

        abort_unless($model && $user && $model->user_id === $user->id && $model->isReady(), 404);

        $disk = Storage::disk(config('filesystems.media'));
        abort_unless($disk->exists($model->path), 404);

        app(AuditLog::class)->record($user, 'report_download', AuditLog::OK, 'report', $model->id, $model->format, 'signed-url');

        $brand = Brand::withoutUserScope()->find($model->brand_id);
        $name = $model->downloadName($brand?->name ?? 'marca');
        $mime = $model->format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8';

        return $disk->download($model->path, $name, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff']);
    }
}
