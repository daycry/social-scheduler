import hashlib,json,pathlib,re,shutil
OUT=pathlib.Path(__file__).resolve().parent
ROOT=OUT.parents[4]
PRIVATE=OUT.parent
RUN=ROOT/'storage/framework/testing/t43/run-general-d4f307e6-622c-4262-ad47-17a0bbf04e72'
read=lambda p:json.loads(p.read_text(encoding='utf-8'))
results=read(RUN/'results.json');recount=read(RUN/'recount.json');execution=read(PRIVATE/'execution.json')
peaks=[json.loads(l) for l in (RUN/'php-peaks.jsonl').read_text(encoding='utf-8').splitlines()]
before=read(RUN/'sources-before.json');after=read(RUN/'sources-after.json')
fixtures=read(RUN/'fixtures/fixture-manifest.json');catalog=read(RUN/'catalog-observer.json');large=read(RUN/'large-observer.json')
raw=OUT/'raw';raw.mkdir(exist_ok=True)
for filename in ['recount.json','qa-gate.json','sources-before.json','sources-after.json','catalog-observer.json','large-observer.json','mcp-raw-cap-observer.json','ledger-lint.json','coverage-check.json']:
 shutil.copy2(RUN/filename,raw/filename)
shutil.copy2(RUN/'fixtures/fixture-manifest.json',raw/'fixture-manifest.json')
rows=[]
def walk(s):
 for spec in s.get('specs',[]):
  for t in spec.get('tests',[]):
   item={'title':spec['title'],'outcome':t['status'],'expected':t['expectedStatus'],'duration_ms':sum(r['duration'] for r in t['results']),'statuses':[r['status'] for r in t['results']],'retry':[r.get('retry',0) for r in t['results']]}
   messages=[re.sub(r'\x1b\[[0-9;]*m','',r.get('error',{}).get('message','')) for r in t['results'] if r.get('error')]
   item['errors']=messages
   rows.append(item)
 for child in s.get('suites',[]):walk(child)
for s in results['suites']:walk(s)
(raw/'case-results-sanitized.json').write_text(json.dumps(rows,ensure_ascii=False,indent=2),encoding='utf-8')
summary={'actual_cases':13,'passed':6,'failed':7,'flaky':0,'skipped':0,'retries':0,'runner_errors':len(results.get('errors',[])),'changed_sources':[],'source_count':len(before),'approved_manifest_files':31,'job_peak_commit_bytes':execution['memory_after']['peak_commit'],'php_peak_allocator_bytes':max(p['peak_allocator_bytes'] for p in peaks),'catalog_pages':catalog['pages'],'catalog_tools':catalog['count'],'large':large,'attempt_state':'terminal,no restart','server_closed':True,'not_ui_acceptance':True}
(raw/'summary-sanitized.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2),encoding='utf-8')
table='\n'.join('| '+r['title'].split(' ',1)[0]+' '+('sin CL' if 'noContentLength' in r['title'] else 'con CL' if 'ContentLength' in r['title'] else 'missing' if 'missing snapshot' in r['title'] else 'corrupt' if 'corrupt snapshot' in r['title'] else '')+' | '+('PASS' if r['outcome']=='expected' else 'FAIL harness')+' | '+f"{r['duration_ms']/1000:.2f}s"+' |' for r in rows)
gate=(RUN/'qa-gate.json').read_text(encoding='utf-8').strip()
report=f'''# T12 datasets — QA de transporte, intento1

2026-11-06. **NO-VERDE: 6/13 pasan, 7 fallan, 0 flaky, 0 skipped, 0 retries y 0 errores globales del runner.** Playwright terminó con exit1. No se repite este intento ni se modifica su fixture. T12 y la iniciativa siguen abiertas; no hay aceptación de UI ni cierre de las23tareas.

## Ejecución y aislamiento

Una pasada HTTP/TCP real, sin navegador para los tests, SQLite/storage/cuentas/token ficticios y UUIDpropio. Servidor exclusivo localhost8080, puerto libre comprobado antes del seed y proof de aislamiento del storage. Supervisor34608/sesión41360 terminales; servidor cerrado después. No se accedió a .env real, MySQL, redes sociales, VPS, R2 ni ONNX.

PHP512MiB, post_max_size192MiB, upload_max_filesize64MiB; configuración exclusiva del runner. WindowsJob3GiB **COMMIT agregado**, killOnClose=true, breakaway=false y deadline; picoCOMMIT **{summary['job_peak_commit_bytes']:,}B**. Pico asignadorPHP separado **{summary['php_peak_allocator_bytes']:,}B**. Ninguna de estas cifras se presenta comoRSS ni como medidaLinux/producción. DuraciónPlaywright {results['stats']['duration']/1000:.2f}s.

Manifiesto aprobado31SHA comprobado antes de correr; {len(before)} fuentes/contratos/harness en snapshots antes/después: **0 cambios**. Ledger-lint: exit0, cero incoherencias y15avisos. Coverage-check: exit0, cero referencias rotas y cero criterios huérfanos;10tareas futuras sinmapeo declaradas fuera de estaQA. Guardrail canónico local ejecutado antes del seed. Raw de las puertas conservado.

## Casos reales

| Caso | Resultado | Duración |
|---|---|---|
{table}

Los7FAIL se detienen al crear la tabla pequeña que se pretendía válida: seis ramas HTTP/MCP esperan éxito y obtienen422/isError; el caso03 falla en su create legítimo MIME. La fixture contieneCR en `literal\\r\\né`, mientras `StudioChartData::text` admiteLF/TAB y rechazaCR. **Es un error del harness identificado por inspección de fixture y validador, no un defecto acreditado del producto ni un RED deTDD.** Los tests no capturaron el body de validación y no se lanzó una llamada extra para reconstruirlo. El gate conserva los7fallos reales.

Por ese corte no quedan acreditadas las cadenas completas de permisos/marca de01, replace/historial/409/archivo de02, los3MIME completos de03, descargas seguras/text/JSONtyped de04, snapshots503 de07, ni CRUD/pin/floats0.0/1.0/-0.0/auditoría de10. No se transforma ninguna de ellas enPASS por la existencia de los FeatureKernel previos. Es necesaria una futura corrección de fixture revisada y una nueva autorización de intento, conservando éste intacto.

## Evidencia válida de esta pasada

CSV **25.000.000B** multipart real:50filas,100columnas, extremos leídos porAPI; descargaCSVtext exacta y parserCSV independiente. SHA entrada=salida `{fixtures['csv']['sha256']}`. Metadatos de importación pequeños, sin devolución de segunda tabla completa.

Paginación6MiB:9IDs exactos en2páginas, sin saltos ni celdas truncadas, cada arrayJSON de filas dentro del presupuesto. Cuerpos HTTP53MiB+1 →413 con Content-Length y tambiénchunked sinContent-Length. MCPtext/plain **167.772.161B** stream64KiB, chunked sinContent-Length →413. No se generó un buffer600MB.

Catálogo MCP real del SDK: **{catalog['count']}herramientas en{catalog['pages']}páginas**, IDs/nombres únicos, nueva `manage_studio_dataset` y smoke `list_brands` correctos. No se infiere un catálogo más grande.

ImportMCP: JSONcanónico **52.000.000B**, envelopeJSON-RPC con encoderensure_ascii normal **{fixtures['mcp']['envelope_bytes']:,}B**; transporteTCP real aceptado, sinHTTPKernel/TestCase. PerfilPHP512MiB explícito. DescargaJSON privada streamed comprobada por headers, filenameID/revisión, parser propio,52IDs/100columnas/textoUnicode y SHA idéntico `{fixtures['json']['sha256']}`. Esta prueba acredita ese envelope; no afirma haber enviado160MiB exactos ni serializaciones arbitrariamente infladas.

## Gate canónico literal

```json
{gate}
```

El recuento independiente coincide con lasstats del JSON real y rechaza faltantes/retries/flaky/errors/skips/cambioSHA. `raw/recount.json` y `raw/case-results-sanitized.json` conservan cada caso y mensaje de error sin headers de autorización.

## Manuales pendientes

- [ ] M-01 — Usuario: scheduler/workerVPS, heartbeat y capturas del periodo cerrado.
- [ ] M-02 — Usuario: publicación real porred y smokesBluesky/Threads pendientes.
- [ ] M-03 — Desarrollo+usuario: rendererLinux/Node/Chromium/fuentes/cola y artefactos reales conR2.
- [ ] M-04 — Usuario: ClaudeDesktop sigue aplazado; no se reabre autorización.
- [ ] Editor/tablas/gráficos responsive, interacción y accesibilidad: pendientes del alcance integralT12.

Informe sólo local. Publicar únicamente evidencia saneada seleccionada; no copiar SQLite, auth/private.json, configcache, cookies, tokens, traces ni logsHTTP sin revisión. Se generaPDF por flujo to-pdf con League/CommonMark+temaCSS existente y ChromiumPlaywright ya instalado, en Job3GiB separado después de cerrar el servidor. La rasterizaciónPyMuPDF del PDFreal se inspecciona aparte; no es una screenshot deUI.
'''
(OUT/'report.md').write_text(report,encoding='utf-8')
print(json.dumps(summary,ensure_ascii=True,indent=2))
