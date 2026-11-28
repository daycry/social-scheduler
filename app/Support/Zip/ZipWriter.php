<?php

namespace App\Support\Zip;

use Closure;

/**
 * Único escritor ZIP de la aplicación (exportación completa de datos y paquetes PPTX del Estudio). No depende de `ext-zip` (solo de zlib
 * para comprimir) y escribe en streaming: nada se carga entero en memoria.
 *
 *  - `addStream`/`addString` comprimen con deflate sobre la marcha y llevan descriptor de datos, así que no hace falta conocer el tamaño.
 *    Con el tamaño conocido y pequeño la entrada es ZIP clásico (lo lee cualquier descompresor, también los antiguos); con el tamaño
 *    desconocido o grande se marca ZIP64 (campo extra y descriptor de 8 bytes) y admite ficheros de más de 4 GiB.
 *  - `addString(..., compress: false)` y `addFile` guardan la entrada SIN comprimir (método 0) con CRC y tamaño reales en la cabecera, como
 *    exigen algunos formatos (PPTX).
 *  - El directorio central usa campos ZIP64 solo en las entradas que los necesitan, y el final del archivo añade el registro y el localizador
 *    ZIP64 cuando hay 65 535 entradas o más o el directorio central no cabe en 32 bits (APPNOTE 4.3.14 y 4.5.3).
 *
 * `$zip64At` es el umbral de 32 bits (0xFFFFFFFF); solo los tests lo bajan para ejercitar las rutas ZIP64 sin escribir 4 GiB.
 */
final class ZipWriter
{
    /** @var resource */
    private $handle;

    /** @var list<array{name: string, flags: int, method: int, crc: int, compressed: int, size: int, offset: int, version: int}> */
    private array $entries = [];

    private int $offset = 0;

    private int $dosTime;

    private int $dosDate;

    /**
     * @param  resource  $handle  Flujo de escritura del ZIP.
     * @param  int|null  $maxBytes  Tope del tamaño del ZIP; al superarlo lanza `ZipTooLarge`.
     * @param  Closure|null  $onProgress  Se llama tras cada bloque de 1 MiB escrito (latido del llamador).
     */
    public function __construct($handle, private readonly ?int $maxBytes = null, private readonly ?Closure $onProgress = null, private readonly int $zip64At = 0xFFFFFFFF)
    {
        $this->handle = $handle;
        $now = getdate();
        $this->dosTime = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
        $this->dosDate = ((max(1980, $now['year']) - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];
    }

    /**
     * @param  resource  $source
     * @param  int|null  $size  Tamaño conocido de `$source`; sin él la entrada va como ZIP64.
     * @return array{size: int, sha256: string}
     */
    public function addStream(string $name, $source, ?int $size = null): array
    {
        $zip64 = $size === null || ($size * 1.001) + 4096 >= $this->zip64At;
        $offset = $this->offset;
        $this->write($zip64
            ? pack('VvvvvvVVVvv', 0x04034B50, 45, 0x0808, 8, $this->dosTime, $this->dosDate, 0, 0xFFFFFFFF, 0xFFFFFFFF, strlen($name), 20).$name.pack('vvPP', 1, 16, 0, 0)
            : pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0808, 8, $this->dosTime, $this->dosDate, 0, 0, 0, strlen($name), 0).$name);
        $deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => 1]);
        $crc = hash_init('crc32b');
        $sha = hash_init('sha256');
        $written = 0;
        $compressed = 0;
        while (! feof($source)) {
            $chunk = fread($source, 1_048_576);
            if ($chunk === false) {
                throw new ZipSourceFailed('No se pudo leer un fichero para el ZIP.');
            }
            if ($chunk === '') {
                continue;
            }
            hash_update($crc, $chunk);
            hash_update($sha, $chunk);
            $written += strlen($chunk);
            $compressed += $this->write((string) deflate_add($deflate, $chunk, ZLIB_NO_FLUSH));
            ($this->onProgress)?->__invoke();
        }
        $compressed += $this->write((string) deflate_add($deflate, '', ZLIB_FINISH));
        if (! $zip64 && ($written >= $this->zip64At || $compressed >= $this->zip64At)) {
            throw new ZipSourceFailed('Un fichero ha crecido mientras se empaquetaba.');
        }
        $crcValue = (int) hexdec(hash_final($crc));
        $this->write($zip64 ? pack('VVPP', 0x08074B50, $crcValue, $compressed, $written) : pack('VVVV', 0x08074B50, $crcValue, $compressed, $written));
        $this->entries[] = ['name' => $name, 'flags' => 0x0808, 'method' => 8, 'crc' => $crcValue, 'compressed' => $compressed, 'size' => $written, 'offset' => $offset, 'version' => $zip64 ? 45 : 20];

        return ['size' => $written, 'sha256' => hash_final($sha)];
    }

    /** @return array{size: int, sha256: string} */
    public function addString(string $name, string $content, bool $compress = true): array
    {
        if (! $compress) {
            $this->store($name, (int) hexdec(hash('crc32b', $content)), strlen($content), fn () => $this->write($content));

            return ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
        }
        $stream = fopen('php://temp/maxmemory:8388608', 'w+b');
        fwrite($stream, $content);
        rewind($stream);
        try {
            return $this->addStream($name, $stream, strlen($content));
        } finally {
            fclose($stream);
        }
    }

    /** Guarda un fichero local sin comprimir (CRC con `hash_file`, copia en bloques). */
    public function addFile(string $name, string $path): void
    {
        $size = @filesize($path);
        $crc = @hash_file('crc32b', $path);
        $source = @fopen($path, 'rb');
        if ($size === false || $crc === false || ! is_resource($source)) {
            throw new ZipSourceFailed('No se pudo leer un fichero para empaquetarlo.');
        }
        try {
            $this->store($name, (int) hexdec($crc), $size, function () use ($source): void {
                while (! feof($source)) {
                    $chunk = fread($source, 1_048_576);
                    if ($chunk === false) {
                        throw new ZipSourceFailed('No se pudo leer un fichero para empaquetarlo.');
                    }
                    $this->write($chunk);
                }
            });
        } finally {
            fclose($source);
        }
    }

    /** Escribe el directorio central y el final del archivo; devuelve el tamaño total del ZIP. */
    public function finish(): int
    {
        $start = $this->offset;
        foreach ($this->entries as $entry) {
            $this->write($this->centralRecord($entry));
        }
        $count = count($this->entries);
        $size = $this->offset - $start;
        if ($count >= 0xFFFF || $start >= $this->zip64At || $size >= $this->zip64At) {
            $recordAt = $this->offset;
            $this->write(pack('VPvvVVPPPP', 0x06064B50, 44, 45, 45, 0, 0, $count, $count, $size, $start));
            $this->write(pack('VVPV', 0x07064B50, 0, $recordAt, 1));
            $this->write(pack('VvvvvVVv', 0x06054B50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0));
        } else {
            $this->write(pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, $size, $start, 0));
        }

        return $this->offset;
    }

    /** @param  Closure(): void  $body  Escribe el contenido tal cual. */
    private function store(string $name, int $crc, int $size, Closure $body): void
    {
        $big = $size >= $this->zip64At;
        $offset = $this->offset;
        $extra = $big ? pack('vvPP', 1, 16, $size, $size) : '';
        $field = $big ? 0xFFFFFFFF : $size;
        $this->write(pack('VvvvvvVVVvv', 0x04034B50, $big ? 45 : 20, 0x0800, 0, $this->dosTime, $this->dosDate, $crc, $field, $field, strlen($name), strlen($extra)).$name.$extra);
        $body();
        $this->entries[] = ['name' => $name, 'flags' => 0x0800, 'method' => 0, 'crc' => $crc, 'compressed' => $size, 'size' => $size, 'offset' => $offset, 'version' => $big ? 45 : 20];
    }

    /** @param  array{name: string, flags: int, method: int, crc: int, compressed: int, size: int, offset: int, version: int}  $entry */
    private function centralRecord(array $entry): string
    {
        $extra = '';
        $fields = [];
        foreach (['size', 'compressed', 'offset'] as $key) {
            if ($entry[$key] >= $this->zip64At) {
                $fields[$key] = 0xFFFFFFFF;
                $extra .= pack('P', $entry[$key]);
            } else {
                $fields[$key] = $entry[$key];
            }
        }
        $extra = $extra === '' ? '' : pack('vv', 1, strlen($extra)).$extra;

        return pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 45, $extra === '' ? $entry['version'] : 45, $entry['flags'], $entry['method'], $this->dosTime, $this->dosDate, $entry['crc'], $fields['compressed'], $fields['size'], strlen($entry['name']), strlen($extra), 0, 0, 0, 0, $fields['offset']).$entry['name'].$extra;
    }

    private function write(string $bytes): int
    {
        $length = strlen($bytes);
        if ($this->maxBytes !== null && $this->offset + $length > $this->maxBytes) {
            throw new ZipTooLarge('El archivo supera el tamaño máximo permitido.');
        }
        if ($length > 0 && @fwrite($this->handle, $bytes) !== $length) {
            throw new ZipWriteFailed('No se pudo escribir el ZIP (¿disco lleno?).');
        }
        $this->offset += $length;

        return $length;
    }
}
