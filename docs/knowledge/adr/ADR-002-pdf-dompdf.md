---
id: ADR-002
titulo: PDF de informes con DomPDF (PHP puro) en lugar de Chromium/Browsershot
estado: propuesta
fecha: 2026-10-20
iniciativa: 2026-10-19-social-scheduler
---

# ADR-002: PDF de informes con DomPDF (PHP puro) en lugar de Chromium/Browsershot

## Contexto

Los informes por marca y rango (Fase 8, T-38..T-41) se exportan a PDF y CSV. El diseño dejó el driver de PDF sin fijar (`spatie/laravel-pdf` + Browsershot/Chromium, o DomPDF con gráficos rasterizados). El despliegue es un VPS HestiaCP (ver [ADR-001](ADR-001-stack-laravel-nativo-hestia.md)) sin Docker: instalar Chromium + Node/Puppeteer añade cientos de MB de RAM por render y dependencias de sistema frágiles que Hestia no gestiona. Fuente: [`docs/roadmap/2026-10-19-social-scheduler/design.md`](../../roadmap/2026-10-19-social-scheduler/design.md) (fila «PDF/CSV» y pendiente C-05).

## Decisión

Se usa `barryvdh/laravel-dompdf` ^3.1 (dompdf 3.x) como driver por defecto, configurable con `REPORTS_PDF_DRIVER`. El render está detrás de `App\Services\Reports\PdfRenderer`, que es el único punto de enchufe para otro driver (hoy solo `dompdf`). Los gráficos son barras HTML/CSS (sin JS ni imágenes remotas); el periodo largo (> 60 días) se agrega por semana para que quepa en A4. Se mide un pico de ~54 MB y ~0,4 s para el peor caso (366 días, 4 redes). El job corre en la cola `reports` con worker único, lo que acota la memoria total.

Limitaciones asumidas: CSS 2.1 (tablas, sin flex/grid) y fuente embebida DejaVu Sans, que no trae emojis; los emojis se eliminan de los textos del PDF (el CSV los conserva).

## Alternativas descartadas

- **`spatie/laravel-pdf` + Browsershot (Chromium headless)** — máxima fidelidad CSS y gráficos vectoriales, pero exige Chromium + Node/Puppeteer en el VPS, mucha más RAM por render y un binario de sistema que romper en cada actualización.
- **Servicio externo de PDF** — añade un tercero que recibiría datos de las marcas y una dependencia de red.

## Consecuencias

Cero dependencias de sistema nuevas y render predecible en memoria. El diseño del PDF queda limitado a lo que dompdf soporta. Si se necesita más fidelidad, se añade un driver Chromium en `PdfRenderer` sin tocar `ReportService`. El diseño por defecto (secciones y branding) sigue pendiente de validar por el usuario (M-03).

## Estado

`propuesta` — validar por la revisión de dos lentes o el usuario en la puerta. Pasa a `aceptada` cuando se valida; a `obsoleta` si una decisión posterior la reemplaza (enlaza aquí la que la sustituye, nunca se borra el rastro).
