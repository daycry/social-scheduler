<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Studio\Design;
use App\Services\Studio\StudioAi;
use App\Services\Studio\StudioAiText;
use App\Services\Studio\StudioCaptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** IA creativa del estudio (T-14): estado, trabajos de imagen y automatizaciones de texto. Web y API comparten el servicio. */
class StudioAiController extends Controller
{
    public function status(Request $request, Brand $brand, StudioAi $ai, StudioAiText $text): JsonResponse
    {
        abort_unless((int) $brand->user_id === (int) $request->user()->id, 404);

        return response()->json($ai->status($request->user()) + ['text' => $text->status($request->user())]);
    }

    public function store(Request $request, Brand $brand, StudioAi $ai): JsonResponse
    {
        $job = $ai->create($request->user(), $brand, $request->all());

        return response()->json(['job' => $ai->present($request->user(), $brand, $job, $request->routeIs('api.*'))], 202);
    }

    public function show(Request $request, Brand $brand, int $job, StudioAi $ai): JsonResponse
    {
        $found = $ai->find($request->user(), $brand, $job);

        return response()->json(['job' => $ai->present($request->user(), $brand, $found, $request->routeIs('api.*'))]);
    }

    public function text(Request $request, Brand $brand, StudioAiText $text, StudioCaptions $captions): JsonResponse
    {
        $user = $request->user();
        abort_unless((int) $brand->user_id === (int) $user->id, 404);
        $action = $request->input('action');
        $allowed = ['action', 'brief', 'count', 'design_id', 'items', 'media_id', 'language', 'cues'];
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => ['La petición contiene propiedades no compatibles.']]);
        }

        return response()->json(match ($action) {
            'copy' => ['result' => $text->copy($user, $brand, (string) $request->input('brief', ''), (int) $request->input('count', 3))],
            'fill_template' => ['result' => $this->fill($request, $brand, $text)],
            'alt_text' => ['result' => ['alt' => $text->altText($user, $brand, (int) $request->input('media_id'))]],
            'translate_captions' => ['result' => $captions->translate($user, $brand, (array) $request->input('cues', []), (string) $request->input('language', ''))],
            default => throw ValidationException::withMessages(['action' => ['Acción de texto no compatible.']]),
        });
    }

    private function fill(Request $request, Brand $brand, StudioAiText $text): array
    {
        $items = $request->input('items');
        if (! is_array($items) || ! array_is_list($items)) {
            throw ValidationException::withMessages(['items' => ['Escribe la lista de elementos.']]);
        }
        $design = Design::withoutUserScope()->where('user_id', $request->user()->id)->where('brand_id', $brand->id)->findOrFail((int) $request->input('design_id'));

        return $text->fillTemplate($request->user(), $design, $items);
    }
}
