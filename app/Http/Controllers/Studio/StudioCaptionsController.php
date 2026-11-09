<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Studio\Design;
use App\Services\Studio\StudioCaptions;
use Illuminate\Http\Request;

class StudioCaptionsController extends Controller
{
    public function __construct(private readonly StudioCaptions $captions) {}

    public function show(Request $request, Design $design)
    {
        return response()->json($this->captions->get($request->user(), $design, $request->only(['revision', 'page', 'per_page', 'query'])));
    }

    public function update(Request $request, Design $design)
    {
        return response()->json($this->captions->mutate($request->user(), $design, $request->all()));
    }

    public function export(Request $request, Design $design)
    {
        $file = $this->captions->export($request->user(), $design, $request->only(['revision', 'format']));

        return response($file['text'], 200, ['Content-Type' => $file['mime'].'; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
