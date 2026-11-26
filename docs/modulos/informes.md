# Informes PDF y CSV

Informes por marca y rango de fechas, con comparación opcional. Se generan en la cola `reports`.

La selección inicial respeta la marca del contexto o `brand_id` y calcula el periodo en su zona horaria. Al generar conserva esa marca. El próximo envío y el último envío se muestran en la zona de la marca del informe programado. El historial reciente sigue siendo del usuario completo; no se presenta como una lista exclusiva de la marca seleccionada.

Si hay informes pendientes, la página consulta su estado hasta 40 veces por ciclo. Empieza a los 4 segundos y amplía la espera hasta 30. Pausa al ocultar la pestaña. Al llegar al límite, «Volver a consultar» inicia un ciclo nuevo.

## Piezas

El historial reciente se recorre por páginas y muestra el total de informes de la cuenta. La selección de marca sigue controlando la preparación de un informe nuevo.

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

La página de informes delega el resultado de la vista previa y el listado de informes recientes a sus componentes. `useReportPreview` gestiona la petición y la cancela al cambiar de marca. `ReportsRecent` refresca el listado mientras hay informes en proceso.

## Constructor de informes y envío programado (T-21)

Además del informe clásico, la pantalla **Informes** permite componer un informe con bloques, guardarlo como plantilla y programar su envío por email.

| Qué | Dónde |
|---|---|
| Bloques | `app/Services/Reports/ReportBlocks.php`: `kpis`, `evolution`, `top_posts`, `competitors`, `ads` y `text`. Hasta 20 por informe; el orden del array es el orden del PDF. El texto libre (hasta 2.000 caracteres) nunca se interpreta como HTML. Por defecto: `kpis`, `evolution`, `top_posts` |
| Plantillas | `report-templates` (`ReportTemplateController`, rutas `report-templates.store/update/destroy`): hasta 50 por usuario, nombre único por marca, con `range_days` y `compare`. Limitador `report-builder` (30/min) |
| Programaciones | `report-schedules` (`ReportScheduleController`, rutas `report-schedules.store/update/destroy`): `weekly` o `monthly`, hasta 10 por usuario y hasta 5 destinatarios (`reports.max_recipients`). La plantilla debe ser de la misma marca (si no, 404). Limitador `report-schedules` (10/min) |
| Envío | `php artisan reports:send-scheduled` (`SendScheduledReports`), programado **cada hora** (`routes/console.php`, `withoutOverlapping`). Encola `SendScheduledReport` en la cola `reports` para cada programación cuya fecha `next_run_at` ya pasó; el correo lleva el enlace de descarga firmado |
| Desuscripción | Cada correo incluye una URL firmada `report-schedules/{schedule}/unsubscribe`. El GET firmado muestra una página de confirmación (`resources/views/unsubscribe.blade.php`, la misma página de baja que usa el resumen semanal) sin efectos; solo el POST firmado da de baja al destinatario. El POST admite one-click (RFC 8058) y está excluido de CSRF (`bootstrap/app.php`). 30 peticiones/min |

Al reactivar una programación no se envía lo atrasado: `next_run_at` se recalcula desde ahora. Pruebas en `tests/Feature/Reports/`.

## Exportaciones CSV síncronas (C-03c)

Además del informe en segundo plano, hay dos descargas CSV inmediatas (`ExportController`, `app/Http/Controllers/ExportController.php`, throttle 30/min, solo con sesión):

| Ruta | Qué descarga |
|---|---|
| `GET export/analytics.csv` (`exports.analytics`) | Analítica de una marca (`brand_id`) o de todas (sin marca). Query: `from`, `to`, `compare`. |
| `GET export/posts.csv` (`exports.posts`) | Publicaciones de la vista Lista. Query: los filtros del calendario (`brand_id`, `network`, `status`, `q`, `date`) o `saved_view`. |

- **Escritor compartido.** `App\Support\CsvWriter` (extraído de `ReportService::csv()` sin cambiar su salida, lo fija `CsvRegressionTest`): UTF-8 con BOM, separador y decimal según `REPORTS_CSV_LOCALE` (`es`: `;` y coma), texto siempre entre comillas, números sin ellas y `null` como celda vacía. En las exportaciones nuevas lo que no hay es una celda vacía, nunca 0 (el informe en segundo plano sigue escribiendo «no disponible»).
- **Neutralización (CWE-1236).** Una celda de texto que empieza por `=`, `+`, `-`, `@`, TAB o CR (o cualquier tramo tras `;`, `,`, TAB o salto de línea) lleva un `'` delante. Los números no se tocan. Aplica también al nombre de la marca y al texto de las publicaciones.
- **Streaming.** Se escribe fila a fila con `response()->streamDownload`; el CSV de publicaciones recorre la consulta con `lazy(500)` y no lo carga entero en memoria.
- **Analítica.** Cabecera `marca;red;metrica;tipo;valor;anterior;variacion_pct`. `tipo` es `flujo` o `acumulado` (seguidores, vídeos, publicaciones: stocks). Una fila por métrica y red, más una por marca y métrica cuando se exporta «Todas las marcas». `anterior` y `variacion_pct` solo llevan valor si se pidió comparación.
- **Publicaciones.** Cabecera `fecha;marca;red;cuenta;tipo;estado;texto;enlace;visualizaciones;alcance;me_gusta;comentarios;compartidos;guardados;interacciones`. Una fila por destino (red) de las publicaciones que lista el calendario con ese filtro (mismo rango de la Lista, marca, red, estado y búsqueda, más los borradores sin fecha), con las métricas de su último snapshot (`AnalyticsService::withLatestSnapshot`, la misma pieza acotada que usa el dashboard: sin N+1). La fecha va en la zona horaria de la marca.
- **Tope.** `social.export.max_rows` (`SOCIAL_EXPORT_MAX_ROWS`, 5.000 filas). Por encima el CSV se corta en el tope; la Lista avisa antes de descargar (propiedad `export` con `total`, `max`, `truncated`) y la respuesta lleva `X-Export-Total` y `X-Export-Truncated`. A diferencia de la Lista en pantalla (500 publicaciones), el CSV no tiene ese límite.
- **Aislamiento.** Solo datos del usuario: una marca o una vista guardada ajena responde 404.
- **Alcance.** El CSV no tiene herramienta MCP (`generate_report` ya devuelve CSV).

## Resumen semanal (T-86)

Además de los informes en PDF, cada usuario puede recibir un **resumen semanal** de sus marcas, como aviso en la aplicación (que también llega por push si lo tiene activado) y, si tiene `notify_by_mail`, por correo. Se activa en **Notificaciones del navegador → Qué avisos quieres recibir** (`users.notify_weekly_digest`, **apagado por defecto**: es un correo recurrente que nadie ha pedido).

| Qué | Dónde |
|---|---|
| Programación | `SendWeeklyDigest` (`app/Jobs/SendWeeklyDigest.php`), cada hora en `routes/console.php` (`send-weekly-digest`, `withoutOverlapping`), solo reparte: encola un `SendUserWeeklyDigest` por usuario al que ya le toca (un usuario lento o con IA no retiene a los demás). Cola `reports`, sin cola ni worker nuevos. Solo reciben el resumen (aviso y correo) los usuarios con el correo verificado, como el resto de avisos push |
| Cuándo y zona horaria | El lunes desde las 08:00 (`ReportSchedule::SEND_HOUR`) y hasta que acabe ese día, en la zona de la **marca principal** del usuario (la más antigua): `users` no tiene zona propia y los informes programados ya usan la hora local de la marca. Esa misma zona fija la semana de todas sus marcas. Sin marcas no hay resumen |
| Una vez por semana | `WeeklyDigest::claim()` es un `UPDATE` condicionado sobre `users.weekly_digest_sent_for` (el lunes local de la semana): dos pasadas, aunque sean simultáneas, no duplican. Si preparar el resumen falla (`handle()`) o el job muere tras el claim por timeout o kill (`failed()`), la semana se devuelve **solo si aún no hay aviso de esa semana** (`data->week`), y la siguiente pasada de la hora lo reintenta; un fallo del correo se registra y no repite el aviso de la app |
| Contenido | Por marca propia, la semana anterior (lunes a domingo, `ReportSchedule::periodFor`) frente a la previa: seguidores, visualizaciones, alcance e interacciones (`AnalyticsService::summary`, las mismas tarjetas y comparación del panel), publicaciones publicadas (`AnalyticsService::publishedCount`, el mismo cálculo del panel: zona de cada marca y sin cuentas desconectadas) y la mejor por interacciones (`ReportService::topPosts`). Una métrica o una marca sin dato sale «sin datos», nunca un 0 inventado |
| Entrega | Aviso `weekly_digest` (`WeeklyDigestReady`, canal `database`, con título y enlace al panel) y el correo `App\Mail\WeeklyDigest` (HTML y texto plano, enlaces absolutos) |
| IA opcional | Párrafo con `AiAssistant::complete` (política de cuota única de T-82) solo si el asistente está configurado **y** el usuario activa «Añadir un párrafo escrito con IA» (`weekly_digest_ai`, apagado por defecto). Viajan al proveedor únicamente los nombres de marca (sin correos, teléfonos ni enlaces) y las cifras agregadas, como datos dentro de un JSON; nunca textos de publicaciones ni datos del usuario. Si falla o responde algo desmesurado (> 700 caracteres), el resumen sale igual sin el párrafo. La política de privacidad (`resources/views/legal/privacy.blade.php`) lo menciona |
| Baja | El correo lleva una URL firmada `digest/{user}/unsubscribe` y las cabeceras `List-Unsubscribe` (RFC 8058): el GET firmado confirma sin efectos y solo el POST firmado desactiva el resumen de ese usuario (`resources/views/unsubscribe.blade.php`, fuera de CSRF en `bootstrap/app.php`, 30 peticiones/min). También hay un enlace a las preferencias |

Preferencias por tipo (`NotificationPreferenceController`, `PATCH profile/notification-preferences`): resumen semanal (apagado por defecto), su párrafo de IA (apagado) y **recordatorio de publicación manual** (`users.notify_manual_reminder`, **encendido por defecto**, el comportamiento anterior). Sin recordatorio no hay aviso por ningún canal antes de la hora (`ManualPublishService::remindUpcoming()` lo excluye de la selección y del claim, así que reactivarlo antes de la hora aún lo recibe, y `ManualPublishReminder::shouldSend()` lo vuelve a comprobar al procesar un aviso que ya esperaba en la cola, porque el worker no llama otra vez a `via()`). El aviso «es la hora» (`notifyDue()`) **no** respeta la preferencia: entrega el texto y la media de una publicación que la aplicación no enviará sola. El correo sigue gobernado por `notify_by_mail` (Perfil). Pruebas: `tests/Feature/Notifications/WeeklyDigestTest.php` y `ManualPublishReminderTest.php`; E2E-25 en `tests/e2e/weekly-digest.spec.ts`.
