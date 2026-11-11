<?php

namespace App\Connectors\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Stream con el tamano FIJADO por quien lo abre. El `readStream` de un disco remoto (R2/S3) devuelve el socket HTTP: `fstat` no da
 * tamano (0) y un multipart construido sobre el se enviaria sin el contenido. Con el tamano real (de `Filesystem::size`) Guzzle
 * declara bien el `Content-Length`, que S3 exige (no admite `chunked`).
 */
class KnownSizeStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __construct(private StreamInterface $stream, private readonly int $size) {}

    public function getSize(): int
    {
        return $this->size;
    }
}
