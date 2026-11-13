<?php

/*
| Copias de seguridad (A-03). Las genera `deploy/backup/backup.sh`; la app solo comprueba su frescura
| (`app:backup-verify`, y `app:health` si BACKUP_HEALTH=true).
*/
return [
    // Carpeta local en la que `backup.sh` deja los respaldos y la marca `last-backup` (mismo valor que BACKUP_DIR del script).
    'dir' => env('BACKUP_DIR') ?: storage_path('backups'),

    // Horas tras las que el ultimo respaldo se considera viejo.
    'max_age_hours' => (int) (env('BACKUP_MAX_AGE_HOURS') ?: 26),

    // Incluye el aviso de frescura en `app:health` (WARN). Activalo cuando el cron de backup.sh exista.
    'health' => (bool) env('BACKUP_HEALTH', false),
];
