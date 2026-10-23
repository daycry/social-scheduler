<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Services\AiAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Asistente IA opcional del compositor (T-14): JSON; sin configurar, `status` dice `enabled: false` y `suggest` responde 404. */
class AiAssistantController extends Controller
{
    public function __construct(private readonly AiAssistant $ai) {}

    public function status(Request $request): JsonResponse
    {
        $enabled = $this->ai->enabled();

        return response()->json(['enabled' => $enabled, 'remaining' => $enabled ? $this->ai->remaining($request->user()) : 0]);
    }

    public function suggest(Request $request): JsonResponse
    {
        abort_unless($this->ai->enabled(), 404);

        $data = $request->validate([
            'brief' => ['required', 'string', 'max:'.AiAssistant::MAX_BRIEF],
            'brand_id' => ['nullable', 'integer'],
            'network' => ['nullable', 'in:instagram,facebook,youtube,tiktok'],
        ]);
        $brand = isset($data['brand_id']) ? Brand::query()->find((int) $data['brand_id']) : null; // UserScope: una marca ajena no se usa

        try {
            $text = $this->ai->suggest($request->user(), $data['brief'], $brand?->name, $data['network'] ?? null);
        } catch (PostValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'remaining' => $this->ai->remaining($request->user())], 422);
        }

        return response()->json(['text' => $text, 'remaining' => $this->ai->remaining($request->user())]);
    }
}
