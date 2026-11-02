# QA local — audio por red

Informe de la entrega inicial, anterior a T-10. La [corrección posterior del buscador y sus evidencias](search-pagination/report.md) es la referencia para el código actualizado; los hashes de este informe fijan la versión inicial.

Fecha: 2026-10-31. Rama `feature/metricool-composer-parity`, base `8fe2f230853d6edc46667a61d08d23d0110edf5d`. Resultado local: **VERDE**. Implementación y revisión cerradas; **la publicación real permanece pendiente del usuario**. No se publicaron posts reales ni se modificaron credenciales de cuentas durante estas pruebas.

## Resultados finales

| Verificación | Resultado | Evidencia |
|---|---|---|
| Suite completa PHP/Pest | 2.491 pasados, 14.671 aserciones; 0 fallos, errores u omitidos | [JUnit XML](raw/phpunit.xml) |
| Regresión reactiva TikTok con Node | 12 pasados, 0 fallos | `tests/frontend/tiktok-creator.test.mjs`; ejecutada también por revisores B y D |
| Playwright de audio | 15 pasados, 0 fallos, flaky u omitidos | [JSON oficial](raw/playwright.json) |
| qa-gate del plugin | VERDE: 15 pasados, 0 interrumpidos | [Salida del gate](raw/qa-gate.json) |
| Build Vite y TypeScript | `npm run build` y `npx tsc --noEmit`: exit 0 | Ejecuciones verificadas en la sesión de desarrollo |
| Formato PHP y diff | Pint sobre PHP cambiado y `git diff --check`: correctos | Ejecutados antes del cierre |
| Revisión independiente | A+B+D, intento 3: 0 gaps pendientes | [Ledger con los tres intentos y correcciones](../tasks.md) |

El [manifiesto de validación](validation-summary.json) contiene los hashes SHA256 de las fuentes después de las comprobaciones y de los artefactos. Permite detectar cambios posteriores que necesiten una nueva verificación. No atribuye resultados a commits futuros.

Cierre documental: [scope-check sin archivos fuera del alcance ni avisos](raw/scope-check.json), [ledger-lint sin incoherencias ni avisos](raw/ledger-lint.txt), 58 enlaces locales comprobados y hashes íntegros de 37 fuentes y 9 artefactos. El ledger conserva 8/9 tareas completas y la spec 11/12 criterios; la diferencia corresponde al smoke real.

Comandos de las ejecuciones finales:

```text
php vendor/bin/pest --colors=never --log-junit=docs/roadmap/2026-10-31-metricool-composer-parity/testing/raw/phpunit.xml
node --test --experimental-test-isolation=none tests/frontend/tiktok-creator.test.mjs
npx playwright test --config=playwright.audio.config.ts
npm run build
npx tsc --noEmit
```

Run E2E canónico: `storage/framework/testing/t43/run-general-b18f3383-21e0-47a9-a10a-ef74bb4fa994`. Las ejecuciones intermedias y sus errores permanecen registradas en el ledger; no se suman al resultado final. La suite PHP completa no se repitió tras correcciones exclusivamente JS/documentales.

## Cobertura y evidencias visuales

Cinco escenarios ejecutados en escritorio 1440×900 y móviles 375×812 y 360×740: Instagram buscar/elegir/volúmenes/guardar/reabrir/quitar; vacío/error/cierre con foco; preescucha sin autoplay y limpieza de formato; TikTok música recomendada/guardar/reabrir/limpieza; error de creator_info estable y recuperación manual. Incluye comprobaciones axe y ausencia de desbordamiento horizontal. Las cuentas, medios y respuestas del proveedor son ficticios. El dominio verificado de TikTok se habilita solo en el entorno desechable de esta suite.

| Vista | Instagram | TikTok |
|---|---|---|
| Escritorio | [Captura](screenshots/instagram-audio-desktop.png) | [Captura](screenshots/tiktok-audio-desktop.png) |
| Móvil 375 | [Captura](screenshots/instagram-audio-mobile.png) | [Captura](screenshots/tiktok-audio-mobile.png) |
| Móvil 360 | [Captura](screenshots/instagram-audio-mobile-360.png) | [Captura](screenshots/tiktok-audio-mobile-360.png) |

Inspección visual directa de Instagram escritorio y TikTok móvil 360: controles y textos presentes, volúmenes guardados 80/25 y casilla de música recomendada legibles. El encabezado fijo puede aparecer dentro de la captura de página completa cuando el viewport estaba desplazado; las comprobaciones de interacción y desbordamiento corresponden al viewport real.

Las pruebas PHP cubren propiedad de cuenta/marca y abilities MCP, forma y compatibilidad de opciones, saneamiento de URLs y errores, búsqueda/metadata, persistencia, payload oficial, rechazo sin envío silencioso, reconexión, fallo temporal y checkpoint. La regresión Node reproduce mensajes de error vacíos/falsos y respuestas 200 mal formadas, y confirma una única consulta automática y recuperación explícita.

## Límites y seguimiento real

- Instagram: selector nativo para Reels elegibles; búsqueda de música/audio original, preescucha disponible y dos volúmenes. Sin recorte, desplazamiento de pista ni previsualización de mezcla final.
- TikTok: música recomendada elegida por TikTok para fotos/carruseles Direct Post. El selector de canción concreta de TikTok Business requiere otra integración y permanece pendiente.
- Facebook, YouTube, Bluesky y Threads: sonido incluido en el vídeo y capacidades compartidas por web/MCP. La asociación de catálogo de Facebook no está implementada; su endpoint de recomendaciones no acredita esa asociación.
- Threads requiere configurar App ID/secreto en esta instalación; las pruebas locales no acreditan esa conexión real.

El [protocolo de smoke real](smoke-real.md) permanece abierto en **T-06 y CA-10**. Registrar enlaces/IDs y resultados antes de cerrar esa tarea. [Contratos e investigación por red](audio-by-network.md), [guía de uso y MCP](../../../guias/audio-instagram.md).
