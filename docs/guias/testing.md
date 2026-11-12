# Tests de Node y limpieza de `storage/framework/testing`

Además de Pest (`composer test`) y Playwright (`npm run test:e2e`), el repo tiene tests de Node en `tests/frontend/`.

| Script | Qué ejecuta |
|---|---|
| `npm run test:unit` | los `tests/frontend/*.test.mjs` que **no** necesitan navegador (excluye `*.browser.test.mjs`) y los `tests/e2e/isolated/*.test.cjs` (comprobaciones del arnés E2E y del servidor HTTP local), con `node --test --expose-gc` |
| `npm run test:browser` | los 6 `tests/frontend/*.browser.test.mjs` (Chromium de Playwright, `ffmpeg` y Vite) |
| `npm run test:purge` | purga de `storage/framework/testing` (ver abajo) |

El selector es `scripts/test/run-node-tests.mjs` (`<unit|browser> [--list]`). `studio-captions-render-entry.mjs` no es un test (no acaba en `.test.mjs`): lo importa un test de navegador y nunca se ejecuta solo.

## Purga de ejecuciones antiguas

Cada ejecución E2E deja un directorio en `storage/framework/testing/t43/` (llegó a ocupar 7,6 GB). `scripts/test/purge-testing.mjs` borra las antiguas, con un alcance deliberadamente estrecho.

**Qué se purga** (lista explícita de nombres, solo hijos directos de `testing/t43/`):

- `run-general-<uuid>`: el `runRoot` de la batería general (`tests/e2e/isolated/runtime.ts`).
- `run-<epoch ms>-<pid>`: el `runRoot` del runner de T-43 (`playwright.roadmap.config.ts`).

De esos se conserva una entrada si es **de las N más recientes o tiene menos de X días** (por defecto N=5, X=2).

**Qué nunca se toca:**

- Todo lo que no encaje exactamente en esos dos patrones: directorios de evidencia de iniciativas anteriores (`t12-…`, `run-qa-…`, `…-peer-…`), ficheros sueltos y cualquier cosa directamente bajo `testing/`.
- Cualquier entrada cuyo nombre aparezca en **cualquier fichero de texto** bajo `docs/` (`.md`, `.json`, `.txt`, `.log`…; incluido `docs/knowledge/journal`): es evidencia citada. Los binarios (imágenes, vídeo, PDF…) no cuentan. `docs/` se resuelve respecto al propio script; si no existe o no se puede leer, la purga **aborta sin borrar nada**.
- La ejecución en curso (`E2E_GENERAL_RUN_ROOT`), enlaces simbólicos y ficheros ocultos.

```bash
node scripts/test/purge-testing.mjs --dry-run            # solo informa qué borraría
node scripts/test/purge-testing.mjs --keep 5 --min-age-days 2
```

Se ejecuta sola en `tests/e2e/global-teardown.ts` al terminar la batería; un fallo solo avisa. Variables: `E2E_PURGE=0` la desactiva, `E2E_PURGE_KEEP`, `E2E_PURGE_MIN_AGE_DAYS`. Los valores (también `--keep`/`--min-age-days`) se validan en el módulo de purga: vacío, no numérico o menor de 1 → valor por defecto (5 y 2) con un aviso. El borrado de usuarios del teardown y la purga son independientes: si falla uno, el otro se ejecuta.
