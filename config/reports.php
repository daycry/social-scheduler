<?php

/*
| Informes PDF/CSV (Fase 8).
|
| Driver de PDF (T-39): DomPDF (PHP puro, sin binarios de sistema) por defecto. Chromium/Browsershot se descarto
| para el VPS HestiaCP: exige instalar Chromium + Node/Puppeteer (cientos de MB de RAM por render y dependencias
| de sistema fragiles). El renderizado esta detras de App\Services\Reports\PdfRenderer para poder cambiar de driver
| sin tocar el resto. Los graficos se dibujan con barras HTML/CSS (sin JS ni imagenes remotas).
*/
return [
    'driver' => env('REPORTS_PDF_DRIVER', 'dompdf'),

    'queue' => env('REPORTS_QUEUE', 'reports'),

    // Presupuesto del job (s). Debe quedar por debajo del --timeout del worker (120 s).
    'timeout' => 100,

    // Dias que se conservan los ficheros generados antes de poder purgarse.
    'retention_days' => 30,

    // Minutos de validez del enlace firmado de descarga.
    'download_minutes' => 15,

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
