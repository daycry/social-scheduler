<?php

namespace App\Connectors;

use App\Connectors\Contracts\Connector;
use App\Connectors\Contracts\Publisher;
use App\Connectors\Exceptions\ConnectorException;

class ConnectorRegistry
{
    /** @var array<string, Connector> */
    private array $connectors = [];

    /** @var array<string, Publisher> */
    private array $publishers = [];

    public function register(Connector $connector): static
    {
        $this->connectors[$connector->provider()] = $connector;

        return $this;
    }

    /** Publicador de una red (T-21, T-22, T-26, T-27). */
    public function registerPublisher(Network $network, Publisher $publisher): static
    {
        $this->publishers[$network->value] = $publisher;

        return $this;
    }

    public function publisherFor(Network $network): ?Publisher
    {
        return $this->publishers[$network->value] ?? null;
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
