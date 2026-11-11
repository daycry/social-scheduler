<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Frescura del ultimo respaldo: lee la marca `last-backup` que `deploy/backup/backup.sh` escribe solo si el respaldo termino bien.
 */
class BackupStatus
{
    /**
     * @return array{ok: bool, detail: string}
     */
    public function check(?string $dir = null, ?int $maxAgeHours = null, ?Carbon $now = null): array
    {
        $dir = rtrim($dir ?? (string) config('backup.dir'), '/\\');
        $max = $maxAgeHours ?? (int) config('backup.max_age_hours', 26);
        $marker = $dir.DIRECTORY_SEPARATOR.'last-backup';

        if (! is_file($marker)) {
            return ['ok' => false, 'detail' => "no hay respaldos: falta $marker (corre backup.sh?)"];
        }

        $data = [];
        foreach (file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $data[trim($key)] = trim($value);
        }

        try {
            $at = Carbon::parse($data['timestamp'] ?? '');
        } catch (Throwable) {
            return ['ok' => false, 'detail' => 'la marca last-backup no tiene una fecha valida'];
        }

        if (! isset($data['db'])) {
            return ['ok' => false, 'detail' => 'la marca last-backup no indica el volcado de la BD'];
        }

        foreach (['db', 'media'] as $kind) {
            if (! isset($data[$kind])) {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$data[$kind];
            if (! preg_match('/^[\w.-]+$/', $data[$kind]) || ! is_file($path) || filesize($path) === 0) {
                return ['ok' => false, 'detail' => "falta o esta vacio el fichero de $kind del ultimo respaldo"];
            }
        }

        $hours = (int) floor($at->diffInHours($now ?? now(), true));

        return $hours > $max
            ? ['ok' => false, 'detail' => "el ultimo respaldo tiene {$hours}h (limite {$max}h)"]
            : ['ok' => true, 'detail' => "ultimo respaldo hace {$hours}h"];
    }
}
