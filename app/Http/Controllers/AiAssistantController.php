<?php

namespace App\Http\Controllers;

use App\Connectors\Network;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Services\AiAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Asistente IA opcional del compositor (T-14): JSON; sin configurar, `status` dice `enabled: false` y `suggest` y `schedule` responden 404.
 * `schedule` (C-04c) solo PROPONE una fecha y unas redes a partir de una frase: el compositor las aplica cuando el usuario confirma.
 */
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
            'network' => ['nullable', Rule::enum(Network::class)],
        ]);
        $brand = isset($data['brand_id']) ? Brand::query()->find((int) $data['brand_id']) : null; // UserScope: una marca ajena no se usa

        try {
            $text = $this->ai->suggest($request->user(), $data['brief'], $brand?->name, $data['network'] ?? null);
        } catch (PostValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'remaining' => $this->ai->remaining($request->user())], 422);
        }

        return response()->json(['text' => $text, 'remaining' => $this->ai->remaining($request->user())]);
    }

    public function schedule(Request $request): JsonResponse
    {
        abort_unless($this->ai->enabled(), 404);

        $data = $request->validate([
            'phrase' => ['required', 'string', 'max:'.AiAssistant::MAX_PHRASE],
            'brand_id' => ['required', 'integer'],
        ]);
        $brand = Brand::query()->findOrFail((int) $data['brand_id']); // UserScope: una marca ajena responde 404 y no se llama a la IA

        try {
            $proposal = $this->ai->proposeSchedule($request->user(), $brand, $data['phrase']);
        } catch (PostValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'remaining' => $this->ai->remaining($request->user())], 422);
        }

        return response()->json(['proposal' => $proposal, 'remaining' => $this->ai->remaining($request->user())]);
    }
}
