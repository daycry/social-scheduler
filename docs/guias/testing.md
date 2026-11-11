# Tests de Node y limpieza de `storage/framework/testing`

Además de Pest (`composer test`) y Playwright (`npm run test:e2e`), el repo tiene tests de Node en `tests/frontend/`.

| Script | Qué ejecuta |
|---|---|
| `npm run test:unit` | los `tests/frontend/*.test.mjs` que **no** necesitan navegador (excluye `*.browser.test.mjs`), con `node --test --expose-gc` |
| `npm run test:browser` | los 6 `tests/frontend/*.browser.test.mjs` (Chromium de Playwright, `ffmpeg` y Vite) |
| `npm run test:purge` | purga de `storage/framework/testing` (ver abajo) |

El selector es `scripts/test/run-node-tests.mjs` (`<unit|browser> [--list]`). `studio-captions-render-entry.mjs` no es un test (no acaba en `.test.mjs`): lo importa un test de navegador y nunca se ejecuta solo.

## Purga de ejecuciones antiguas

Cada ejecución E2E deja `storage/framework/testing/t43/run-*` (llegó a ocupar 7,6 GB). `scripts/test/purge-testing.mjs` conserva una entrada si es **de las N más recientes o tiene menos de X días**, y borra el resto (hijos directos de `testing/` y de `testing/t43/`). Nunca sigue enlaces simbólicos, no toca ficheros ocultos (`.gitignore`) ni la ejecución en curso (`E2E_GENERAL_RUN_ROOT`).

```bash
node scripts/test/purge-testing.mjs --dry-run            # solo informa qué borraría
node scripts/test/purge-testing.mjs --keep 5 --min-age-days 2
```

Se ejecuta sola en `tests/e2e/global-teardown.ts` al terminar la batería (por defecto N=5, X=2 días; un fallo solo avisa). Variables: `E2E_PURGE=0` la desactiva, `E2E_PURGE_KEEP`, `E2E_PURGE_MIN_AGE_DAYS`. El mínimo de antigüedad protege las ejecuciones de otro proceso en curso.
