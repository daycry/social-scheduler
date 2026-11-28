<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\IdempotencyKey;
use App\Models\SocialAccount;
use App\Services\AiAssistant;
use App\Services\ContentPlanService;
use App\Support\CurrentBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan de contenido en lenguaje natural (T-93): `propose` (POST ai/plan) pide a la IA un lote de borradores con fecha y NO crea nada;
 * `store` crea solo los elementos que el usuario confirma, revalidados en el servidor. Mismo contrato JSON y mismas convenciones que el
 * resto del asistente: 409 con la IA sin configurar, mensajes en castellano, 404 (sin llamar a la IA) para una marca ajena.
 */
class ContentPlanController extends Controller
{
    public function __construct(private readonly ContentPlanService $plan, private readonly AiAssistant $ai) {}

    public function index(Request $request): Response
    {
        $brand = CurrentBrand::resolve($request);
        $listing = $brand ? $this->plan->accounts($request->user(), $brand) : ['accounts' => [], 'excluded' => []];
        $describe = fn (SocialAccount $a) => ['id' => $a->id, 'name' => $a->name, 'network' => $a->network->value, 'network_label' => $a->network->label()];

        return Inertia::render('Posts/Plan', [
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name, 'timezone' => $brand->timezone ?: 'UTC'] : null,
            'accounts' => array_map(fn (array $o) => $describe($o['account']) + ['limit' => $o['limit']], $listing['accounts']),
            'excluded' => array_map(fn (array $o) => $describe($o['account']) + ['reason' => $o['reason']], $listing['excluded']),
            'maxItems' => ContentPlanService::maxItems(),
            'maxBriefing' => AiAssistant::MAX_BRIEFING,
            'maxText' => ContentPlanService::MAX_TEXT,
        ]);
    }

    public function propose(Request $request): JsonResponse
    {
        if (! $this->ai->enabled()) {
            return response()->json(['message' => 'El asistente IA no está activado.', 'remaining' => 0], 409);
        }

        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'briefing' => ['required', 'string', 'max:'.AiAssistant::MAX_BRIEFING],
        ]);
        $brand = $this->brand((int) $data['brand_id']);

        try {
            $proposal = $this->plan->propose($request->user(), $brand, $data['briefing']);
        } catch (PostValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'remaining' => $this->ai->remaining($request->user())], 422);
        }

        return response()->json($proposal + [
            'excluded' => array_map(fn (array $o) => ['id' => $o['account']->id, 'name' => $o['account']->name, 'reason' => $o['reason']], $this->plan->accounts($request->user(), $brand)['excluded']),
            'remaining' => $this->ai->remaining($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'mode' => ['required', 'in:'.implode(',', ContentPlanService::MODES)],
            'items' => ['required', 'array', 'min:1', 'max:'.ContentPlanService::maxItems()],
            'items.*.scheduled_at' => ['required', 'string', 'max:40'],
            'items.*.account_ids' => ['present', 'array', 'max:20'],
            'items.*.account_ids.*' => ['integer'],
            'items.*.text' => ['nullable', 'string', 'max:'.ContentPlanService::MAX_TEXT],
        ], ['items.max' => 'Un plan admite como máximo '.ContentPlanService::maxItems().' publicaciones.']);
        $brand = $this->brand((int) $data['brand_id']);

        try {
            $result = $this->plan->confirm($request->user(), $brand, $data['idempotency_key'], ['mode' => $data['mode'], 'items' => $data['items']]);
        } catch (PostValidationException $e) {
            // `already_used` solo si existe un registro con esa clave (confirmación ya creada o en curso): una clave mal formada o una reserva
            // fallida sin fila no crea nada y se puede reintentar.
            $alreadyUsed = isset($e->errors()['idempotency_key'])
                && IdempotencyKey::query()->where('user_id', $request->user()->id)->where('scope', 'content-plan')->where('key', $data['idempotency_key'])->exists();
            $message = match (true) {
                $alreadyUsed => 'Este plan ya se confirmó antes: se ha creado o se está creando. Revisa el calendario antes de volver a proponerlo.',
                isset($e->errors()['idempotency_key']) => (string) $e->errors()['idempotency_key'][0],
                default => 'El plan tiene publicaciones que no se pueden crear. Revísalas y vuelve a confirmar.',
            };

            // `already_used`: la clave pertenece a una confirmacion que ya se creo o sigue en curso (una ejecucion fallida la libera): reconfirmar duplicaria el lote.
            return response()->json(['message' => $message, 'errors' => $e->errors(), 'already_used' => $alreadyUsed], 422);
        }

        return response()->json($result);
    }

    /** UserScope: una marca ajena responde 404 y no se llama a la IA. */
    private function brand(int $id): Brand
    {
        return Brand::query()->find($id) ?? abort(404, 'No se ha encontrado la marca.');
    }
}
