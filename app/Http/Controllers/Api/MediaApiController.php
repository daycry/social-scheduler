<?php

namespace App\Http\Controllers\Api;

use App\Models\MediaAsset;
use App\Services\Media\UnsafeUrlException;
use App\Services\MediaListing;
use App\Services\MediaUploadService;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Media del usuario en la API v1: los mismos servicios que la web y el MCP (`MediaListing`, `MediaUploadService`), con los errores y la
 * auditoria de `ApiController`. No devuelve las URLs de sesion de la web (`preview_url`, `download_url`).
 */
class MediaApiController extends ApiController
{
    public function __construct(private readonly MediaUploadService $uploads, private readonly MediaListing $listing) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(CursorPage::RULES + ['status' => ['nullable', Rule::in(MediaListing::STATUSES)], 'kind' => ['nullable', Rule::in(MediaListing::KINDS)]]);

        return $this->respond($request, 'list_media', 'media', null, fn () => $this->listing->page($request->user(), $data['status'] ?? null, $data['limit'] ?? null, $data['cursor'] ?? null, kind: $data['kind'] ?? null));
    }

    public function show(Request $request, int $asset): JsonResponse
    {
        $found = $this->owned($request, $asset);

        return $this->respond($request, 'get_media', 'media', $found->id, fn () => ['asset' => MediaListing::present($found)]);
    }

    /** Registra el fichero y devuelve como subirlo (`upload.mode`: local, single o multipart). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'mime' => ['required', 'string', 'max:100'], 'size' => ['required', 'integer', 'min:1']]);

        return $this->respond($request, 'initiate_media_upload', 'media', null, function () use ($request, $data) {
            $result = $this->uploads->initiate($request->user(), $data['name'], $data['mime'], (int) $data['size']);
            $upload = $result['upload'];
            if ($upload['mode'] === 'local') {
                $upload['url'] = route('api.media.upload', $result['asset'], absolute: false);
            }

            return ['id' => $result['asset']->id, 'asset' => MediaListing::present($result['asset']), 'upload' => $upload];
        }, 201);
    }

    public function importUrl(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2048']]);

        // UnsafeUrlException (UrlGuard) es un InvalidArgumentException: `respond()` lo traduce a 422.
        return $this->respond($request, 'import_media_from_url', 'media', null, function () use ($request, $data) {
            $asset = $this->uploads->importFromUrl($request->user(), $data['url']);

            return ['id' => $asset->id, 'asset' => MediaListing::present($asset)];
        }, 202);
    }

    /** Subida del binario con el disco local (en R2/S3 se sube directo a las URLs prefirmadas). */
    public function upload(Request $request, int $asset): JsonResponse
    {
        $found = $this->owned($request, $asset);
        $stream = $request->getContent(true);

        try {
            return $this->respond($request, 'upload_media', 'media', $found->id, fn () => ['id' => $found->id, 'asset' => MediaListing::present($this->uploads->receiveLocal($found, $stream))]);
        } finally {
            is_resource($stream) && fclose($stream);
        }
    }

    /** Cierra la subida (multipart) y extrae los metadatos; con un fichero no valido responde 422 con el asset y el motivo. */
    public function complete(Request $request, int $asset): JsonResponse
    {
        $found = $this->owned($request, $asset);
        $data = $request->validate(MediaUploadService::COMPLETE_RULES);

        $response = $this->respond($request, 'complete_media_upload', 'media', $found->id, function () use ($found, $data) {
            $done = $this->uploads->completeClientUpload($found, $data['parts'] ?? []);

            return ['id' => $done->id, 'asset' => MediaListing::present($done)];
        });

        $done = $response->getData(true)['asset'] ?? null;

        return $done !== null && ($done['status'] ?? null) !== MediaAsset::STATUS_READY ? response()->json($response->getData(true) + ['message' => $done['error'] ?? 'El fichero no es valido.'], 422) : $response;
    }

    public function destroy(Request $request, int $asset): JsonResponse
    {
        $found = $this->owned($request, $asset);

        return $this->respond($request, 'delete_media', 'media', $found->id, function () use ($found) {
            $this->uploads->delete($found);

            return ['id' => $found->id, 'deleted' => true];
        });
    }

    private function owned(Request $request, int $asset): MediaAsset
    {
        return MediaAsset::withoutUserScope()->where('user_id', $request->user()->id)->find($asset) ?? abort(404);
    }
}
