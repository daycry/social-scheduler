# T12 datasets — QA de transporte, intento2

2026-11-06. **VERDE: 13/13 pasan, 0 fallos, 0 flaky, 0 skipped; 0 retries y 0 errores globales del runner.** Recuento independiente: PASS. Es una nueva pasada íntegra; no se arrastran los seis PASS de QA1.

## Alcance y aislamiento

Una ejecución real HTTP/TCP de API y del transporte JSON-RPC del SDK MCP. Sin navegador para los tests, UI ni viewports. Servidor exclusivo localhost8080; puerto libre comprobado antes del seed y proof del storage propio. SQLite, storage, cuentas, bearer y archivos ficticios en UUIDnuevo338fc634-8dce-423d-a6d4-af305005bacc. No .env real, MySQL, proveedores, redes sociales, VPS, R2 ni ONNX.

PHP512MiB, post_max_size192MiB, upload_max_filesize64MiB; sólo perfil del runner, sin cambios en producción. JobObject Windows3GiB de **COMMIT agregado**, killOnClose=true y breakaway=false, con deadline. Pico COMMIT **1,393,283,072B**; pico del asignador PHP, separado, **465,567,744B**. No son RSS ni validación Linux. Duración Playwright 259.10s. Supervisor30684/sesión47527 y servidor terminales después de la pasada.

El único cambio funcional frente a QA1 fue eliminar el escapeCR de una celda de la fixture pequeña, conservando LF/TAB y todos los CRLF de HTTP/multipart/CSV. Preflight puro vendor-autoload+codec confirmó original rechazado y corregido con identidad exacta, sin bootLaravel/env/DB/red. QA1 mantiene sus6PASS/7FAILharness y sus archivos intactos; no se sobrescribe ni se presenta como un defecto del producto.

## Puertas y resultados

Manifiesto31SHA aprobado validado antes de ejecutar. Snapshots de 51 fuentes/contratos/harness antes y después: **0 cambios**. Ledger-lint exit0 sin incoherencias; coverage-check exit0 sin referencias rotas ni criterios huérfanos. Las10tareas futuras sinmapeo siguen declaradas fuera de E2E-26. Guardrail canónico local ejecutado antes del seed. Los avisos y salidas exactas se conservan en raw.

| Caso | Resultado | Duración |
|---|---|---|
| E2E-26.01  | PASS | 1.40s |
| E2E-26.02  | PASS | 2.57s |
| E2E-26.03  | PASS | 3.12s |
| E2E-26.04  | PASS | 1.28s |
| E2E-26.05  | PASS | 12.63s |
| E2E-26.06  | PASS | 1.15s |
| E2E-26.07 missing | PASS | 0.95s |
| E2E-26.07 corrupt | PASS | 0.89s |
| E2E-26.08 con CL | PASS | 12.48s |
| E2E-26.08 sin CL | PASS | 127.87s |
| E2E-26.09  | PASS | 0.60s |
| E2E-26.10  | PASS | 4.60s |
| E2E-26.11  | PASS | 89.04s |

01: bearer401, ability403, usuario/marca404 y lecturas autorizadas. 02: replace crea revisión2;409 deja inventario/revisiones/hashes intactos; filas fijadas en revisión1 con offset exacto; historial paginado después del archivo, edición archivada404 y restauración sin crear revisión. 03: API rechaza claves extra y objetos donde exige listas; por cada MIME text/plain, application/JSON y ausencia real de Content-Type, MCP rechaza rows:{} y columnasobjnuméricas y acepta create pequeño legítimo.

04: parserCSV/TSV independiente comprueba que modo seguro añade apóstrofo a todos los strings de encabezado/celdas; modo text conserva datos literales; JSON preserva tipos/IDs/hash. Descargas HTTP reales con Content-Type UTF-8, private/no-store, nosniff y filename sólo ID/revisión. No se importa el codec ni sus asserts como observador. 07: snapshots ausente/corrupto devuelven503 por API y error503 MCP sin ruta ni Content-Disposition de éxito.

09: catálogo real de **42 herramientas en 2 páginas**, sin duplicados, nueva manage_studio_dataset y herramienta legacy list_brands. 10: acciones MCP read/write, propiedad/marca, importCSV/TSVtexto, exportlink privado,409, archivo/restore, pin histórico y auditoría con dataset_id. ImportJSONtexto literal `[0.0,1.0,-0.0,null,""]`: content[].text conserva lexicalmente los floats antes de parsear; Object.is confirma negativezero; descargaJSON bytefiel y SHA coherente. Los JSONobservadores contienen sólo fixtures ficticias y metadatos seleccionados.

## Archivos y límites reales

05: **25.000.000B CSV multipart real**,50filas/100columnas. Lectura de extremos y metadata; descargaCSVtext igual en bytes y SHA `f7978554e54fdbae62559f374c8371a23e4b1788cc46f0fb1a9b336e69a14d33`, además de parseo independiente completo. 06: nueveIDs exactos en dos páginas de filas, cada JSONarray≤6MiB, sin saltos ni truncado de celdas.

08: HTTPJSON53MiB+1 →413 antes del parseo con Content-Length y también chunked sin Content-Length. MCPtext/plain **167.772.161B** chunked sin Content-Length →413, enviado desde chunks64KiB sin crear una carga600MB.

11: JSONcanónico tipado **52.000.000B** importado vía MCP real. Encoder ensure_ascii normal: envelope **155,970,107B**, aceptado bajo el perfil PHP512MiB. DescargaJSON privada comprobada por headers, parser,52IDs/100columnas/textoUnicode y SHA idéntico `5916851043bee9de3bc8e9c159d915eb9dd423b5dc013cbd98b91b8b5b39c1a5`. Se acredita ese tamaño de envelope, no160MiB exactos ni inflación arbitraria por whitespace/escapes.

## Gate canónico literal

```json
{
  "verdict": "VERDE",
  "counts": {
    "passed": 13,
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

`raw/recount.json` contrasta todos los casos del JSON real con stats y exige13 resultados únicos, una sola ejecución por caso, cero retries/flaky/errors/skips/interrupted y fuentes intactas. Los datos raw publicables están saneados; no incluyen Authorization, token, cookie, configcache ni SQLite.

## Pendientes y entrega

Esta aceptación se limita al transporte de datasets. **T12 y la iniciativa siguen abiertas**: no acredita editor, tablas/gráficos responsive, presentaciones/documentos/pizarras, XLSX, conectores ni cierre global. No se modifica el ledger ni se hace handoff de cierre a documenter por este subbloque.

- [ ] M-01 — Usuario: scheduler/workerVPS, heartbeat y capturas del periodo cerrado.
- [ ] M-02 — Usuario: publicación real porred y smokesBluesky/Threads pendientes.
- [ ] M-03 — Desarrollo+usuario: rendererLinux/Node/Chromium/fuentes/cola y artefactos reales conR2.
- [ ] M-04 — Usuario: ClaudeDesktop sigue aplazado; no se reabre autorización.
- [ ] UI de tablas/gráficos/editor responsive y accesibilidad: pendientes del alcance integralT12.

Informe sólo local. PDF mediante flujo to-pdf, League/CommonMark+temaCSS y ChromiumPlaywright ya existentes, sin instalar; Job3GiB separado y sin servidor. PDFreal rasterizado con PyMuPDF e inspeccionado visualmente. EstadoSHA final posterior al PDF en final-evidence.json. Sólo se copian evidencias saneadas seleccionadas; auth/DB/configcache/trazas/logsHTTP siguen privados.
