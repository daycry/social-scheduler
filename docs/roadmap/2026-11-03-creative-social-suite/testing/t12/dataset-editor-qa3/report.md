# T12 — biblioteca/editor de datasets, QA UI

Veredicto canónico y recuento real: **PASS**, 15 casos observados. No se arrastran PASS de transporte. La aceptación visual de capturas/PDF requiere registro explícito posterior en pdf-validation.json y visual-review.json; no se infiere del gate automático.

## Alcance y aislamiento

E2E27.01–05 en375/768/1440; servidorlocalhost propio,SQLite/storage/usuarios/configcache ficticiosUUID. Config antesKernel y proof de rutas antes de usuarios. Sin .env real, MySQL, proveedores, redes sociales, VPS, R2 ni ONNX. Build de root previamente terminado/congelado; no rebuild. WindowsJob3GiB COMMIT/killOnClose/noBreakaway ydeadline, un worker y cero retries. COMMIT agregado medido: 761221120B; asignadorPHP: **No disponible en esta pasada**. No se presentan comoRSS ni medidaLinux.

El proceso PHP propio mostró argumentos reales512M/post192M/upload64M y router público tests/e2e/isolated/server.php. Se pasó auto_prepend_file, pero no se observó su log del asignador: no se atribuye una causa ni se inventa una cifra. summary.json conserva null. Portabilidad acreditada para imports/colección y servidor público utilizado en esta QA; no acredita CI global, Linux ni VPS.

## Resultados

| Escenario | Proyecto | Outcome real |
|---|---|---|
| E2E27.01 | desktop | expected |
| E2E27.02 | desktop | expected |
| E2E27.03 | desktop | expected |
| E2E27.04 | desktop | expected |
| E2E27.05 | desktop | expected |
| E2E27.01 | mobile | expected |
| E2E27.02 | mobile | expected |
| E2E27.03 | mobile | expected |
| E2E27.04 | mobile | expected |
| E2E27.05 | mobile | expected |
| E2E27.01 | mobile-360 | expected |
| E2E27.02 | mobile-360 | expected |
| E2E27.03 | mobile-360 | expected |
| E2E27.04 | mobile-360 | expected |
| E2E27.05 | mobile-360 | expected |

Recount exige IDs/proyectos completos, stats reales, viewport efectivo y cero errores/retries/skips/flaky/interrupted; su veredicto anterior declara si este intento cumple. Puertas ledger-lint/coverage-check/guardrail en raw privado; criterios futuros fuera del subbloque siguen declarados. Expected browser network diagnostics de404/409/422 se conservan aparte; ninguna excepción JavaScript o console.error de aplicación se omite.

## Gate literal

```json
{
  "verdict": "VERDE",
  "counts": {
    "passed": 15,
    "failed": 0,
    "flaky": 0,
    "skipped": 0,
    "interrupted": 0
  },
  "flaky_sin_justificar": [],
  "flaky_justificados": {},
  "threshold": "verde ⟺ 0 failed, 0 flaky sin justificar, 0 interrumpidos y ≥1 test ejecutado"
}
```

## Observación independiente

Los oráculos previstos de CSV/TSV seguros/fieles y JSON tipado usan parsers independientes de codecs/producto para lexemas0.0/1.0/-0.0/9007199254740993, strings/multilínea/null/IDs, bytes y SHA/headers privados. Sólo las entradas del inventario de este intento acreditan comprobaciones alcanzadas; no se atribuyen ejecuciones a formatos ausentes. Los oráculos previstos cubren UICRUD/estructura/conversión,409/borrador/historial/biblioteca,archivo/marca/respuestas tardías mediante HTTP real; sólo un caso completo con outcome expected acredita su cadena. Un caso fallido puede haberse detenido antes de alguna comprobación. route.fetch sólo demora entrega; no finge éxito. Inventario saneado de **48 descargas HTTP snapshot +39 borradores Blob =87 archivos** y18 capturas; raw de resultados con config/auth no es publicable.

## Manuales y alcance abierto

- [ ] M-01 VPS scheduler/worker/heartbeat del usuario.
- [ ] M-02 publicación real porred y smokes externos pendientes.
- [ ] M-03 rendererLinux/colas/fuentes/medios/R2 reales.
- [ ] M-04 ClaudeDesktop aplazado, sin nueva autorización.

T12 integral y23tareas no se cierran por este subbloque. Gráficos/capas, XLSX/conectores, documentos/presentaciones/pizarras y despliegue conservan sus criterios. No se publica este informe enConfluence. No copiar configcache/SQLite/auth/tokens/cookies/traces/requestheaders.

## Capturas reales para inspección

### desktop — E2E27.01

![desktop studio-datasets-E2E27-01-I-e19a9--reopen-and-numeric-lexemes-desktop-typed-editor.png](screenshots/studio-datasets-E2E27-01-I-e19a9--reopen-and-numeric-lexemes-desktop-typed-editor.png)

### mobile — E2E27.01

![mobile studio-datasets-E2E27-01-I-e19a9--reopen-and-numeric-lexemes-mobile-typed-editor.png](screenshots/studio-datasets-E2E27-01-I-e19a9--reopen-and-numeric-lexemes-mobile-typed-editor.png)

### mobile-360 — E2E27.01

![mobile-360 studio-datasets-E2E27-01-I-e19a9--reopen-and-numeric-lexemes-mobile-360-typed-editor.png](screenshots/studio-datasets-E2E27-01-I-e19a9--reopen-and-numeric-lexemes-mobile-360-typed-editor.png)

### desktop — E2E27.02

![desktop studio-datasets-E2E27-02-r-61d36-owed-cells-and-invalid-file-desktop-imports.png](screenshots/studio-datasets-E2E27-02-r-61d36-owed-cells-and-invalid-file-desktop-imports.png)

### mobile — E2E27.02

![mobile studio-datasets-E2E27-02-r-61d36-owed-cells-and-invalid-file-mobile-imports.png](screenshots/studio-datasets-E2E27-02-r-61d36-owed-cells-and-invalid-file-mobile-imports.png)

### mobile-360 — E2E27.02

![mobile-360 studio-datasets-E2E27-02-r-61d36-owed-cells-and-invalid-file-mobile-360-imports.png](screenshots/studio-datasets-E2E27-02-r-61d36-owed-cells-and-invalid-file-mobile-360-imports.png)

### desktop — E2E27.03

![desktop studio-datasets-E2E27-03-s-63138--preview-transpose-IDs-undo-desktop-limits.png](screenshots/studio-datasets-E2E27-03-s-63138--preview-transpose-IDs-undo-desktop-limits.png)

### desktop — E2E27.03

![desktop studio-datasets-E2E27-03-s-63138--preview-transpose-IDs-undo-desktop-structure.png](screenshots/studio-datasets-E2E27-03-s-63138--preview-transpose-IDs-undo-desktop-structure.png)

### mobile — E2E27.03

![mobile studio-datasets-E2E27-03-s-63138--preview-transpose-IDs-undo-mobile-limits.png](screenshots/studio-datasets-E2E27-03-s-63138--preview-transpose-IDs-undo-mobile-limits.png)

### mobile — E2E27.03

![mobile studio-datasets-E2E27-03-s-63138--preview-transpose-IDs-undo-mobile-structure.png](screenshots/studio-datasets-E2E27-03-s-63138--preview-transpose-IDs-undo-mobile-structure.png)

### mobile-360 — E2E27.03

![mobile-360 studio-datasets-E2E27-03-s-63138--preview-transpose-IDs-undo-mobile-360-limits.png](screenshots/studio-datasets-E2E27-03-s-63138--preview-transpose-IDs-undo-mobile-360-limits.png)

### mobile-360 — E2E27.03

![mobile-360 studio-datasets-E2E27-03-s-63138--preview-transpose-IDs-undo-mobile-360-structure.png](screenshots/studio-datasets-E2E27-03-s-63138--preview-transpose-IDs-undo-mobile-360-structure.png)

### desktop — E2E27.04

![desktop studio-datasets-E2E27-04-s-5bc22-restore-and-brand-isolation-desktop-brand-isolation.png](screenshots/studio-datasets-E2E27-04-s-5bc22-restore-and-brand-isolation-desktop-brand-isolation.png)

### mobile — E2E27.04

![mobile studio-datasets-E2E27-04-s-5bc22-restore-and-brand-isolation-mobile-brand-isolation.png](screenshots/studio-datasets-E2E27-04-s-5bc22-restore-and-brand-isolation-mobile-brand-isolation.png)

### mobile-360 — E2E27.04

![mobile-360 studio-datasets-E2E27-04-s-5bc22-restore-and-brand-isolation-mobile-360-brand-isolation.png](screenshots/studio-datasets-E2E27-04-s-5bc22-restore-and-brand-isolation-mobile-360-brand-isolation.png)

### desktop — E2E27.05

![desktop studio-datasets-E2E27-05-d-7ef79--discard-keyboard-and-focus-desktop-late-close-brand-keyboard.png](screenshots/studio-datasets-E2E27-05-d-7ef79--discard-keyboard-and-focus-desktop-late-close-brand-keyboard.png)

### mobile — E2E27.05

![mobile studio-datasets-E2E27-05-d-7ef79--discard-keyboard-and-focus-mobile-late-close-brand-keyboard.png](screenshots/studio-datasets-E2E27-05-d-7ef79--discard-keyboard-and-focus-mobile-late-close-brand-keyboard.png)

### mobile-360 — E2E27.05

![mobile-360 studio-datasets-E2E27-05-d-7ef79--discard-keyboard-and-focus-mobile-360-late-close-brand-keyboard.png](screenshots/studio-datasets-E2E27-05-d-7ef79--discard-keyboard-and-focus-mobile-360-late-close-brand-keyboard.png)
