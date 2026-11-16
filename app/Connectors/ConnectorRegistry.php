<?php

namespace App\Connectors;

use App\Connectors\Contracts\CommentSource;
use App\Connectors\Contracts\Connector;
use App\Connectors\Contracts\FirstCommenter;
use App\Connectors\Contracts\HidesComments;
use App\Connectors\Contracts\InsightsReader;
use App\Connectors\Contracts\Publisher;
use App\Connectors\Exceptions\ConnectorException;

class ConnectorRegistry
{
    /** @var array<string, Connector> */
    private array $connectors = [];

    /** @var array<string, Publisher> */
    private array $publishers = [];

    /** @var array<string, InsightsReader> */
    private array $insights = [];

    /** @var array<string, CommentSource> */
    private array $comments = [];

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

    /** Lector de metricas de una red (T-32, T-33, T-34). */
    public function registerInsights(Network $network, InsightsReader $reader): static
    {
        $this->insights[$network->value] = $reader;

        return $this;
    }

    /** Fuente de comentarios de una red para el inbox (T-43): Instagram/Facebook, Threads, Bluesky y YouTube; el orden de registro es el del inbox. */
    public function registerComments(Network $network, CommentSource $source): static
    {
        $this->comments[$network->value] = $source;

        return $this;
    }

    public function commentsFor(Network $network): ?CommentSource
    {
        return $this->comments[$network->value] ?? null;
    }

    /**
     * Redes que el inbox lee: las que tienen una `CommentSource` registrada.
     *
     * @return list<Network>
     */
    public function commentNetworks(): array
    {
        return array_map(Network::from(...), array_keys($this->comments));
    }

    /** ¿Deja la red ocultar comentarios? Si su fuente implementa `HidesComments`. */
    public function canHideComments(Network $network): bool
    {
        return ($this->comments[$network->value] ?? null) instanceof HidesComments;
    }

    public function insightsFor(Network $network): ?InsightsReader
    {
        return $this->insights[$network->value] ?? null;
    }

    public function publisherFor(Network $network): ?Publisher
    {
        return $this->publishers[$network->value] ?? null;
    }

    /** Quien puede dejar el primer comentario en esa red: el publicador, si implementa `FirstCommenter` (TikTok no). */
    public function commenterFor(Network $network): ?FirstCommenter
    {
        $publisher = $this->publishers[$network->value] ?? null;

        return $publisher instanceof FirstCommenter ? $publisher : null;
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
