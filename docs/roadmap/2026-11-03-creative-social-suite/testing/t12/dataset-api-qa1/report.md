# T12 datasets — QA de transporte, intento1

2026-11-06. **NO-VERDE: 6/13 pasan, 7 fallan, 0 flaky, 0 skipped, 0 retries y 0 errores globales del runner.** Playwright terminó con exit1. No se repite este intento ni se modifica su fixture. T12 y la iniciativa siguen abiertas; no hay aceptación de UI ni cierre de las23tareas.

## Ejecución y aislamiento

Una pasada HTTP/TCP real, sin navegador para los tests, SQLite/storage/cuentas/token ficticios y UUIDpropio. Servidor exclusivo localhost8080, puerto libre comprobado antes del seed y proof de aislamiento del storage. Supervisor34608/sesión41360 terminales; servidor cerrado después. No se accedió a .env real, MySQL, redes sociales, VPS, R2 ni ONNX.

PHP512MiB, post_max_size192MiB, upload_max_filesize64MiB; configuración exclusiva del runner. WindowsJob3GiB **COMMIT agregado**, killOnClose=true, breakaway=false y deadline; picoCOMMIT **1,292,562,432B**. Pico asignadorPHP separado **465,567,744B**. Ninguna de estas cifras se presenta comoRSS ni como medidaLinux/producción. DuraciónPlaywright 256.97s.

Manifiesto aprobado31SHA comprobado antes de correr; 51 fuentes/contratos/harness en snapshots antes/después: **0 cambios**. Ledger-lint: exit0, cero incoherencias y15avisos. Coverage-check: exit0, cero referencias rotas y cero criterios huérfanos;10tareas futuras sinmapeo declaradas fuera de estaQA. Guardrail canónico local ejecutado antes del seed. Raw de las puertas conservado.

## Casos reales

| Caso | Resultado | Duración |
|---|---|---|
| E2E-26.01  | FAIL harness | 2.04s |
| E2E-26.02  | FAIL harness | 0.20s |
| E2E-26.03  | FAIL harness | 2.88s |
| E2E-26.04  | FAIL harness | 0.19s |
| E2E-26.05  | PASS | 14.53s |
| E2E-26.06  | PASS | 1.25s |
| E2E-26.07 missing | FAIL harness | 0.17s |
| E2E-26.07 corrupt | FAIL harness | 0.23s |
| E2E-26.08 con CL | PASS | 11.61s |
| E2E-26.08 sin CL | PASS | 130.10s |
| E2E-26.09  | PASS | 0.89s |
| E2E-26.10  | FAIL harness | 0.20s |
| E2E-26.11  | PASS | 89.79s |

Los7FAIL se detienen al crear la tabla pequeña que se pretendía válida: seis ramas HTTP/MCP esperan éxito y obtienen422/isError; el caso03 falla en su create legítimo MIME. La fixture contieneCR en `literal\r\né`, mientras `StudioChartData::text` admiteLF/TAB y rechazaCR. **Es un error del harness identificado por inspección de fixture y validador, no un defecto acreditado del producto ni un RED deTDD.** Los tests no capturaron el body de validación y no se lanzó una llamada extra para reconstruirlo. El gate conserva los7fallos reales.

Por ese corte no quedan acreditadas las cadenas completas de permisos/marca de01, replace/historial/409/archivo de02, los3MIME completos de03, descargas seguras/text/JSONtyped de04, snapshots503 de07, ni CRUD/pin/floats0.0/1.0/-0.0/auditoría de10. No se transforma ninguna de ellas enPASS por la existencia de los FeatureKernel previos. Es necesaria una futura corrección de fixture revisada y una nueva autorización de intento, conservando éste intacto.

## Evidencia válida de esta pasada

CSV **25.000.000B** multipart real:50filas,100columnas, extremos leídos porAPI; descargaCSVtext exacta y parserCSV independiente. SHA entrada=salida `f7978554e54fdbae62559f374c8371a23e4b1788cc46f0fb1a9b336e69a14d33`. Metadatos de importación pequeños, sin devolución de segunda tabla completa.

Paginación6MiB:9IDs exactos en2páginas, sin saltos ni celdas truncadas, cada arrayJSON de filas dentro del presupuesto. Cuerpos HTTP53MiB+1 →413 con Content-Length y tambiénchunked sinContent-Length. MCPtext/plain **167.772.161B** stream64KiB, chunked sinContent-Length →413. No se generó un buffer600MB.

Catálogo MCP real del SDK: **42herramientas en2páginas**, IDs/nombres únicos, nueva `manage_studio_dataset` y smoke `list_brands` correctos. No se infiere un catálogo más grande.

ImportMCP: JSONcanónico **52.000.000B**, envelopeJSON-RPC con encoderensure_ascii normal **155,970,107B**; transporteTCP real aceptado, sinHTTPKernel/TestCase. PerfilPHP512MiB explícito. DescargaJSON privada streamed comprobada por headers, filenameID/revisión, parser propio,52IDs/100columnas/textoUnicode y SHA idéntico `5916851043bee9de3bc8e9c159d915eb9dd423b5dc013cbd98b91b8b5b39c1a5`. Esta prueba acredita ese envelope; no afirma haber enviado160MiB exactos ni serializaciones arbitrariamente infladas.

## Gate canónico literal

```json
{
  "verdict": "NO-VERDE",
  "counts": {
    "passed": 6,
    "failed": 7,
    "flaky": 0,
    "skipped": 0,
    "interrupted": 0
  },
  "flaky_sin_justificar": [],
  "flaky_justificados": {},
  "threshold": "verde ⟺ 0 failed, 0 flaky sin justificar, 0 interrumpidos y ≥1 test ejecutado"
}
```

El recuento independiente coincide con lasstats del JSON real y rechaza faltantes/retries/flaky/errors/skips/cambioSHA. `raw/recount.json` y `raw/case-results-sanitized.json` conservan cada caso y mensaje de error sin headers de autorización.

## Manuales pendientes

- [ ] M-01 — Usuario: scheduler/workerVPS, heartbeat y capturas del periodo cerrado.
- [ ] M-02 — Usuario: publicación real porred y smokesBluesky/Threads pendientes.
- [ ] M-03 — Desarrollo+usuario: rendererLinux/Node/Chromium/fuentes/cola y artefactos reales conR2.
- [ ] M-04 — Usuario: ClaudeDesktop sigue aplazado; no se reabre autorización.
- [ ] Editor/tablas/gráficos responsive, interacción y accesibilidad: pendientes del alcance integralT12.

Informe sólo local. Publicar únicamente evidencia saneada seleccionada; no copiar SQLite, auth/private.json, configcache, cookies, tokens, traces ni logsHTTP sin revisión. Se generaPDF por flujo to-pdf con League/CommonMark+temaCSS existente y ChromiumPlaywright ya instalado, en Job3GiB separado después de cerrar el servidor. La rasterizaciónPyMuPDF del PDFreal se inspecciona aparte; no es una screenshot deUI.
