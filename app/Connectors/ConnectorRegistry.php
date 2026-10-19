<?php

namespace App\Connectors;

use App\Connectors\Contracts\Connector;
use App\Connectors\Exceptions\ConnectorException;

class ConnectorRegistry
{
    /** @var array<string, Connector> */
    private array $connectors = [];

    public function register(Connector $connector): static
    {
        $this->connectors[$connector->provider()] = $connector;

        return $this;
    }

    public function has(string $provider): bool
    {
        return isset($this->connectors[$provider]);
    }

    public function forProvider(string $provider): Connector
    {
        return $this->connectors[$provider]
            ?? throw new ConnectorException("Proveedor de conexion desconocido: $provider.");
    }

    public function forNetwork(Network $network): Connector
    {
        return $this->forProvider($network->provider());
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->connectors);
    }
}
