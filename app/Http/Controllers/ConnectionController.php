<?php

namespace App\Http\Controllers;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\CredentialConnector;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Meta\PlaceSearch;
use App\Connectors\Meta\PlaceSearchUnavailable;
use App\Connectors\Network;
use App\Connectors\Pinterest\PinterestConnector;
use App\Connectors\TikTok\TikTokPublisher;
use App\Http\Controllers\OAuth\OAuthController;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Services\ConnectionExpiry;
use App\Services\SocialAccountService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
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
                'auth_kind' => $a->network->authKind(),
                'name' => $a->name,
                'username' => $a->username,
                'status' => $a->status,
                'last_error' => $a->last_error,
                'boards_count' => $a->network === Network::Pinterest ? count((array) ($a->meta['boards'] ?? [])) : null,
                'boards_partial' => $a->network === Network::Pinterest ? (bool) ($a->meta['boards_partial'] ?? false) : null,
                'boards_refreshed_at' => $a->network === Network::Pinterest ? ($a->meta['boards_refreshed_at'] ?? null) : null,
                'direct_post' => $a->network === Network::TikTok && ($a->meta['direct_post'] ?? false) === true,
                'token_expires_at' => $a->token_expires_at?->toIso8601String(),
                // Aviso «reconecta antes de» (T-23): caducidad conocida que el sistema no renueva (acceso a datos de Meta, token de LinkedIn sin refresh_token) en pocos dias. No bloquea nada.
                ...$this->expiryProps($a),
                'data_access_expires_at' => $a->data_access_expires_at?->toIso8601String(),
                'connected_at' => $a->connected_at?->toIso8601String(),
            ])->values(),
            // Las redes OAuth sin configurar se listan como «no disponibles»; una de credenciales sin conector no se ofrece. Una red apagada
            // o sin configurar a proposito (Threads: THREADS_ENABLED / THREADS_APP_ID) no se lista en absoluto.
            'providers' => collect(Network::cases())
                ->filter(fn (Network $n) => $n->isAvailable())
                ->filter(fn (Network $n) => $n->authKind() === Network::AUTH_OAUTH || $this->connectors->has($n->provider()))
                ->map(fn (Network $n) => [
                    'network' => $n->value,
                    'label' => $n->label(),
                    'provider' => $n->provider(),
                    'auth_kind' => $n->authKind(),
                    'available' => $this->connectors->has($n->provider()),
                    // Campos del formulario de las redes que se conectan con credenciales (nunca valores guardados).
                    'fields' => $this->credentialFields($n),
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

    /** @return array{reconnect_before: ?string, reconnect_reason: ?string, reconnect_message: ?string} */
    private function expiryProps(SocialAccount $a): array
    {
        $d = ConnectionExpiry::dueSoon($a);

        return ['reconnect_before' => $d['at'] ?? null, 'reconnect_reason' => $d['reason'] ?? null, 'reconnect_message' => $d ? ConnectionExpiry::message($a, $d['reason']) : null];
    }

    /** @return list<array{name: string, label: string, type: string, help?: string}> */
    private function credentialFields(Network $network): array
    {
        if ($network->authKind() !== Network::AUTH_CREDENTIALS || ! $this->connectors->has($network->provider())) {
            return [];
        }
        $connector = $this->connectors->forProvider($network->provider());

        return $connector instanceof CredentialConnector ? $connector->credentialFields() : [];
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

    /** Descarta la eleccion pendiente del ultimo OAuth sin conectar ninguna cuenta. */
    public function discardPending(Request $request, Brand $brand): RedirectResponse
    {
        $this->authorize('update', $brand);

        $request->session()->forget(OAuthController::PENDING_KEY.".{$brand->id}");

        return redirect()->route('brands.connections.index', $brand)
            ->with('success', 'Conexion descartada: no se ha conectado ninguna cuenta.');
    }

    /** Activa/desactiva Direct Post de una cuenta de TikTok (por defecto desactivado: borrador a la bandeja). */
    public function directPost(Request $request, Brand $brand, SocialAccount $account): RedirectResponse
    {
        $this->authorize('update', $brand);
        $this->authorize('disconnect', $account);
        abort_unless($account->brand_id === $brand->id && $account->network === Network::TikTok, 404);

        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $account->forceFill(['meta' => array_merge($account->meta ?? [], ['direct_post' => $enabled])])->save();

        return redirect()->route('brands.connections.index', $brand)->with('success', $enabled
            ? 'Direct Post activado: TikTok publicara directamente (requiere auditoria aprobada).'
            : 'Direct Post desactivado: los videos se enviaran como borrador a la bandeja de TikTok.');
    }

    /** Vuelve a leer los tableros de una cuenta de Pinterest (paginados con `bookmark`) y guarda la lista para el compositor. */
    public function refreshPinterestBoards(Brand $brand, SocialAccount $account, PinterestConnector $pinterest): RedirectResponse
    {
        $this->authorize('update', $brand);
        $this->authorize('disconnect', $account);
        abort_unless($account->brand_id === $brand->id && $account->network === Network::Pinterest, 404);

        $back = redirect()->route('brands.connections.index', $brand);

        try {
            $boards = $pinterest->refreshBoards($account);
        } catch (ConnectorException $e) {
            return $back->with('error', 'No se pudieron actualizar los tableros: '.$e->getMessage());
        }

        if ((bool) ($account->fresh()->meta['boards_partial'] ?? false)) {
            return $back->with('success', 'Tableros actualizados: '.count($boards).' (lista incompleta; si no ves tu tablero, indícalo por su id).');
        }

        return $back->with('success', count($boards) === 1 ? 'Tableros actualizados: 1 tablero.' : 'Tableros actualizados: '.count($boards).' tableros.');
    }

    /**
     * Datos de la cuenta de TikTok para el compositor (Content Sharing Guidelines de TikTok): nombre del creador, visibilidades que
     * admite, interacciones que tiene desactivadas y duracion maxima. Se consultan al abrir el compositor, no se guardan.
     */
    public function tiktokCreator(Brand $brand, SocialAccount $account, TikTokPublisher $publisher): JsonResponse
    {
        $this->authorize('update', $brand);
        $this->authorize('disconnect', $account);
        abort_unless($account->brand_id === $brand->id && $account->network === Network::TikTok, 404);

        try {
            $info = $publisher->creatorInfo($account);
        } catch (ConnectorException $e) {
            // Por ejemplo `spam_risk_too_many_posts` o la cuenta sin permiso: el compositor no deja publicar y explica por que.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'nickname' => $info['creator_nickname'] ?? $account->name,
            'username' => $info['creator_username'] ?? $account->username,
            'avatar_url' => $info['creator_avatar_url'] ?? null,
            'privacy_level_options' => array_values(array_intersect(
                TikTokPublisher::PRIVACY_LEVELS, (array) ($info['privacy_level_options'] ?? []),
            )),
            'comment_disabled' => (bool) ($info['comment_disabled'] ?? false),
            'duet_disabled' => (bool) ($info['duet_disabled'] ?? false),
            'stitch_disabled' => (bool) ($info['stitch_disabled'] ?? false),
            'max_video_post_duration_sec' => isset($info['max_video_post_duration_sec']) ? (int) $info['max_video_post_duration_sec'] : null,
        ]);
    }

    /**
     * Busca lugares de Meta para `location_id` (Instagram) y `place` (Facebook) desde el compositor. Con la busqueda restringida o sin
     * permisos responde 422 con el motivo: el compositor deja pegar el id a mano.
     */
    public function places(Request $request, Brand $brand, SocialAccount $account, PlaceSearch $search): JsonResponse
    {
        $this->authorize('update', $brand);
        $this->authorize('disconnect', $account);
        abort_unless($account->brand_id === $brand->id && in_array($account->network, [Network::Instagram, Network::Facebook], true), 404);
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);

        try {
            return response()->json(['places' => $search->search($account, $q)]);
        } catch (PlaceSearchUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
