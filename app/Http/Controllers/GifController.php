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
            $page = $q === '' ? $this->giphy->trending($offset, $request->user()->id) : $this->giphy->search($q, $offset, $request->user()->id);
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

        // Lista cerrada ANTES de pedir nada; luego la URL se RECONSTRUYE pidiendo el GIF a Giphy por su id (con su rating): no se usa la del cliente.
        if (! GiphyClient::allowedMediaUrl($data['url']) || ! preg_match('#^/media/(?:v1\.[^/]+/)?([A-Za-z0-9]+)/#', (string) parse_url($data['url'], PHP_URL_PATH), $m)) {
            return response()->json(['message' => 'Solo se pueden importar GIFs de Giphy.'], 422);
        }

        try {
            $url = $this->giphy->mp4ById($m[1], $request->user()->id);
            $asset = $this->uploads->importFromGiphy($request->user(), $url);
        } catch (GiphyException $e) {
            return response()->json(['message' => $e->getMessage()], in_array($e->status, [422, 429], true) ? $e->status : 502);
        } catch (UnsafeUrlException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['asset' => $asset->toPublicArray()], 202);
    }
}
