<?php

namespace App\Http\Controllers\Mcp\OAuth;

use App\Http\Controllers\Controller;
use App\Mcp\Abilities;
use App\Services\AuditLog;
use App\Services\Mcp\OAuth\AuthorizationServer;
use App\Services\Mcp\OAuth\OAuthException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pantalla de consentimiento de /oauth/authorize (sesion web + CSRF + `auth`). El GET no tiene efectos laterales;
 * el POST aprueba o deniega y vuelve al cliente con `code` o `error`. Un client_id o redirect_uri invalidos NUNCA redirigen.
 */
class AuthorizeController extends Controller
{
    public function show(Request $request, AuthorizationServer $server): Response
    {
        try {
            $req = $server->validateAuthorize($request->query());
        } catch (OAuthException $e) {
            return $this->failure($request, $e);
        }

        $host = parse_url($req->redirectUri, PHP_URL_HOST);
        $port = parse_url($req->redirectUri, PHP_URL_PORT);

        return $this->page($request, [
            'client' => ['name' => $req->client->name],
            'redirectHost' => $host.($port ? ':'.$port : ''),
            'params' => $req->toParams(),
            'abilities' => Abilities::all(),
            'presets' => Abilities::presets(),
            'defaultPreset' => 'read',
            'tokenDayOptions' => self::dayOptions(),
            'defaultTokenDays' => (int) config('services.mcp.oauth.token_days'),
            'error' => null,
        ]);
    }

    public function decide(Request $request, AuthorizationServer $server, AuditLog $audit): Response
    {
        try {
            $req = $server->validateAuthorize($request->input());
        } catch (OAuthException $e) {
            return $this->failure($request, $e);
        }

        $user = $request->user();

        if ($request->input('decision') !== 'approve') {
            $audit->record($user, 'oauth.denied', AuditLog::DENIED, 'oauth_client', null, 'client_id='.$req->client->id, 'oauth');

            return $this->away($this->redirectWith($req->redirectUri, ['error' => 'access_denied', 'state' => $req->state]));
        }

        $data = $request->validate([
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(Abilities::keys())],
            // Solo cuenta la duracion elegida aqui (nunca la query); fuera de la lista es un error, no se recorta.
            'token_days' => ['nullable', 'integer', Rule::in(self::dayOptions())],
        ]);
        $abilities = array_values(array_unique($data['abilities']));

        $code = $server->issueCode($user, $req, $abilities, isset($data['token_days']) ? (int) $data['token_days'] : null);
        $audit->record($user, 'oauth.authorized', AuditLog::OK, 'oauth_client', null, 'client_id='.$req->client->id.';abilities='.implode(',', $abilities), 'oauth');

        return $this->away($this->redirectWith($req->redirectUri, ['code' => $code, 'state' => $req->state]));
    }

    /** @return list<int> */
    private static function dayOptions(): array
    {
        return array_values(array_map('intval', (array) config('services.mcp.oauth.token_day_options', [30])));
    }

    /** @param  array<string, string|null>  $params */
    private function redirectWith(string $uri, array $params): string
    {
        return AuthorizationServer::appendQuery($uri, array_filter($params, fn ($v) => $v !== null));
    }

    /** Redirect a un destino externo; las visitas Inertia necesitan una redireccion de ventana completa (409). */
    private function away(string $url): Response
    {
        return Inertia::location($url);
    }

    private function failure(Request $request, OAuthException $e): Response
    {
        if (! $e->isFatal()) {
            return $this->away($e->redirectUrl());
        }

        return $this->page($request, ['error' => ['code' => $e->error, 'message' => $e->description]], 400);
    }

    /** @param  array<string, mixed>  $props */
    private function page(Request $request, array $props, int $status = 200): Response
    {
        $response = Inertia::render('OAuth/Authorize', $props)->toResponse($request);

        // Anti-clickjacking: prevalece sobre el X-Frame-Options: SAMEORIGIN de Nginx (CA-18).
        return $response->setStatusCode($status)->withHeaders([
            'Content-Security-Policy' => "frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
