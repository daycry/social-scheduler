<?php

namespace App\Http\Controllers;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\CredentialConnector;
use App\Connectors\Exceptions\ConnectorException;
use App\Models\Brand;
use App\Services\SocialAccountService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Conexion con credenciales escritas por el usuario (Bluesky: handle + app password; D1). Es el equivalente de
 * OAuthController para los proveedores que NO redirigen. La contrasena viaja solo en el cuerpo del POST: nunca se
 * devuelve, no se vuelve a mostrar en el formulario (ni siquiera tras un error) y no se registra.
 */
class CredentialConnectionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly SocialAccountService $accounts,
    ) {}

    public function store(Request $request, Brand $brand, string $provider): RedirectResponse
    {
        $this->authorize('update', $brand);
        abort_unless($this->connectors->has($provider), 404);
        $connector = $this->connectors->forProvider($provider);
        abort_unless($connector instanceof CredentialConnector, 404);

        // Validacion manual: `validate()` reflejaria la entrada en la sesion (old input) y la app password quedaria alli.
        $validator = Validator::make($request->only(['handle', 'app_password']), [
            'handle' => ['required', 'string', 'max:255'],
            'app_password' => ['required', 'string', 'max:255'],
        ], [
            'handle.required' => 'Escribe tu handle de Bluesky (por ejemplo tunombre.bsky.social).',
            'app_password.required' => 'Escribe la app password de Bluesky.',
        ]);

        $back = redirect()->route('brands.connections.index', $brand);

        if ($validator->fails()) {
            return $back->withErrors($validator->errors()->toArray());
        }

        try {
            $candidates = $connector->connectWithCredentials($validator->validated());
        } catch (ConnectorException $e) {
            return $back->withErrors(['credentials' => $e->getMessage()]);
        }

        $account = $this->accounts->connect($request->user(), $brand, $candidates[0]);

        return $back->with('success', $account->network->label().' conectado: '.$account->name.'.');
    }
}
