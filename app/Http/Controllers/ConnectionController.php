<?php

namespace App\Http\Controllers;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Http\Controllers\OAuth\OAuthController;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Services\SocialAccountService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Pantalla de conexiones de una marca: estado, conectar, elegir cuentas, reconectar y desconectar. */
class ConnectionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SocialAccountService $accounts,
        private readonly ConnectorRegistry $connectors,
    ) {}

    public function index(Request $request, Brand $brand): Response
    {
        $this->authorize('view', $brand);

        $pending = OAuthController::pending($request, $brand);

        return Inertia::render('Brands/Connections', [
            'brand' => $brand->only(['id', 'name']),
            // Lista explicita de campos: los tokens nunca llegan al navegador.
            'accounts' => $this->accounts->listForBrand($request->user(), $brand)->map(fn (SocialAccount $a) => [
                'id' => $a->id,
                'network' => $a->network->value,
                'network_label' => $a->network->label(),
                'provider' => $a->network->provider(),
                'name' => $a->name,
                'username' => $a->username,
                'status' => $a->status,
                'last_error' => $a->last_error,
                'token_expires_at' => $a->token_expires_at?->toIso8601String(),
                'data_access_expires_at' => $a->data_access_expires_at?->toIso8601String(),
                'connected_at' => $a->connected_at?->toIso8601String(),
            ])->values(),
            'providers' => collect(Network::cases())->map(fn (Network $n) => [
                'network' => $n->value,
                'label' => $n->label(),
                'provider' => $n->provider(),
                'available' => $this->connectors->has($n->provider()),
            ])->values(),
            'pending' => $pending ? [
                'provider' => $pending['provider'],
                'candidates' => array_map(fn ($c) => $c->toPublicArray(), $pending['candidates']),
            ] : null,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }

    /** Conecta las cuentas elegidas entre las candidatas pendientes del ultimo OAuth. */
    public function select(Request $request, Brand $brand): RedirectResponse
    {
        $this->authorize('update', $brand);

        $data = $request->validate(['keys' => ['required', 'array', 'min:1'], 'keys.*' => ['string']]);
        $pending = OAuthController::pending($request, $brand);

        if (! $pending) {
            return redirect()->route('brands.connections.index', $brand)
                ->with('error', 'No hay una conexion pendiente de confirmar. Vuelve a conectar la red.');
        }

        $connected = 0;
        foreach ($pending['candidates'] as $candidate) {
            if (in_array($candidate->key(), $data['keys'], true)) {
                $this->accounts->connect($request->user(), $brand, $candidate);
                $connected++;
            }
        }

        $request->session()->forget(OAuthController::PENDING_KEY.".{$brand->id}");

        return redirect()->route('brands.connections.index', $brand)->with(
            $connected > 0 ? 'success' : 'error',
            $connected > 0 ? "$connected cuenta(s) conectada(s)." : 'Ninguna de las cuentas elegidas es valida.',
        );
    }

    public function destroy(Request $request, Brand $brand, SocialAccount $account): RedirectResponse
    {
        $this->authorize('update', $brand);
        $this->authorize('disconnect', $account);
        abort_unless($account->brand_id === $brand->id, 404);

        $this->accounts->disconnect($account);

        return redirect()->route('brands.connections.index', $brand)->with('success', 'Cuenta desconectada y credenciales eliminadas.');
    }
}
