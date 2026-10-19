<?php

namespace App\Http\Controllers;

use App\Mcp\Abilities;
use App\Services\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/** API keys personales (Sanctum) con las que un agente se conecta al MCP. El secreto se muestra UNA vez. */
class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('ApiKeys/Index', [
            'keys' => $request->user()->tokens()->latest('id')->get()->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => $t->abilities,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ])->values(),
            'abilities' => Abilities::all(),
            'endpoint' => url('/mcp'),
            'newKey' => session('new_key'),
        ]);
    }

    public function store(Request $request, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(Abilities::keys())],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $token = $request->user()->createToken($data['name'], array_values(array_unique($data['abilities'])), isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : now()->addMinutes((int) config('sanctum.expiration', 525600)));
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
