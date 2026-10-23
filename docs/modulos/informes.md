# Informes PDF y CSV

Informes por marca y rango de fechas, con comparación opcional. Se generan en la cola `reports`.

## Piezas

| Pieza | Ruta |
|---|---|
| Servicio | `app/Services/ReportService.php` (`enqueue`, `create`, `generate`, `dataset`, `csv`) |
| Render PDF | `app/Services/Reports/PdfRenderer.php` |
| Job | `app/Jobs/GenerateReport.php` |
| Purga | `app/Console/Commands/PruneReports.php` (`reports:prune`, diario 04:10) |
| Controlador y vista | `ReportController`, `resources/js/Pages/Reports/Index.vue` |
| Modelo / política | `app/Models/Report.php`, `app/Policies/ReportPolicy.php` |
| Configuración | `config/reports.php` |

## Decisión de driver

DomPDF 3.x en PHP puro, sin Chromium ni Node en el VPS ([ADR-002](../knowledge/adr/ADR-002-pdf-dompdf.md)). Medido: pico ~54 MB y ~0,4 s en el peor caso (366 días, 4 redes). Limitaciones: CSS 2.1 sin flex/grid y fuente DejaVu Sans sin emojis (se eliminan en el PDF; el CSV los conserva). `PdfRenderer` es el punto de enchufe para otro driver (`REPORTS_PDF_DRIVER`).

Los gráficos son barras HTML/CSS. Con más de 60 días (`chart_max_daily_points`) el gráfico diario se agrega por semana.

## Contenido

Secciones por defecto (`reports.sections`): `cover`, `summary` (KPI con comparación), `networks`, `top_posts` (5 por red) y `notes` (métricas "no disponible"). Branding: `REPORTS_BRAND_COLOR`, `REPORTS_LOGO_PATH`, `REPORTS_FOOTER`. ⚠️ Secciones y diseño están pendientes de aprobación del usuario (M-03).

CSV en formato largo con cabecera `seccion,red,elemento,metrica,fecha,valor,anterior,variacion_pct`, UTF-8 con BOM. `null` se escribe "no disponible", nunca 0. Las celdas de texto van siempre entre comillas y `ReportService::cell()` neutraliza las que empiezan por `= + - @` (CWE-1236). Con `REPORTS_CSV_LOCALE=es` separa con `;` y usa coma decimal; con `en`, `,` y punto.

## Límites y ciclo de vida

| Parámetro | Valor |
|---|---|
| Informes activos por usuario | 5 (`max_active`) |
| Retención | 30 días (`retention_days`) |
| Informe atascado | 2 h (`stuck_hours`), luego `failed` |
| Enlace de descarga | firmado, 5 min (`download_minutes`) |

## Descarga

La interfaz pide un enlace nuevo con `GET reports/{report}/link`. La descarga `reports/{report}/download/{owner}` va solo con firma, sin sesión, para que la use también un agente MCP. La firma cubre informe y dueño; el controlador comprueba que coinciden y que el informe está `ready`, y audita el acceso.
