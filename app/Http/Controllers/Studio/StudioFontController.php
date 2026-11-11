<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Studio\StudioFonts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudioFontController extends Controller
{
    public function __construct(private readonly StudioFonts $fonts) {}

    public function index(Request $request, Brand $brand): JsonResponse
    {
        $filters = $request->only(['query', 'page', 'per_page', 'archived']);
        if (isset($filters['archived']) && in_array($filters['archived'], ['true', 'false'], true)) {
            $filters['archived'] = $filters['archived'] === 'true';
        }

        return response()->json($this->fonts->listing($request->user(), $brand, $filters, $request->is('api/*')));
    }

    public function store(Request $request, Brand $brand): JsonResponse
    {
        $request->validate(['file' => ['required', 'file'], 'name' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $font = $this->fonts->upload($request->user(), $brand, $request->file('file'), $request->input('name'));

        return response()->json(['font' => $this->fonts->present($font, $request->is('api/*'))], 201);
    }

    public function show(Request $request, Brand $brand, int $font): JsonResponse
    {
        return response()->json(['font' => $this->fonts->present($this->fonts->owned($request->user(), $brand, $font), $request->is('api/*'))]);
    }

    public function file(Request $request, Brand $brand, int $font): StreamedResponse
    {
        return $this->fonts->file($request->user(), $brand, $font);
    }

    public function destroy(Request $request, Brand $brand, int $font): Response
    {
        $this->fonts->delete($request->user(), $brand, $font);

        return response()->noContent();
    }

    public function archive(Request $request, Brand $brand, int $font): JsonResponse
    {
        return response()->json(['font' => $this->fonts->present($this->fonts->archive($request->user(), $brand, $font, true), $request->is('api/*'))]);
    }

    public function restore(Request $request, Brand $brand, int $font): JsonResponse
    {
        return response()->json(['font' => $this->fonts->present($this->fonts->archive($request->user(), $brand, $font, false), $request->is('api/*'))]);
    }
}
