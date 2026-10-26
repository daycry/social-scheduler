<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Services\Media\DriveFileSource;
use App\Services\Media\UnsafeUrlException;
use App\Services\MediaStorage;
use App\Services\MediaUploadService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    /** Importa media desde una URL publica (C-04a): valida con `UrlGuard`, responde 202 con el asset `pendiente` y descarga en la cola `media`. */
    public function importUrl(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2048']]);

        try {
            $asset = $this->uploads->importFromUrl($request->user(), $data['url']);
        } catch (UnsafeUrlException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['asset' => $asset->toPublicArray()], 202);
    }

    /**
     * Importa un fichero de Google Drive (C-04b) con el token del Picker. Solo se acepta si el Picker esta configurado (clave, app id y client id).
     * El token no se guarda: viaja cifrado en el job y caduca en 1 h.
     */
    public function importDrive(Request $request): JsonResponse
    {
        abort_unless(DriveFileSource::pickerConfigured(), 404);

        $data = $request->validate([
            'file_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/'],
            'access_token' => ['required', 'string', 'max:4096'],
        ]);

        try {
            $asset = $this->uploads->importFromDrive($request->user(), $data['file_id'], $data['access_token']);
        } catch (UnsafeUrlException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['asset' => $asset->toPublicArray()], 202);
    }

    /** Estado de un asset (para sondear el progreso de una importacion). */
    public function show(MediaAsset $asset): JsonResponse
    {
        $this->authorize('view', $asset);

        return response()->json(['asset' => $asset->toPublicArray()]);
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

    /**
     * Fichero de la media ya guardada, solo para su dueño (previsualizacion del compositor). En disco local se sirve con
     * soporte de rangos (los videos se pueden adelantar); con R2 redirige a una URL temporal firmada.
     */
    public function file(MediaAsset $asset): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view', $asset);
        abort_unless($asset->isReady(), 404);

        if ($this->storage->isS3()) {
            return redirect()->away($asset->publicUrl(30));
        }

        $disk = $this->storage->disk();
        abort_unless($disk->exists($asset->path), 404);

        return response()->file($disk->path($asset->path), [
            'Content-Type' => $asset->mime,
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate()->setMaxAge(3600);
    }

    public function destroy(MediaAsset $asset): JsonResponse
    {
        $this->authorize('delete', $asset);

        try {
            $this->uploads->delete($asset);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['ok' => true]);
    }
}
