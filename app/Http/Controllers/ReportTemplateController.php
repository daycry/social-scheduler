<?php

namespace App\Http\Controllers;

use App\Analytics\Period;
use App\Models\Brand;
use App\Models\ReportTemplate;
use App\Services\Reports\ReportBlocks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Plantillas de informe por marca (T-21). Aisladas por usuario: una ajena responde 404 (UserScope). */
class ReportTemplateController extends Controller
{
    public const MAX_PER_USER = 50;

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $brand = Brand::query()->findOrFail($data['brand_id']); // marca ajena = 404

        if (ReportTemplate::query()->count() >= self::MAX_PER_USER) {
            return back()->withErrors(['name' => 'Has llegado al maximo de '.self::MAX_PER_USER.' plantillas.']);
        }

        if (ReportTemplate::query()->where('brand_id', $brand->id)->where('name', $data['name'])->exists()) {
            return back()->withErrors(['name' => 'Ya hay una plantilla con ese nombre en esta marca.']);
        }

        try {
            $blocks = ReportBlocks::normalize($data['blocks']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['blocks' => $e->getMessage()]);
        }

        ReportTemplate::create(['user_id' => $request->user()->id, 'brand_id' => $brand->id, 'name' => $data['name'], 'blocks' => $blocks, 'range_days' => $data['range_days'], 'compare' => $data['compare'] ?? 'none']);

        return to_route('reports.index', ['brand_id' => $brand->id])->with('success', 'Plantilla guardada.');
    }

    public function update(Request $request, ReportTemplate $template): RedirectResponse
    {
        $data = $this->validated($request, false);

        if (ReportTemplate::query()->where('brand_id', $template->brand_id)->where('name', $data['name'])->whereKeyNot($template->id)->exists()) {
            return back()->withErrors(['name' => 'Ya hay una plantilla con ese nombre en esta marca.']);
        }

        try {
            $blocks = ReportBlocks::normalize($data['blocks']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['blocks' => $e->getMessage()]);
        }

        $template->update(['name' => $data['name'], 'blocks' => $blocks, 'range_days' => $data['range_days'], 'compare' => $data['compare'] ?? 'none']);

        return to_route('reports.index', ['brand_id' => $template->brand_id])->with('success', 'Plantilla actualizada.');
    }

    public function destroy(ReportTemplate $template): RedirectResponse
    {
        $brandId = $template->brand_id;
        $template->delete(); // sus programaciones se borran en cascada

        return to_route('reports.index', ['brand_id' => $brandId])->with('success', 'Plantilla eliminada.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $withBrand): array
    {
        return $request->validate([
            'brand_id' => $withBrand ? ['required', 'integer'] : ['prohibited'],
            'name' => ['required', 'string', 'max:80'],
            'blocks' => ['required', 'array'],
            'range_days' => ['required', 'integer', 'min:1', 'max:'.Period::MAX_DAYS],
            'compare' => ['nullable', 'in:'.implode(',', Period::COMPARE_MODES)],
        ]);
    }
}
