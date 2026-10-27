<?php

namespace App\Connectors\Contracts;

use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Exceptions\ConnectorException;

/** Proveedor que se conecta con credenciales escritas por el usuario (p. ej. handle + app password de Bluesky). */
interface CredentialConnector extends Connector
{
    /**
     * Campos del formulario de conexion.
     *
     * @return list<array{name: string, label: string, type: string, help?: string}>
     */
    public function credentialFields(): array;

    /**
     * Valida las credenciales contra la red y devuelve la cuenta conectable (con el secreto a guardar cifrado).
     *
     * @param  array<string, mixed>  $input
     * @return list<ConnectionCandidate>
     *
     * @throws ConnectorException con mensaje legible para el usuario; no debe incluir la contrasena.
     */
    public function connectWithCredentials(array $input): array;
}
