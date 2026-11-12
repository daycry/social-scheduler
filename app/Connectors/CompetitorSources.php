<?php

namespace App\Connectors;

use App\Connectors\Contracts\CompetitorSource;
use InvalidArgumentException;

/**
 * Las fuentes de competidores (una por red) detras del contrato `CompetitorSource`. Solo se ofrecen las redes disponibles en esta
 * instalacion (`Network::isAvailable()`: Bluesky se apaga con `BLUESKY_ENABLED=false`).
 */
class CompetitorSources
{
    /** @var array<string, CompetitorSource> */
    private array $sources = [];

    /** @param iterable<CompetitorSource> $sources */
    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            $this->sources[$source->network()->value] = $source;
        }
    }

    /** @throws InvalidArgumentException si la red no tiene fuente o esta deshabilitada en esta instalacion (no se consulta). */
    public function for(Network $network): CompetitorSource
    {
        return $this->has($network) ? $this->sources[$network->value] : throw new InvalidArgumentException("No hay fuente de competidores disponible para {$network->label()}.");
    }

    public function has(Network $network): bool
    {
        return isset($this->sources[$network->value]) && $network->isAvailable();
    }

    /** @return list<Network> Redes con fuente de competidores disponibles en esta instalacion, en el orden del catalogo. */
    public function networks(): array
    {
        return array_values(array_filter(Network::cases(), fn (Network $n) => $this->has($n)));
    }
}
