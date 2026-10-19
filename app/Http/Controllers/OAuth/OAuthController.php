<?php

namespace App\Http\Controllers\OAuth;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Exceptions\ConnectorException;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\BrandService;
use App\Services\SocialAccountService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Flujo OAuth generico para todos los proveedores (meta | google | tiktok): redirige a la red,
 * valida `state` en el callback, canjea el `code` y conecta la(s) cuenta(s) a la marca.
 * Si el proveedor devuelve varias cuentas (p. ej. varias Paginas de Meta) se dejan pendientes de
 * eleccion en la pantalla de conexiones (tokens cifrados en sesion, nunca hacia el navegador).
 */
class OAuthController extends Controller
{
    use AuthorizesRequests;

    public const PENDING_KEY = 'oauth.pending';

    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly BrandService $brands,
        private readonly SocialAccountService $accounts,
    ) {}

    public function redirect(Request $request, Brand $brand, string $provider): RedirectResponse
    {
        $this->authorize('update', $brand);
        abort_unless($this->connectors->has($provider), 404);

        $state = Str::random(40);
        $request->session()->put("oauth.$provider", ['state' => $state, 'brand_id' => $brand->id]);

        $url = $this->connectors->forProvider($provider)->authorizationUrl(
            $state,
            route('connections.callback', $provider),
            $request->boolean('rerequest'),
        );

        return redirect()->away($url);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->connectors->has($provider), 404);

        $stored = $request->session()->pull("oauth.$provider");
        $state = (string) $request->query('state', '');

        if (! is_array($stored) || $state === '' || ! hash_equals($stored['state'], $state)) {
            Log::warning('oauth.invalid_state', ['user_id' => $request->user()->id, 'provider' => $provider]);

            return redirect()->route('brands.index')->with('error', 'La sesion de conexion ha caducado o no es valida. Vuelve a intentarlo.');
        }

        $brand = $this->brands->find($request->user(), $stored['brand_id']);
        abort_if($brand === null, 404);
        $this->authorize('update', $brand);

        $back = fn (string $type, string $message): RedirectResponse => redirect()
            ->route('brands.connections.index', $brand)
            ->with($type, $message);

        if ($request->query('error')) {
            Log::info('oauth.denied', ['provider' => $provider, 'error' => $request->query('error')]);

            return $back('error', 'La autorizacion fue cancelada o rechazada en la red social. No se ha conectado nada.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $back('error', 'La red social no devolvio ningun codigo de autorizacion.');
        }

        try {
            $candidates = $this->connectors->forProvider($provider)->exchangeCode($code, route('connections.callback', $provider));
        } catch (ConnectorException $e) {
            return $back('error', $e->getMessage());
        }

        if (count($candidates) === 1) {
            $account = $this->accounts->connect($request->user(), $brand, $candidates[0]);

            return $back('success', $account->network->label().' conectado: '.$account->name.'.');
        }

        $request->session()->put(self::PENDING_KEY.".{$brand->id}", Crypt::encrypt([
            'provider' => $provider,
            'candidates' => $candidates,
        ]));

        return $back('success', 'Elige las cuentas que quieres conectar a la marca.');
    }

    /**
     * @return array{provider: string, candidates: list<ConnectionCandidate>}|null
     */
    public static function pending(Request $request, Brand $brand): ?array
    {
        $payload = $request->session()->get(self::PENDING_KEY.".{$brand->id}");

        if (! $payload) {
            return null;
        }

        try {
            $data = Crypt::decrypt($payload);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
