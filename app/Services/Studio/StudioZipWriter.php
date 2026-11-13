<?php

namespace App\Services\Studio;

/**
 * Escritor ZIP minimo (entradas sin comprimir, sin ZIP64) para los paquetes que genera el estudio (PPTX). No depende de `ext-zip`, que el
 * servidor puede no tener (el empaquetado de paginas ya cae a Python por eso). Los ficheros se copian en streaming: el CRC se calcula con
 * `hash_file` y nunca se carga una imagen entera en memoria. El nombre y la fecha de cada entrada los fija el llamador.
 */
final class StudioZipWriter
{
    private const MAX_BYTES = 4_000_000_000;

    /** @var resource */
    private $handle;

    /** @var list<array{name: string, crc: int, size: int, offset: int}> */
    private array $entries = [];

    private int $offset = 0;

    private int $dosTime;

    private int $dosDate;

    public function __construct(string $path)
    {
        $handle = fopen($path, 'wb');
        if (! is_resource($handle)) {
            throw new StudioRenderFailure('No se pudo crear el paquete de la exportación.');
        }
        $this->handle = $handle;
        $now = getdate();
        $this->dosTime = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
        $this->dosDate = ((max(1980, $now['year']) - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];
    }

    public function addString(string $name, string $content): void
    {
        $this->header($name, (int) hexdec(hash('crc32b', $content)), strlen($content));
        $this->write($content);
    }

    public function addFile(string $name, string $path): void
    {
        $size = filesize($path);
        $crc = hash_file('crc32b', $path);
        $source = fopen($path, 'rb');
        if ($size === false || $crc === false || ! is_resource($source)) {
            throw new StudioRenderFailure('No se pudo leer una imagen renderizada para empaquetarla.');
        }
        try {
            $this->header($name, (int) hexdec($crc), $size);
            while (! feof($source)) {
                $chunk = fread($source, 1_048_576);
                if ($chunk === false) {
                    throw new StudioRenderFailure('No se pudo leer una imagen renderizada para empaquetarla.');
                }
                $this->write($chunk);
            }
        } finally {
            fclose($source);
        }
    }

    public function close(): void
    {
        $start = $this->offset;
        foreach ($this->entries as $entry) {
            $this->write(pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0x0800, 0, $this->dosTime, $this->dosDate, $entry['crc'], $entry['size'], $entry['size'], strlen($entry['name']), 0, 0, 0, 0, 0, $entry['offset']).$entry['name']);
        }
        $count = count($this->entries);
        $this->write(pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, $this->offset - $start, $start, 0));
        fclose($this->handle);
    }

    private function header(string $name, int $crc, int $size): void
    {
        if (count($this->entries) >= 65000 || $size >= self::MAX_BYTES || $this->offset + $size >= self::MAX_BYTES) {
            throw new StudioRenderFailure('El paquete supera el tamaño que admite la exportación. Exporta menos páginas.');
        }
        $this->entries[] = ['name' => $name, 'crc' => $crc, 'size' => $size, 'offset' => $this->offset];
        $this->write(pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, 0, $this->dosTime, $this->dosDate, $crc, $size, $size, strlen($name), 0).$name);
    }

    private function write(string $bytes): void
    {
        if ($bytes !== '' && fwrite($this->handle, $bytes) !== strlen($bytes)) {
            throw new StudioRenderFailure('No se pudo escribir el paquete de la exportación (¿disco lleno?).');
        }
        $this->offset += strlen($bytes);
    }
}
