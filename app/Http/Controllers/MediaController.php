<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Services\MediaStorage;
use App\Services\MediaUploadService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Endpoints JSON de la subida de media (prefirmado, finalizacion, subida local de dev, borrado). */
class MediaController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MediaUploadService $uploads,
        private readonly MediaStorage $storage,
    ) {}

    /** Media lista del usuario (para el selector del compositor). */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'media' => MediaAsset::query()->where('status', MediaAsset::STATUS_READY)
                ->latest('id')->limit(100)->get()->map->toPublicArray()->values(),
        ]);
    }

    /** Registra el asset y devuelve las URLs prefirmadas (single/multipart) o el modo local. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $this->uploads->initiate($request->user(), $data['name'], $data['mime'], (int) $data['size']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $upload = $result['upload'];
        if ($upload['mode'] === 'local') {
            $upload['url'] = route('media.upload', $result['asset'], absolute: false);
        }

        return response()->json(['asset' => $result['asset']->toPublicArray(), 'upload' => $upload], 201);
    }

    /** Subida directa a la app, solo con el disco local (dev/test). En produccion (R2) no se usa. */
    public function upload(Request $request, MediaAsset $asset): JsonResponse
    {
        $this->authorize('update', $asset);
        abort_if($this->storage->isS3() || $asset->status !== MediaAsset::STATUS_PENDING, 409, 'La subida local no esta disponible.');

        $stream = $request->getContent(true);
        $this->storage->disk()->writeStream($asset->path, $stream);
        is_resource($stream) && fclose($stream);

        return response()->json(['asset' => $this->uploads->finalize($asset)->toPublicArray()]);
    }

    /** Cierra la subida (multipart) y extrae los metadatos. */
    public function complete(Request $request, MediaAsset $asset): JsonResponse
    {
        $this->authorize('update', $asset);

        $data = $request->validate([
            'parts' => ['sometimes', 'array', 'max:10000'],
            'parts.*.number' => ['required_with:parts', 'integer', 'min:1', 'max:10000'],
            'parts.*.etag' => ['required_with:parts', 'string', 'max:200'],
        ]);

        try {
            $asset = $this->uploads->complete($asset, $data['parts'] ?? []);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['asset' => $asset->toPublicArray()], $asset->isReady() ? 200 : 422);
    }

    public function destroy(MediaAsset $asset): JsonResponse
    {
        $this->authorize('delete', $asset);

        if ($asset->posts()->exists()) {
            return response()->json(['message' => 'El archivo se usa en una publicacion; quitalo de ella antes de borrarlo.'], 409);
        }

        $this->uploads->delete($asset);

        return response()->json(['ok' => true]);
    }
}
