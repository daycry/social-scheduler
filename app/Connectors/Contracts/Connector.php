<?php

namespace App\Connectors\Contracts;

use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Models\SocialAccount;

/**
 * Contrato comun de un proveedor OAuth (Meta, Google, TikTok). Cada implementacion
 * aisla las peculiaridades de su API y version; el resto de la app solo conoce esta interfaz.
 *
 * Las capacidades adicionales (publicar, sondear estado, leer insights) se declaran en
 * interfaces aparte (Publisher, StatusPoller, InsightsReader) que el conector implementa
 * cuando la red las soporta.
 */
interface Connector
{
    /** Clave del proveedor usada en las rutas: meta | google | tiktok. */
    public function provider(): string;

    /** @return list<Network> Redes que conecta este proveedor. */
    public function networks(): array;

    /**
     * URL de autorizacion a la que se redirige al usuario.
     *
     * @param  bool  $rerequest  Pedir de nuevo la seleccion de paginas/cuentas (solo Meta).
     */
    public function authorizationUrl(string $state, string $redirectUri, bool $rerequest = false): string;

    /**
     * Canjea el `code` del callback y devuelve las cuentas conectables (con sus tokens).
     *
     * @return list<ConnectionCandidate>
     *
     * @throws ConnectorException con mensaje legible para el usuario.
     */
    public function exchangeCode(string $code, string $redirectUri): array;

    /**
     * Renueva las credenciales de una cuenta existente.
     *
     * @throws ReconnectRequiredException si la renovacion ya no es posible (token revocado/caducado).
     * @throws ConnectorException fallo transitorio (red, 5xx): se reintenta en la siguiente pasada.
     */
    public function refresh(SocialAccount $account): RefreshResult;

    /** Horas de antelacion con que debe renovarse el token antes de caducar. */
    public function refreshMarginHours(): int;
}
