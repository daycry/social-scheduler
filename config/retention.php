<?php

/*
| Retencion de datos (A-08, A-07). `model:prune` (diario, routes/console.php) borra por lotes lo que supera estos plazos.
| Valores en dias salvo indicacion. `audit_logs` conserva un año por defecto: la pantalla de auditoria (F-01) la mostrara.
| Poner un plazo a 0 desactiva la poda de esa tabla.
*/
return [
    'audit_logs_days' => (int) env('RETENTION_AUDIT_LOGS_DAYS', 365),
    'publish_attempts_days' => (int) env('RETENTION_PUBLISH_ATTEMPTS_DAYS', 365),
    'notifications_days' => (int) env('RETENTION_NOTIFICATIONS_DAYS', 90),
    'studio_ai_jobs_days' => (int) env('RETENTION_STUDIO_AI_JOBS_DAYS', 90),
    'studio_image_jobs_days' => (int) env('RETENTION_STUDIO_IMAGE_JOBS_DAYS', 30),
    'canva_jobs_days' => (int) env('RETENTION_CANVA_JOBS_DAYS', 90),
    // Solo exportaciones terminadas sin fichero (fallidas o canceladas); las listas conservan su fichero y se gestionan aparte.
    'studio_design_exports_days' => (int) env('RETENTION_STUDIO_EXPORTS_DAYS', 30),
    'studio_av_steps_days' => (int) env('RETENTION_STUDIO_AV_STEPS_DAYS', 14),

    // Revisiones del estudio: se conservan las `keep_last` mas recientes de cada diseño y la ultima de cada dia hasta `daily_days`.
    'design_revisions' => [
        'keep_last' => (int) env('RETENTION_REVISIONS_KEEP_LAST', 50),
        'daily_days' => (int) env('RETENTION_REVISIONS_DAILY_DAYS', 90),
        // Horas que se conserva una revision sustituida por un autoguardado (sigue sirviendo de base para exportar) antes de podarla.
        'superseded_hours' => (int) env('RETENTION_REVISIONS_SUPERSEDED_HOURS', 24),
    ],
];
