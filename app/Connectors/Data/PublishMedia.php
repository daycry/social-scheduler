<?php

namespace App\Connectors\Data;

/** Un archivo de media listo para que una red lo descargue (URL temporal firmada). */
final class PublishMedia
{
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $mime,
        public readonly string $url,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?float $duration = null,
        public readonly ?float $fps = null,
        public readonly ?int $size = null,
        /** Disco y ruta del fichero: permiten leerlo en streaming por trozos (YouTube, TikTok FILE_UPLOAD). */
        public readonly ?string $disk = null,
        public readonly ?string $path = null,
        /** Origen del asset (`upload`, `giphy`...): un MP4 de Giphy es un GIF y Bluesky lo publica con `presentation: gif`. */
        public readonly ?string $source = null,
    ) {}

    public function isVideo(): bool
    {
        return $this->kind === 'video';
    }
}
