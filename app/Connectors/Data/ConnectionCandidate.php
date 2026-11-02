<?php

namespace App\Connectors\Data;

use App\Connectors\Network;
use Carbon\CarbonInterface;

/**
 * Cuenta conectable devuelta por un proveedor tras el OAuth (aun sin guardar).
 * Contiene tokens: no debe serializarse hacia el navegador ni a logs.
 */
final class ConnectionCandidate
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $meta  Datos especificos de la red (page_id, opciones...).
     * @param  ?string  $secret  Credencial larga del usuario (app password de Bluesky): se guarda cifrada, nunca sale en toPublicArray.
     */
    public function __construct(
        public readonly Network $network,
        public readonly string $externalId,
        public readonly string $name,
        public readonly ?string $username,
        public readonly string $accessToken,
        public readonly ?string $refreshToken = null,
        public readonly ?CarbonInterface $tokenExpiresAt = null,
        public readonly ?CarbonInterface $refreshExpiresAt = null,
        public readonly ?CarbonInterface $dataAccessExpiresAt = null,
        public readonly array $scopes = [],
        public readonly array $meta = [],
        public readonly ?string $secret = null,
    ) {}

    /** Clave estable usada para elegir candidatos en la UI. */
    public function key(): string
    {
        return $this->network->value.':'.$this->externalId;
    }

    /** Representacion segura para el navegador (sin tokens). */
    public function toPublicArray(): array
    {
        return [
            'key' => $this->key(),
            'network' => $this->network->value,
            'network_label' => $this->network->label(),
            'name' => $this->name,
            'username' => $this->username,
            // Permisos que faltan para publicar (p. ej. instagram_content_publish): aviso, no bloqueo.
            'missing_scopes' => $this->meta['missing_scopes'] ?? [],
        ];
    }
}
