<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Informes programados (T-21): PDF semanal o mensual por email. Aislados por usuario; la plantilla debe ser de la misma marca. */
class ReportScheduleController extends Controller
{
    public const MAX_PER_USER = 10;

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'report_template_id' => ['required', 'integer'],
            'frequency' => ['required', 'in:'.implode(',', ReportSchedule::FREQUENCIES)],
            'recipients' => ['required', 'array', 'min:1', 'max:'.(int) config('reports.max_recipients', 5)],
            'recipients.*' => ['required', 'string', 'max:190', 'email:rfc,strict'],
        ]);
        $brand = Brand::query()->findOrFail($data['brand_id']);
        $template = ReportTemplate::query()->where('brand_id', $brand->id)->findOrFail($data['report_template_id']); // plantilla ajena o de otra marca = 404

        if (ReportSchedule::query()->count() >= self::MAX_PER_USER) {
            return back()->withErrors(['recipients' => 'Has llegado al maximo de '.self::MAX_PER_USER.' programaciones.']);
        }

        ReportSchedule::create([
            'user_id' => $request->user()->id, 'brand_id' => $brand->id, 'report_template_id' => $template->id,
            'frequency' => $data['frequency'], 'recipients' => $this->recipients($data['recipients']), 'active' => true,
            'next_run_at' => ReportSchedule::nextRun($data['frequency'], $brand->timezone),
        ]);

        return to_route('reports.index', ['brand_id' => $brand->id])->with('success', 'Informe programado.');
    }

    public function update(Request $request, ReportSchedule $schedule): RedirectResponse
    {
        $data = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'frequency' => ['sometimes', 'in:'.implode(',', ReportSchedule::FREQUENCIES)],
            'recipients' => ['sometimes', 'array', 'min:1', 'max:'.(int) config('reports.max_recipients', 5)],
            'recipients.*' => ['required', 'string', 'max:190', 'email:rfc,strict'],
        ]);
        $brand = Brand::query()->find($schedule->brand_id);
        $changes = [];

        if (array_key_exists('recipients', $data)) {
            $changes['recipients'] = $this->recipients($data['recipients']);
        }

        if (array_key_exists('frequency', $data) && $data['frequency'] !== $schedule->frequency) {
            $changes['frequency'] = $data['frequency'];
            $changes['next_run_at'] = ReportSchedule::nextRun($data['frequency'], $brand?->timezone);
        }

        if (array_key_exists('active', $data)) {
            $changes['active'] = (bool) $data['active'];

            if ($changes['active'] && ! $schedule->active) {
                $changes['next_run_at'] = ReportSchedule::nextRun($changes['frequency'] ?? $schedule->frequency, $brand?->timezone); // no envia lo atrasado al reactivar
                $changes['last_error'] = null;
            }
        }

        $schedule->update($changes);

        return to_route('reports.index', ['brand_id' => $schedule->brand_id])->with('success', 'Programacion actualizada.');
    }

    public function destroy(ReportSchedule $schedule): RedirectResponse
    {
        $brandId = $schedule->brand_id;
        $schedule->delete();

        return to_route('reports.index', ['brand_id' => $brandId])->with('success', 'Programacion eliminada.');
    }

    /**
     * @param  list<string>  $emails
     * @return list<string>
     */
    private function recipients(array $emails): array
    {
        return array_values(array_unique(array_map(fn (string $e) => mb_strtolower(trim($e)), $emails)));
    }
}
