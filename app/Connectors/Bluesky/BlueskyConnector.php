<?php

namespace App\Connectors\Bluesky;

use App\Connectors\Contracts\CredentialConnector;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Network;
use App\Models\SocialAccount;

/**
 * Conector de Bluesky (D1): se conecta con handle + app password (no OAuth). Guarda accessJwt/refreshJwt cifrados y la app
 * password en `credential_secret` (cifrada) para recrear la sesion; `external_id` = DID (estable aunque cambie el handle).
 */
class BlueskyConnector implements CredentialConnector
{
    public function __construct(private readonly BlueskySession $session) {}

    public function provider(): string
    {
        return 'bluesky';
    }

    public function networks(): array
    {
        return [Network::Bluesky];
    }

    public function refreshMarginHours(): int
    {
        return (int) config('social.refresh_margin_hours.bluesky', 1);
    }

    public function credentialFields(): array
    {
        return [
            ['name' => 'handle', 'label' => 'Handle de Bluesky', 'type' => 'text', 'help' => 'Por ejemplo tunombre.bsky.social'],
            ['name' => 'app_password', 'label' => 'App password', 'type' => 'password', 'help' => 'Créala en Bluesky > Ajustes > Contraseñas de aplicación. No uses tu contraseña principal.'],
        ];
    }

    public function connectWithCredentials(array $input): array
    {
        $handle = trim((string) ($input['handle'] ?? ''));
        $password = (string) ($input['app_password'] ?? '');

        if ($handle === '' || $password === '') {
            throw new ConnectorException('Escribe el handle y la app password de Bluesky.');
        }

        try {
            $opened = $this->session->open($handle, $password);
        } catch (BlueskyException $e) {
            // Nunca se propaga el mensaje del servidor tal cual si habla de credenciales; tampoco se registra la contrasena.
            throw new ConnectorException(match (true) {
                in_array($e->errorCode, ['AuthenticationRequired', 'InvalidIdentifier', 'AccountTakedown'], true) || $e->status === 401 => 'Bluesky rechazó el handle o la app password. Comprueba que usas una app password válida y no la contraseña de tu cuenta.',
                in_array($e->errorCode, ['InvalidHandle', 'HandleNotFound'], true) => 'Bluesky no reconoce ese handle.',
                $e->isTransient() => 'No se pudo conectar con Bluesky. Reintenta en unos minutos.',
                default => 'Bluesky no pudo validar la cuenta.',
            });
        }

        $session = $opened['session'];
        $access = (string) ($session['accessJwt'] ?? '');
        if ($access === '') {
            throw new ConnectorException('Bluesky no devolvió una sesión válida.');
        }

        return [new ConnectionCandidate(
            network: Network::Bluesky,
            externalId: $opened['did'],
            name: $opened['handle'],
            username: $opened['handle'],
            accessToken: $access,
            refreshToken: ($session['refreshJwt'] ?? null) ?: null,
            tokenExpiresAt: BlueskySession::jwtExpiry($access),
            refreshExpiresAt: null,
            scopes: ['atproto'],
            meta: [
                'did' => $opened['did'],
                'pds' => $opened['pds'],
                'handle' => $opened['handle'],
                'email_confirmed' => (bool) ($session['emailConfirmed'] ?? false),
            ],
            secret: $password,
        )];
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        return $this->session->refresh($account);
    }
}
