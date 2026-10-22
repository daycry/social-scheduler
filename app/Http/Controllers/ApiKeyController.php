<?php

namespace App\Http\Controllers;

use App\Mcp\Abilities;
use App\Models\PersonalAccessToken;
use App\Services\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** API keys personales (Sanctum) con las que un agente se conecta al MCP. El secreto se muestra UNA vez. */
class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('ApiKeys/Index', [
            'keys' => $request->user()->tokens()->with('oauthClient')->latest('id')->get()->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => $t->abilities,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
                // Origen: manual (creada aqui) u OAuth (emitida por el servidor de autorizacion a un cliente MCP).
                'origin' => $t->oauth_client_id !== null ? 'oauth' : 'manual',
                'oauth_client' => $t->oauthClient?->name,
            ])->values(),
            'abilities' => Abilities::all(),
            'presets' => Abilities::presets(),
            'endpoint' => url('/mcp'),
            'newKey' => session('new_key'),
        ]);
    }

    public function store(Request $request, AuditLog $audit): RedirectResponse
    {
        // La fecha elegida manda, con un tope configurable; sin fecha, el default (nunca 0 ni sin caducidad).
        $maxDays = max(1, (int) config('services.mcp.key_max_days', 730));
        $defaultDays = min($maxDays, max(1, (int) config('services.mcp.key_default_days', 365)));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(Abilities::keys())],
            'expires_at' => ['nullable', 'date', 'after:now', 'before_or_equal:'.now()->addDays($maxDays)->toDateString()],
        ]);

        $token = $request->user()->createToken($data['name'], array_values(array_unique($data['abilities'])), isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : now()->addDays($defaultDays));
        $audit->record($request->user(), 'api_key.created', AuditLog::OK, 'api_key', $token->accessToken->id, null, 'web');

        // Se muestra una sola vez: en BD solo queda su hash.
        return redirect()->route('api-keys.index')->with('new_key', $token->plainTextToken);
    }

    public function destroy(Request $request, int $key, AuditLog $audit): RedirectResponse
    {
        // Solo entre las keys del propio usuario: la de otro responde 404.
        $token = $request->user()->tokens()->findOrFail($key);
        $token->delete();
        $audit->record($request->user(), 'api_key.revoked', AuditLog::OK, 'api_key', $key, null, 'web');

        return redirect()->route('api-keys.index')->with('success', 'API key revocada.');
    }
}
