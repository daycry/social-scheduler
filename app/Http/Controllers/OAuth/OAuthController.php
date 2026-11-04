<?php

namespace App\Http\Controllers\OAuth;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\OAuthConnector;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Threads\ThreadsClient;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\BrandService;
use App\Services\SocialAccountService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

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

    private const FLOW_TTL_SECONDS = 1800;

    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly BrandService $brands,
        private readonly SocialAccountService $accounts,
    ) {}

    public function redirect(Request $request, Brand $brand, string $provider): Response
    {
        $this->authorize('update', $brand);
        $connector = $this->oauthConnector($provider);

        $state = Str::random(40);
        $flow = ['state' => $state, 'brand_id' => $brand->id, 'user_id' => $request->user()->id, 'issued_at' => now()->timestamp];
        $key = $this->flowKey($request, $provider);
        Cache::lock($key.'.lock', 5)->block(5, function () use ($key, $flow, $state): void {
            $flows = Cache::get($key, []);
            $flows = array_filter(is_array($flows) ? $flows : [], fn ($item) => is_array($item)
                && ($item['issued_at'] ?? 0) > now()->timestamp - self::FLOW_TTL_SECONDS);
            $flows[hash('sha256', $state)] = $flow;
            Cache::put($key, array_slice($flows, -10, null, true), self::FLOW_TTL_SECONDS);
        });
        // Convenience pointer only: ordinary session writes cannot create or revive authoritative flows.
        $request->session()->put("oauth.$provider", $flow);

        $url = $connector->authorizationUrl(
            $state,
            route('connections.callback', $provider),
            $request->boolean('rerequest'),
        );

        // Inertia::location: para una peticion Inertia (409 + X-Inertia-Location) fuerza una visita completa
        // a la red externa; para una peticion normal es un redirect 302 corriente.
        return Inertia::location($url);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $connector = $this->oauthConnector($provider);

        $state = $request->query('state', '');
        $stored = is_string($state) ? $this->takeFlow($request, $provider, $state) : null;

        if ($stored === null) {
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

            if ($provider === 'threads' && ThreadsClient::looksLikeNotTester($request->query('error_description').' '.$request->query('error_reason'))) {
                return $back('error', ThreadsClient::NOT_TESTER_MESSAGE);
            }

            return $back('error', 'La autorizacion fue cancelada o rechazada en la red social. No se ha conectado nada.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $back('error', 'La red social no devolvio ningun codigo de autorizacion.');
        }

        try {
            $candidates = $connector->exchangeCode($code, route('connections.callback', $provider));
        } catch (ConnectorException $e) {
            return $back('error', $e->getMessage());
        }

        if (count($candidates) === 1) {
            $account = $this->accounts->connect($request->user(), $brand, $candidates[0]);

            $missing = $account->meta['missing_scopes'] ?? [];

            return $back('success', $account->network->label().' conectado: '.$account->name.'.'
                .($missing ? ' Aviso: faltan permisos para publicar ('.implode(', ', $missing).'); reconecta y aceptalos.' : ''));
        }

        $request->session()->put(self::PENDING_KEY.".{$brand->id}", Crypt::encrypt([
            'provider' => $provider,
            'candidates' => $candidates,
        ]));

        // Sin aviso verde: aun no se ha conectado nada. La tarjeta de eleccion (y el aviso global) piden el paso que falta.
        return redirect()->route('brands.connections.index', $brand);
    }

    /** Consume one matching authorization without invalidating another tab or brand. */
    private function takeFlow(Request $request, string $provider, string $state): ?array
    {
        if ($state === '' || strlen($state) > 128) {
            return null;
        }
        $key = $this->flowKey($request, $provider);
        $stored = Cache::lock($key.'.lock', 5)->block(5, function () use ($key, $state, $request): ?array {
            $flows = Cache::get($key, []);
            $hash = hash('sha256', $state);
            $flow = is_array($flows) ? ($flows[$hash] ?? null) : null;
            if (! is_array($flow) || ! is_string($flow['state'] ?? null) || ! hash_equals($flow['state'], $state)) {
                return null;
            }
            unset($flows[$hash]);
            Cache::put($key, $flows, self::FLOW_TTL_SECONDS);
            if (($flow['user_id'] ?? null) !== $request->user()->id
                || ($flow['issued_at'] ?? 0) <= now()->timestamp - self::FLOW_TTL_SECONDS) {
                return null;
            }

            return $flow;
        });
        if ($stored === null) {
            return null;
        }
        $latest = $request->session()->get("oauth.$provider");
        if (is_array($latest) && is_string($latest['state'] ?? null) && hash_equals($latest['state'], $state)) {
            $request->session()->forget("oauth.$provider");
        }

        return $stored;
    }

    private function flowKey(Request $request, string $provider): string
    {
        // The CSRF secret identifies the browser session without depending on a rotated session ID.
        return 'social.oauth.flows.'.hash('sha256', $request->user()->id.'|'.$request->session()->token().'|'.$provider);
    }

    /** Solo los proveedores OAuth tienen redireccion y callback; los de credenciales (Bluesky) responden 404. */
    private function oauthConnector(string $provider): OAuthConnector
    {
        abort_unless($this->connectors->has($provider), 404);
        $connector = $this->connectors->forProvider($provider);
        abort_unless($connector instanceof OAuthConnector, 404);

        return $connector;
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
