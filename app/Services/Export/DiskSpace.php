<?php

namespace App\Services\Export;

/** Mide el espacio libre de un directorio y si dos directorios comparten volumen. Se inyecta en `FullExport` para poder simularlo en los tests. */
class DiskSpace
{
    /** Bytes libres del directorio, o `false` si no se pueden medir. */
    public function free(string $directory): float|int|false
    {
        return is_dir($directory) ? @disk_free_space($directory) : false;
    }

    /** ¿Están los dos directorios en el mismo dispositivo? (`rename()` entre ellos es atómico y no copia.) */
    public function sameVolume(string $a, string $b): bool
    {
        $first = @stat($a);
        $second = @stat($b);
        if ($first === false || $second === false) {
            return false;
        }
        if ($first['dev'] !== 0 || $second['dev'] !== 0) {
            return $first['dev'] === $second['dev'];
        }

        // Sin número de dispositivo (algunas plataformas): mismo volumen si empiezan por la misma unidad o raíz.
        return strtoupper(substr((string) realpath($a), 0, 3)) === strtoupper(substr((string) realpath($b), 0, 3));
    }
}
