<?php

/*
| Informes PDF/CSV (Fase 8).
|
| Driver de PDF (T-39): DomPDF (PHP puro, sin binarios de sistema) por defecto. Chromium/Browsershot se descarto
| para el VPS HestiaCP: exige instalar Chromium + Node/Puppeteer (cientos de MB de RAM por render y dependencias
| de sistema fragiles). El renderizado esta detras de App\Services\Reports\PdfRenderer para poder cambiar de driver
| sin tocar el resto. Los graficos se dibujan con barras HTML/CSS (sin JS ni imagenes remotas).
| Decision: docs/knowledge/adr/ADR-002-pdf-dompdf.md. La fuente (DejaVu Sans) no trae emojis: en el PDF se eliminan.
*/
return [
    'driver' => env('REPORTS_PDF_DRIVER', 'dompdf'),

    'queue' => env('REPORTS_QUEUE', 'reports'),

    // Presupuesto del job (s). Debe quedar por debajo del --timeout del worker (120 s).
    'timeout' => 100,

    // Dias que se conservan los informes (fila + fichero); `reports:prune` (diario) los purga pasado el plazo.
    'retention_days' => 30,

    // Horas tras las que un informe `pending`/`processing` se considera atascado: deja de contar para el limite y
    // `reports:prune` lo marca `failed`.
    'stuck_hours' => 2,

    // Informes en curso (pending/processing) permitidos por usuario a la vez.
    'max_active' => 5,

    // Minutos de validez del enlace firmado de descarga (solo firma, sin sesion: lo usan tambien los agentes MCP).
    'download_minutes' => 5,

    // CSV: `es` (Excel es-ES) separa con `;` y usa coma decimal; `en` separa con `,` y usa punto decimal.
    // Las celdas de texto van siempre entre comillas y las que empiezan por = + - @ se neutralizan (CWE-1236).
    'csv_locale' => env('REPORTS_CSV_LOCALE', 'es'),

    // Por encima de estos dias, el grafico diario del PDF se agrega por semana (cabe en A4).
    'chart_max_daily_points' => 60,

    // Publicaciones destacadas por red en el informe.
    'top_posts' => 5,

    // PENDIENTE DE VALIDAR POR EL USUARIO (M-03): secciones y branding por defecto.
    // Secciones: cover (portada), summary (resumen KPI con comparacion), networks (desglose por red),
    // top_posts (publicaciones destacadas), notes (notas de metricas "no disponible").
    'sections' => ['cover', 'summary', 'networks', 'top_posts', 'notes'],

    'branding' => [
        'color' => env('REPORTS_BRAND_COLOR', '#4f46e5'),
        // Ruta absoluta a un PNG/JPG local para la portada (opcional). Sin logo se usa el nombre de la app.
        'logo' => env('REPORTS_LOGO_PATH'),
        'footer' => env('REPORTS_FOOTER', ''),
    ],
];
