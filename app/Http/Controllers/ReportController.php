<?php

namespace App\Http\Controllers;

use App\Analytics\Period;
use App\Jobs\GenerateReport;
use App\Models\Brand;
use App\Models\Report;
use App\Services\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informes (T-40): elegir marca y rango, generar PDF/CSV en la cola `reports`, ver el estado y descargar con enlace
 * firmado y caducable. Solo ve y descarga informes propios (el UserScope hace que uno ajeno responda 404).
 */
class ReportController extends Controller
{
    private const MAX_ACTIVE = 5;

    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): Response
    {
        $brands = Brand::query()->orderBy('name')->get(['id', 'name']);
        $names = $brands->pluck('name', 'id');
        $period = Period::make(null, null)->toArray();

        $items = Report::query()->latest('id')->limit(20)->get()->map(fn (Report $r) => [
            'id' => $r->id, 'brand' => $names[$r->brand_id] ?? '—', 'format' => $r->format, 'status' => $r->status,
            'from' => $r->from->format('Y-m-d'), 'to' => $r->to->format('Y-m-d'), 'compare' => $r->compare,
            'size' => $r->size, 'error' => $r->error, 'created_at' => $r->created_at?->toIso8601String(),
            'download_url' => $r->isReady() ? $this->downloadUrl($r) : null,
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

        try {
            $period = Period::make($data['from'], $data['to']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['from' => $e->getMessage()]);
        }

        $active = Report::query()->whereIn('status', [Report::STATUS_PENDING, Report::STATUS_PROCESSING])->count();
        $formats = array_values(array_unique($data['formats']));

        if ($active + count($formats) > self::MAX_ACTIVE) {
            return back()->withErrors(['formats' => 'Ya hay varios informes en curso. Espera a que terminen.']);
        }

        foreach ($formats as $format) {
            $report = $this->reports->create($request->user(), $brand, $format, $period, $data['compare'] ?? 'none');
            GenerateReport::dispatch($report->id);
        }

        return to_route('reports.index')->with('success', 'Informe en cola. Se generara en unos instantes.');
    }

    public function download(Report $report): StreamedResponse
    {
        Gate::authorize('view', $report);
        abort_unless($report->isReady(), 404);

        $disk = Storage::disk(config('filesystems.media'));
        abort_unless($disk->exists($report->path), 404);

        $brand = Brand::query()->find($report->brand_id);
        $name = $report->downloadName($brand?->name ?? 'marca');
        $mime = $report->format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8';

        return $disk->download($report->path, $name, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function downloadUrl(Report $report): string
    {
        return URL::temporarySignedRoute('reports.download', now()->addMinutes((int) config('reports.download_minutes', 15)), $report);
    }
}
