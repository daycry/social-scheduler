<?php

namespace App\Connectors\Contracts;

use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Exceptions\ConnectorException;

/** Proveedor que se conecta por OAuth (redireccion y callback): Meta, Google y TikTok. */
interface OAuthConnector extends Connector
{
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
}
