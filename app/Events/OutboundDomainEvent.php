<?php

namespace App\Events;

use Illuminate\Support\Str;

/** Hechos escalares internos, sin modelos serializados ni credenciales de proveedores. */
final readonly class OutboundDomainEvent
{
    public string $id;

    public string $occurredAt;

    /** @param array<string, int|string|null> $data */
    public function __construct(public int $ownerId, public int $brandId, public string $name, public array $data)
    {
        $this->id = (string) Str::uuid();
        $this->occurredAt = now()->utc()->toIso8601String();
    }
}
