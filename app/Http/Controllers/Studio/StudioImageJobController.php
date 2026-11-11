<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Studio\StudioImageJob;
use App\Services\Studio\StudioImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudioImageJobController extends Controller
{
    public function store(Request $request, Brand $brand, StudioImages $images): JsonResponse
    {
        $job = $images->create($request->user(), $brand, $request->all());

        return response()->json(['job' => $images->present($request->user(), $brand, $job, $request->routeIs('api.*'))], 202);
    }

    public function show(Request $request, Brand $brand, StudioImageJob $job, StudioImages $images): JsonResponse
    {
        return response()->json(['job' => $images->present($request->user(), $brand, $job, $request->routeIs('api.*'))]);
    }
}
