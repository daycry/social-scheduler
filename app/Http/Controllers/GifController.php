<?php

namespace App\Http\Controllers;

use App\Services\Media\GiphyClient;
use App\Services\Media\GiphyException;
use App\Services\Media\UnsafeUrlException;
use App\Services\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Buscador de GIFs de Giphy (C-04b): proxy de busqueda (la clave no sale del servidor) e importacion del MP4 elegido. */
class GifController extends Controller
{
    public function __construct(private readonly GiphyClient $giphy, private readonly MediaUploadService $uploads) {}

    /** Busqueda (o tendencias si no hay texto). 404 sin clave: el boton tampoco se muestra. */
    public function search(Request $request): JsonResponse
    {
        abort_unless(GiphyClient::configured(), 404);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:4999'],
        ]);

        $q = trim((string) ($data['q'] ?? ''));
        $offset = (int) ($data['offset'] ?? 0);

        try {
            $page = $q === '' ? $this->giphy->trending($offset) : $this->giphy->search($q, $offset);
        } catch (GiphyException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status === 429 ? 429 : 502);
        }

        return response()->json($page);
    }

    /** Importa el MP4 elegido: solo hosts de Giphy; responde 202 con el asset `pendiente` (descarga en la cola `media`). */
    public function import(Request $request): JsonResponse
    {
        abort_unless(GiphyClient::configured(), 404);

        $data = $request->validate(['url' => ['required', 'string', 'max:2048']]);

        try {
            $asset = $this->uploads->importFromGiphy($request->user(), $data['url']);
        } catch (UnsafeUrlException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['asset' => $asset->toPublicArray()], 202);
    }
}
