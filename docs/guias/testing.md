# Tests de Node y limpieza de `storage/framework/testing`

Además de Pest (`composer test`) y Playwright (`npm run test:e2e`), el repo tiene tests de Node en `tests/frontend/`.

| Script | Qué ejecuta |
|---|---|
| `npm run test:unit` | los `tests/frontend/*.test.mjs` que **no** necesitan navegador (excluye `*.browser.test.mjs`) y los `tests/e2e/isolated/*.test.cjs` (comprobaciones del arnés E2E, del paralelismo y del servidor HTTP local), con `node --test --expose-gc` |
| `npm run test:browser` | los 6 `tests/frontend/*.browser.test.mjs` (Chromium de Playwright, `ffmpeg` y Vite) |
| `npm run test:purge` | purga de `storage/framework/testing` (ver abajo) |

El selector es `scripts/test/run-node-tests.mjs` (`<unit|browser> [--list]`). `studio-captions-render-entry.mjs` no es un test (no acaba en `.test.mjs`): lo importa un test de navegador y nunca se ejecuta solo.

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

`desktop` ejecuta todo (258 casos); `mobile` (375 px) y `mobile-360` solo los tests etiquetados `{ tag: '@responsive' }` (79 casos cada uno). Total: 416 ejecuciones en vez de 774. Criterio: se etiqueta lo que comprueba **maquetación, desbordamiento horizontal, navegación o menús que cambian en móvil, vistas con tablas/rejillas o accesibilidad a anchos estrechos**; la lógica de negocio (publicar, programar, importar media, permisos, 2FA, OAuth, Studio headless) es la misma en cualquier ancho y se queda solo en `desktop`.

| Spec | Tests `@responsive` | Por qué |
|---|---|---|
| `pages.spec.ts` | los 3 grupos: páginas públicas, autenticadas y shell | recorre todas las páginas con 0 px de desbordamiento y el menú |
| `a11y.spec.ts` | `axe · públicas` y `axe · autenticadas` | contraste y tamaño de objetivo cambian a 375/360 px |
| `flows.spec.ts` | registro; marca → borrador → calendario/lista/papelera/biblioteca | formularios y navegación completa en móvil |
| `calendar-views.spec.ts` | la vista por defecto al entrar por el menú | el menú de móvil es otro |
| `composer.spec.ts` | E2E-01 (pantalla completa y pastillas), E2E-12 (barrido de desbordamiento), A11Y-01 (emoji/IA/salida), E2E-13 (selector de tipo, ramas por ancho) | maquetación del compositor |
| `sites.spec.ts` | E2E-15 y E2E-15b | vista previa móvil/tablet/escritorio de los Sitios |
| `instagram-grid.spec.ts` | E2E-05 | rejilla de 3 columnas sin desbordar |
| `campaigns.spec.ts` | E2E-06 | lista y selector de campañas con captura por proyecto |
| `saved-texts.spec.ts` | E2E-02 | menú del compositor sobre el cursor |
| `studio.spec.ts`, `studio-boards.spec.ts` | el editor de diseño y la pizarra | lienzo en pantalla estrecha y ramas táctiles |

Etiqueta un test nuevo con `test('…', { tag: '@responsive' }, async …)` o un grupo con `test.describe('…', { tag: '@responsive' }, …)` si es una comprobación de maquetación o navegación; `npx playwright test --list --project=mobile` debe listar solo etiquetados (lo comprueba `tests/e2e/isolated/parallel.test.cjs`).

### Medición

Muestra de 215 ejecuciones (6 specs ligeros × 3 proyectos con `@responsive`), `E2E_PURGE=0`, misma máquina Windows:

| Modo | Tiempo | Resultado |
|---|---|---|
| `E2E_WORKERS=1` (serie) | 6,9 min | 213 verdes, 2 omitidos previstos |
| `E2E_WORKERS=2` | 4,0 min (239 s en total, con el arranque de dos servidores) | 213 verdes, 2 omitidos previstos |

Con 2 workers el reparto es por ficheros, y `a11y.spec.ts` (66 casos) domina; con más specs el reparto mejora. Sumado a la reducción móvil, la estimación de la batería completa es el ~54 % de las ejecuciones originales repartidas entre los workers; el dato real lo da la pasada completa.

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
