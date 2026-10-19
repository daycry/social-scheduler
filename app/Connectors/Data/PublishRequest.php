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
     */
    public function __construct(
        public readonly int $targetId,
        public readonly ContentType $type,
        public readonly ?string $text,
        public readonly array $media,
        public readonly array $options = [],
        public readonly ?string $reference = null,
        public readonly ?CarbonInterface $since = null,
    ) {}

    public function firstMedia(): ?PublishMedia
    {
        return $this->media[0] ?? null;
    }
}
