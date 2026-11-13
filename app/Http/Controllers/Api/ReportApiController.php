<?php

namespace App\Http\Controllers\Api;

use App\Analytics\Period;
use App\Models\Brand;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Services\Reports\ReportBlocks;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Informes de la API v1 (los mismos servicios que `generate_report` y `get_report` del MCP): se encolan y se consultan por id. */
class ReportApiController extends ApiController
{
    public function __construct(private readonly ReportService $reports) {}

    public function store(Request $request, int $brand): JsonResponse
    {
        $brand = $this->ownedBrand($request, $brand);
        $data = $request->validate([
            'format' => ['required', Rule::in(Report::FORMATS)],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'compare' => ['nullable', Rule::in(Period::COMPARE_MODES)],
            'template_id' => ['nullable', 'integer'],
            'blocks' => ['nullable', 'array', 'max:'.ReportBlocks::MAX_BLOCKS],
        ]);
        $template = isset($data['template_id']) ? (ReportTemplate::withoutUserScope()->where('user_id', $request->user()->id)->find($data['template_id']) ?? abort(404)) : null;

        return $this->respond($request, 'generate_report', 'report', null, fn () => $this->reports->request($request->user(), $brand, $data, $template), 202);
    }

    public function show(Request $request, int $report): JsonResponse
    {
        $found = Report::withoutUserScope()->where('user_id', $request->user()->id)->find($report) ?? abort(404);
        $brandName = Brand::withoutUserScope()->where('user_id', $request->user()->id)->whereKey($found->brand_id)->value('name');

        return $this->respond($request, 'get_report', 'report', $found->id, fn () => $this->reports->status($found, $brandName));
    }
}
