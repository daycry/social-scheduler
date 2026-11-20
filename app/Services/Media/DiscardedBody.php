<?php

namespace App\Services\Media;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Destino (`sink`) de Guzzle que acepta y descarta el cuerpo de la respuesta: nada se guarda en memoria ni en disco.
 * Sirve con el manejador cURL, que es el que aplica la IP fijada de UrlGuard (`stream => true` lo sustituiría).
 */
final class DiscardedBody implements StreamInterface
{
    private int $size = 0;

    public function write(string $string): int
    {
        $this->size += strlen($string);

        return strlen($string);
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function tell(): int
    {
        return $this->size;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('El cuerpo descartado no admite seek.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('El cuerpo descartado no admite rewind.');
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function isReadable(): bool
    {
        return false;
    }

    public function read(int $length): string
    {
        throw new RuntimeException('El cuerpo descartado no se puede leer.');
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
