<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Studio\StudioAudio;
use App\Services\Studio\StudioAudioProbeUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudioAudioController extends Controller
{
    public function __construct(private readonly StudioAudio $audio) {}

    public function store(Request $request, Brand $brand): JsonResponse
    {
        return response()->json($this->audio->start($request->user(), $brand, $request->all(), $request->is('api/*')), 201);
    }

    public function index(Request $request, Brand $brand): JsonResponse
    {
        return response()->json($this->audio->listing($request->user(), $brand, $request->only(['query', 'page', 'per_page']), $request->is('api/*')));
    }

    public function show(Request $request, Brand $brand, int $media): JsonResponse
    {
        return response()->json(['asset' => $this->audio->present($brand, $this->audio->owned($request->user(), $brand, $media), $request->is('api/*'))]);
    }

    public function upload(Request $request, Brand $brand, int $media): JsonResponse
    {
        $stream = $request->getContent(true);
        try {
            $asset = $this->audio->uploadLocal($request->user(), $brand, $media, $stream);
        } catch (StudioAudioProbeUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return response()->json(['asset' => $this->audio->present($brand, $asset, $request->is('api/*'))], $asset->isReady() ? 200 : 422);
    }

    public function complete(Request $request, Brand $brand, int $media): JsonResponse
    {
        try {
            $asset = $this->audio->complete($request->user(), $brand, $media, $request->all());
        } catch (StudioAudioProbeUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['asset' => $this->audio->present($brand, $asset, $request->is('api/*'))], $asset->isReady() ? 200 : 422);
    }
}
