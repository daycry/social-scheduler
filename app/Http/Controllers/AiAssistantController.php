<?php

namespace App\Http\Controllers;

use App\Connectors\Network;
use App\Enums\ContentType;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Services\AiAssistant;
use App\Services\Studio\StudioAiText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Asistente IA opcional del compositor (T-14): JSON; sin configurar, `status` dice `enabled: false` y las cuatro rutas de generacion responden 409 con la explicacion.
 * `schedule` (C-04c) solo PROPONE una fecha y unas redes a partir de una frase: el compositor las aplica cuando el usuario confirma.
 * `adapt` (T-82) propone una version del texto por red y `alt` un texto alternativo de una imagen propia: ambos solo proponen. Los mensajes
 * al usuario van siempre en castellano (tambien los 404).
 */
class AiAssistantController extends Controller
{
    public function __construct(private readonly AiAssistant $ai) {}

    public function status(Request $request): JsonResponse
    {
        $enabled = $this->ai->enabled();

        // `vision`: el modelo admite imagenes (texto alternativo); sin IA configurada no hay nada que ofrecer.
        return response()->json([
            'enabled' => $enabled,
            'remaining' => $enabled ? $this->ai->remaining($request->user()) : 0,
            'vision' => $enabled && (bool) config('social.ai.vision', true),
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        if (! $this->ai->enabled()) {
            return $this->notEnabled();
        }

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
        if (! $this->ai->enabled()) {
            return $this->notEnabled();
        }

        $data = $request->validate([
            'phrase' => ['required', 'string', 'max:'.AiAssistant::MAX_PHRASE],
            'brand_id' => ['required', 'integer'],
        ]);
        $brand = $this->brand((int) $data['brand_id']);

        try {
            $proposal = $this->ai->proposeSchedule($request->user(), $brand, $data['phrase']);
        } catch (PostValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'remaining' => $this->ai->remaining($request->user())], 422);
        }

        return response()->json(['proposal' => $proposal, 'remaining' => $this->ai->remaining($request->user())]);
    }

    public function adapt(Request $request): JsonResponse
    {
        if (! $this->ai->enabled()) {
            return $this->notEnabled();
        }

        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'text' => ['required', 'string', 'max:'.AiAssistant::MAX_ADAPT],
            'targets' => ['required', 'array', 'min:1', 'max:'.count(Network::cases()) * 4],
            'targets.*.account_id' => ['required', 'integer', 'distinct'],
            'targets.*.content_type' => ['required', Rule::enum(ContentType::class)],
        ]);
        $brand = $this->brand((int) $data['brand_id']);

        $targets = [];
        foreach ($data['targets'] as $index => $target) {
            // Solo cuentas de ESTA marca (y del usuario): una ajena responde 404.
            $account = SocialAccount::ownedBy($request->user()->id)->where('brand_id', $brand->id)->find((int) $target['account_id'])
                ?? abort(404, 'No se ha encontrado la cuenta en esta marca.');
            $type = ContentType::from($target['content_type']);
            if ($type->network() !== $account->network) {
                throw ValidationException::withMessages(["targets.$index.content_type" => ['El tipo de contenido no corresponde a la red de la cuenta.']]);
            }
            $targets[] = ['account_id' => $account->id, 'content_type' => $type];
        }

        try {
            $results = $this->ai->adapt($request->user(), $brand, $data['text'], $targets);
        } catch (PostValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'remaining' => $this->ai->remaining($request->user())], 422);
        }

        return response()->json(['results' => $results, 'remaining' => $this->ai->remaining($request->user())]);
    }

    public function alt(Request $request, StudioAiText $text): JsonResponse
    {
        if (! $this->ai->enabled()) {
            return $this->notEnabled();
        }

        $data = $request->validate(['media_id' => ['required', 'integer']]);
        // La media se autoriza por propietario (biblioteca, URL, Drive o Recursos de cualquier marca del usuario); una ajena responde 404.
        $media = MediaAsset::ownedBy($request->user()->id)->find((int) $data['media_id']) ?? abort(404, 'No se ha encontrado la imagen.');

        // `describe` valida que sea una imagen lista y consume una unidad de la cuota diaria.
        return response()->json(['text' => $text->describe($request->user(), $media), 'remaining' => $this->ai->remaining($request->user())]);
    }

    /** UserScope: una marca ajena responde 404 y no se llama a la IA. */
    private function brand(int $id): Brand
    {
        return Brand::query()->find($id) ?? abort(404, 'No se ha encontrado la marca.');
    }

    private function notEnabled(): JsonResponse
    {
        return response()->json(['message' => 'El asistente IA no está activado.', 'remaining' => 0], 409);
    }
}
