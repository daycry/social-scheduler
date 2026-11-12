# Tests de Node y limpieza de `storage/framework/testing`

Además de Pest (`composer test`) y Playwright (`npm run test:e2e`), el repo tiene tests de Node en `tests/frontend/`.

| Script | Qué ejecuta |
|---|---|
| `npm run test:unit` | los `tests/frontend/*.test.mjs` que **no** lanzan un navegador y los `tests/e2e/isolated/*.test.cjs` (comprobaciones del arnés E2E, del paralelismo y del servidor HTTP local), con `node --test --expose-gc` |
| `npm run test:browser` | los que lanzan Chromium: los `*.browser.test.mjs` y cualquier test que **importe `playwright`, directa o transitivamente** (p. ej. todos los que importan `scripts/studio/av-video.mjs`); 13 ficheros hoy. Necesita Chromium (`npx playwright install chromium`), `ffmpeg` y Vite |
| `npm run test:purge` | purga de `storage/framework/testing` (ver abajo) |

El selector es `scripts/test/run-node-tests.mjs` (`<unit|browser> [--list]`). La clasificación es **automática** (análisis estático del grafo de imports relativos; `@playwright/test` no cuenta, es el runner), no por nombre de fichero; `tests/frontend/test-tooling.test.mjs` falla si un test de `test:unit` importa un navegador. `studio-captions-render-entry.mjs` no es un test (no acaba en `.test.mjs`): lo importa un test de navegador y nunca se ejecuta solo.

## E2E en paralelo: workers, shard y `@responsive`

La batería general (`npm run test:e2e`) iba en serie (un worker, un `php -S`, tres proyectos que repetían los 258 casos: 774 ejecuciones, ~1,5 h). Ahora:

- **Un servidor, una SQLite y un `runRoot` por worker.** `tests/e2e/isolated/runtime.ts` reparte *slots*: el slot es el `parallelIndex` del worker de Playwright; cada uno tiene su puerto libre (`E2E_PORT` solo fija el del slot 0), su `run-general-<uuid>`, su `db.sqlite`, su sesión de solo lectura y sus usuarios `e2e-sec-*`. `playwright.config.ts` lanza un `serve.cjs` por slot. Los ficheros se reparten entre workers; dentro de un fichero los tests siguen en orden en un único worker, también los 23 specs con `describe.configure({ mode: 'serial' })`.
- **Aislamiento comprobado por worker.** `global-setup` ejecuta `verifyRunIdentity` contra cada slot (el servidor del puerto debe tener como `storage` el `runRoot` de SU slot) y cada worker lo repite, de forma síncrona, al arrancar (`verifyRunIdentitySync` en `runtime.ts`). Si el puerto lo ocupa otro run u otro slot, el worker falla antes de ejecutar nada. La purga protege los `runRoot` de todos los slots de la ejecución en curso.
- **`E2E_WORKERS`** (entero de 1 a 16; por defecto **2**) fija a la vez los servidores y `workers`. `--workers=N` con N mayor que `E2E_WORKERS` falla con un mensaje claro (sube `E2E_WORKERS`); uno menor funciona, pero deja servidores sin uso. Con `E2E_WORKERS=1` el comportamiento es el serie de antes.
- **`--shard=i/n`** (nativo de Playwright) divide los ficheros entre máquinas de CI; cada shard monta sus propios servidores. Combina con `E2E_WORKERS` dentro de cada máquina.
- **RAM.** Cada worker son un `php -S` (~60 MB), un frontend Node (~100 MB), Chromium (~120 MB por contexto y más con Studio, audio o vídeo) y el proceso del worker. Medido con 2 workers sobre una muestra ligera (`pages`, `a11y`, `hashtags`, `saved-texts`, `campaigns`, `instagram-grid`): ≈ 1,9 GB en total (Chromium ≈ 0,9 GB, Node ≈ 0,8 GB, PHP ≈ 0,1 GB). Los specs de Studio y de exportación consumen bastante más: cuenta **~1,5–2 GB por worker** más ~1 GB de margen (2 workers → 4–5 GB libres; 4 workers → 8–9 GB). La pasada completa en serie de la fase 1 murió por falta de memoria: sube `E2E_WORKERS` poco a poco y vigila la RAM.
- **`PHP_CLI_SERVER_WORKERS` no sirve en Windows.** PHP 8.3 imprime `forking is not supported on this platform` y sigue con un único proceso; por eso el paralelismo está en los servidores por worker y no en workers dentro de `php -S`. En Linux/macOS podría usarse además, pero el arnés no lo activa.
- **Evidencias por slot.** `results.json`, los informes y las trazas de fallos viven bajo `storage/framework/testing/t43/run-general-<uuid>/` (el `results.json` es el del slot 0; `artifacts/` de cada slot en su propio `runRoot`).

### Proyectos móviles: solo `@responsive`

`desktop` ejecuta todo (258 casos); `mobile` (375 px) y `mobile-360` solo los tests etiquetados `{ tag: '@responsive' }` (197 casos cada uno): 652 ejecuciones en vez de 774 (-16 %). Manda la cobertura: un fallo real de maquetación (por ejemplo el desbordamiento a 375 px de las pestañas «Editar por red») solo se ve en un ancho estrecho.

**Regla.** Lleva `@responsive` todo test que cumpla al menos una de estas condiciones: llama a `expectNoOverflow` (o `overflowX`/`scrollWidth`), hace una auditoría axe, tiene ramas por proyecto o viewport (`project.name ===`, `startsWith('mobile')`, `isMobile`, `viewportSize`, `.viewport`) o aparece en un ledger como fallo histórico solo en móvil. Se detecta también a través de los helpers locales que contengan esas llamadas. Se etiqueta un test con `test('…', { tag: '@responsive' }, async …)` y un grupo con `test.describe('…', { tag: '@responsive' }, …)`.

**Quedan solo en desktop** (61 casos): los que no tienen ninguna de esas condiciones —lógica de publicación, importación y cancelación de media, seguridad 2FA/passkey en sus casos sin maquetación propia, flujos OAuth/social sin captura de desbordamiento, API del MCP, Studio headless (exportación, presentaciones, temporizadores, automatización, gráficos)—. Una excepción con la detección activada (falso positivo de helper) debe figurar con su motivo en `RESPONSIVE_EXCEPTIONS` de `tests/frontend/test-tooling.test.mjs`, que falla (en `npm run test:unit`) si un test con esas condiciones no lleva la etiqueta. `npx playwright test --list --project=mobile` debe listar solo etiquetados (lo comprueba `tests/e2e/isolated/parallel.test.cjs`).

### Medición

Muestra de 215 ejecuciones (6 specs ligeros × 3 proyectos con `@responsive`), `E2E_PURGE=0`, misma máquina Windows:

| Modo | Tiempo | Resultado |
|---|---|---|
| `E2E_WORKERS=1` (serie) | 6,9 min | 213 verdes, 2 omitidos previstos |
| `E2E_WORKERS=2` | 4,0 min (239 s en total, con el arranque de dos servidores) | 213 verdes, 2 omitidos previstos |

Con 2 workers el reparto es por ficheros, y `a11y.spec.ts` (66 casos) domina; con más specs el reparto mejora. La duración total de la batería completa se mide en la pasada de fin de fase; esta muestra solo demuestra el reparto y el aislamiento.

## Pest: tiempos y `--parallel`

- En serie, `pest` tarda ~5 min (3685 tests). Los tests que antes dormían o salían a la red ya no lo hacen: el reloj de `RemoteMediaImporter` y de `BackfillMetrics` se simula (`SteppedClockImporter`, `Carbon::setTestNow`) y los datasets de Bluesky simulan también `getRecord`. Con `Http::preventStrayRequests()` (TestCase) una petición sin simular falla al instante; si un test parece lento, mide con `vendor/bin/pest --profile`.
- **`--parallel` no se usa todavía.** Baja la suite a ~90 s con 4 procesos, pero no es estable: `wfSig()` vive en `ApprovalTest.php` y lo usan otros dos ficheros, y los `StudioAv*Test` fallan cuando el worker ya ejecutó otros ficheros (estado compartido sin localizar). `Storage::fake` sí aísla los discos por proceso. Hasta resolverlo, `composer test` sigue en serie.

## CI (`.github/workflows/`)

| Workflow / job | Cuándo | Qué ejecuta |
|---|---|---|
| `ci.yml` · `php` | PR y push a `main` | `vendor/bin/pint --test` y `composer test` (Pest completo); caché de `vendor` |
| `ci.yml` · `node` | ídem | `npm run test:unit` (incluye el arnés E2E) y `npm run build`; instala `ffmpeg` |
| `ci.yml` · `node-browser` | ídem | `npm run test:browser` con Chromium (`npx playwright install --with-deps chromium`, con caché de `~/.cache/ms-playwright` por versión de Playwright) y `ffmpeg` |
| `ci.yml` · `typecheck` | ídem | `npm run typecheck` (`tsc --noEmit` sobre `tests/e2e` y las configs de Playwright; los `.cjs`/`.mjs` se infieren con `allowJs`, sin `checkJs`) |
| `ci.yml` · `studio-runtime` | ídem | `python -m unittest discover -s tests/studio_runtime -p 'test_*.py'` con el Python privado de Studio y el modelo de quitar fondo (`setup-runtime.py --download-model`, SHA-256 fijado, con caché) |
| `ci.yml` · `openapi` | ídem | `npx @redocly/cli@2.77.0 lint --extends minimal docs/api/creative-suite.openapi.yaml` |
| `ci.yml` · `audit` | ídem | `composer audit --locked` y `npm audit --omit=dev` |
| `ci.yml` · `tests` | ídem | agregador: pasa si pasan todos los anteriores; es el **check requerido** de `main` |
| `nightly.yml` · `e2e` | cron diario 02:17 UTC y a mano | `npx playwright test --shard=i/4` en matriz (`E2E_WORKERS=2`); sube `storage/framework/testing/t43/` y `test-results/` como artefacto si falla |

- Una ejecución por commit: `push` solo a `main` (las ramas se prueban por su PR) y `concurrency` cancela las ejecuciones obsoletas de la misma rama/PR (en `main` no cancela). Cada job lleva `timeout-minutes`.
- **`tests/studio_runtime`:** `test_av_linux.py` necesita un cgroup v2 delegado y acotado (`STUDIO_AV_CGROUP_TEST=1`), que un runner hospedado no ofrece; se omite solo (`skipUnless`) y `test_av_limits.py` solo corre en Windows. `test_background.py` usa el modelo real (MIT, ~214 MiB) que la CI descarga y cachea; sin él el test del modelo real se omite con motivo explícito, y el de checksum usa un fichero propio (no depende del modelo). Esos casos (8 omitidos en total) siguen requiriendo una prueba manual en un servidor real.
- **OpenAPI:** el perfil `minimal` de Redocly falla con errores estructurales (tipos 3.1 en un documento 3.0.3, descripciones mal escapadas…); los `summary` que faltan son avisos (102 hoy). Versión fijada: súbela a propósito, no con `latest`.
- **No están en la CI:** `ledger-lint` (es un script del plugin de agentes, fuera del repo; se ejecuta en local con el kit). `npm run test:browser` y el E2E completo tampoco corren por PR: el E2E va en el nocturno.
- Local: `actionlint` valida los workflows (`go install github.com/rhysd/actionlint/cmd/actionlint@v1.7.7`).

## Purga de ejecuciones antiguas

Cada ejecución E2E deja un directorio en `storage/framework/testing/t43/` (llegó a ocupar 7,6 GB). `scripts/test/purge-testing.mjs` borra las antiguas, con un alcance deliberadamente estrecho.

**Qué se purga** (lista explícita de nombres, solo hijos directos de `testing/t43/`):

- `run-general-<uuid>`: el `runRoot` de la batería general (`tests/e2e/isolated/runtime.ts`).
- `run-<epoch ms>-<pid>`: el `runRoot` del runner de T-43 (`playwright.roadmap.config.ts`).

De esos se conserva una entrada si es **de las N más recientes o tiene menos de X días** (por defecto N=5, X=2).

**Qué nunca se toca:**

- Todo lo que no encaje exactamente en esos dos patrones: directorios de evidencia de iniciativas anteriores (`t12-…`, `run-qa-…`, `…-peer-…`), ficheros sueltos y cualquier cosa directamente bajo `testing/`.
- Cualquier entrada cuyo nombre aparezca en **cualquier fichero de texto** bajo `docs/` (`.md`, `.json`, `.txt`, `.log`…; incluido `docs/knowledge/journal`): es evidencia citada. Los ficheros se leen en streaming por trozos de 1 MB (memoria acotada, sin cargar ni concatenar `docs/`; un nombre partido entre dos trozos se detecta por un solape) y se decodifica UTF-16 (BOM o bytes nulos alternos, p. ej. logs de PowerShell). Los binarios (imágenes, vídeo, PDF o ficheros con bytes nulos sueltos) no cuentan. `docs/` se resuelve respecto al propio script; si no existe o no se puede leer, la purga **aborta sin borrar nada**.
- La ejecución en curso (`E2E_GENERAL_RUN_ROOT`), enlaces simbólicos y ficheros ocultos.

```bash
node scripts/test/purge-testing.mjs --dry-run            # solo informa qué borraría
node scripts/test/purge-testing.mjs --keep 5 --min-age-days 2
```

Se ejecuta sola en `tests/e2e/global-teardown.ts` al terminar la batería; un fallo solo avisa. Variables: `E2E_PURGE=0` la desactiva, `E2E_PURGE_KEEP`, `E2E_PURGE_MIN_AGE_DAYS`. Los valores (también `--keep`/`--min-age-days`) se validan en el módulo de purga: vacío, no numérico o menor de 1 → valor por defecto (5 y 2) con un aviso. El borrado de usuarios del teardown y la purga son independientes: si falla uno, el otro se ejecuta.
