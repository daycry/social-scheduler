<?php

namespace App\Connectors\Data;

use App\Enums\ContentType;
use Carbon\CarbonInterface;

/**
 * Contenido normalizado que se entrega al conector. Contiene URLs firmadas: no loguear ni serializar al navegador.
 */
final class PublishRequest
{
    /**
     * @param  list<PublishMedia>  $media  En orden de publicacion.
     * @param  array<string, mixed>  $options  Opciones por destino (p. ej. enlace, comentario).
     * @param  ?string  $reference  Referencia externa ya creada (contenedor de IG, publish_id) si la hay.
     * @param  ?CarbonInterface  $since  Cuando empezo el intento (para localizar lo ya publicado).
     * @param  array<string, mixed>  $progress  Progreso reanudable de publicaciones de varios pasos (carrusel de IG).
     * @param  ?\Closure  $checkpoint  `fn(?string $reference, ?array $progress)`: persiste la referencia/progreso EN EL ACTO,
     *                                 antes del siguiente paso irreversible (no perder un contenedor si el siguiente paso falla).
     */
    public function __construct(
        public readonly int $targetId,
        public readonly ContentType $type,
        public readonly ?string $text,
        public readonly array $media,
        public readonly array $options = [],
        public readonly ?string $reference = null,
        public readonly ?CarbonInterface $since = null,
        public readonly array $progress = [],
        private readonly ?\Closure $checkpoint = null,
    ) {}

    /** Guarda ya la referencia externa y/o el progreso (si quien llama lo permite). */
    public function checkpoint(?string $reference, ?array $progress = null): void
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($reference, $progress);
        }
    }

    public function firstMedia(): ?PublishMedia
    {
        return $this->media[0] ?? null;
    }
}
