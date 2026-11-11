"""Report the immutable attempt2 execution and independent observations."""
import collections, hashlib, json, pathlib, subprocess, sys
sys.stdout.reconfigure(encoding='utf-8',errors='replace')
ROOT=pathlib.Path('C:/laragon/www/metricool')
OUT=pathlib.Path(__file__).resolve().parent
RAW=OUT/'raw'
PLAN=ROOT/'docs/roadmap/2026-11-03-creative-social-suite'
KIT=pathlib.Path('C:/Users/daycr/.codex/plugins/cache/daycry/custom-agents/1.21.1/agent-kits')
def read(path):return json.loads(path.read_text(encoding='utf-8'))
gates={}
for name,script,args in [
    ('qa-gate',KIT/'qa/qa-gate.py',[RAW/'results.json']),
    ('ledger-lint',KIT/'shared/ledger-lint.py',[PLAN/'tasks.md']),
    ('coverage-check',KIT/'qa/coverage-check.py',[PLAN/'tasks.md',PLAN/'test-plan.md',PLAN/'spec.md'])]:
    proc=subprocess.run([sys.executable,str(script),*[str(p) for p in args]],capture_output=True,text=True,encoding='utf-8')
    gates[name]={'exit_code':proc.returncode,'stdout':proc.stdout,'stderr':proc.stderr}
    (RAW/f'{name}.txt').write_text(proc.stdout+proc.stderr,encoding='utf-8')
(RAW/'gates.json').write_text(json.dumps(gates,ensure_ascii=False,indent=2),encoding='utf-8')
gate=json.loads(gates['qa-gate']['stdout'])
coverage=json.loads(next(line for line in gates['coverage-check']['stdout'].splitlines() if line.startswith('{')))
execution=read(RAW/'execution.json'); tests=read(RAW/'test-index.json'); extra=read(RAW/'extra-checks.json')
checks=read(RAW/'caption-independent-checks.json'); observed=read(RAW/'caption-independent/observations.json')
failed_checks=[c for c in checks if not c['passed']]
visual_findings=read(RAW/'visual-findings.json')
composites=read(RAW/'composite-audit.json')
frozen=read(RAW/'reviewed-freeze-manifest.json')
groups={'sources39':{p:v['sha256_after'] for p,v in frozen['files'].items()},'generated76':frozen['generated']}
descriptors=read(ROOT/'storage/framework/testing/t11-captions-root-review/descriptors.json')
groups['descriptors7']={p:v['sha256'] for p,v in descriptors.items()}
final_freeze={name:{'count':len(group),'mismatches':[p for p,h in group.items()
    if not (ROOT/p).is_file() or hashlib.sha256((ROOT/p).read_bytes()).hexdigest()!=h]}
    for name,group in groups.items()}
(RAW/'freeze-output-check.json').write_text(json.dumps(final_freeze,indent=2),encoding='utf-8')
independent_ok=not failed_checks and not execution['changed_sources'] and not any(v['mismatches'] for v in final_freeze.values())
overall='VERDE local del alcance probado' if gates['qa-gate']['exit_code']==0 and independent_ok and not visual_findings else 'NO ACEPTADO: QA2-V01 Important de legibilidad'
counts=collections.Counter(t['project'] for t in tests)
rows=[]
for title in dict.fromkeys(t['title'] for t in tests):
    group=[t for t in tests if t['title']==title]
    name=group[0]['file'].replace('\\','/').split('/')[-1]
    short=title.replace('Studio captions ','Captions ').replace('Studio timeline: ','Timeline: ').replace('Studio: ','')
    states=['PASA' if any(t['project']==p and t['statuses']==['passed'] for t in group) else 'FALLA' for p in ['desktop','mobile','mobile-360']]
    rows.append('| '+name+' — '+short+' | '+' | '.join(states)+' |')
windows=[]; selections=[]
for entry in observed['media']:
    project=next(p for p in ['mobile-360','mobile','desktop'] if f'/{p}/' in entry['path'])
    counts_by_frame={f['frame']:f['threshold_counts']['200'] for f in entry['frames']}
    if '/captions-mp4.' in entry['path'] or '/captions-gif.' in entry['path']:
        kind=pathlib.Path(entry['path']).suffix[1:]
        ratios=', '.join(f"{p['frames']}: {p['peak_mask_luminance_ratio']:.6f}" for p in entry['comparisons'])
        windows.append(f'| {project}/{kind} | {entry["decoded_frame_count"]} | {counts_by_frame} | {ratios} |')
    else:
        name=pathlib.Path(entry['path']).stem.replace('captions-selection-','')
        selections.append(f'| {project}/{name} | {entry["decoded_frame_count"]} | {counts_by_frame} |')
tone_rows=[]
for sample in composites:
    data=sample['independent_audio']; left=data['left']; right=data['right']
    tone_rows.append(f"| {sample['project']} | {left['video']['amplitude']:.6f}/{right['video']['amplitude']:.6f} | {left['music']['amplitude']:.6f}/{right['music']['amplitude']:.6f} | {left['attack']['amplitude']:.6f}/{right['attack']['amplitude']:.6f} | {left['release']['amplitude']:.6f}/{right['release']['amplitude']:.6f} | {left['trimMarker']['amplitude']:.6f}/{right['trimMarker']['amplitude']:.6f} | {left['tail']['rms']:.6f}/{right['tail']['rms']:.6f} |")
memory=execution['memory_after']; observer_memory=read(RAW/'observer-memory-collect-evidence.json')
font_rows=[]
for archive in observed['svg_archives']:
    for svg in archive['entries']:
        font_rows.append(f"| {archive['path'].split('/')[-2]}/{svg['name']} | {svg['text_nodes']} | {', '.join(svg['embedded_font_sha256'])} | {svg['expected_private_font_present']} |")
body=f'''# QA independiente T11 — intento 2

| Campo | Resultado |
|---|---|
| Fecha | 2026-11-05 |
| Veredicto de qa-gate | **{gate['verdict']}**, exit {gates['qa-gate']['exit_code']} |
| Evaluación del alcance local | **{overall}** |
| Resultado real | {gate['counts']['passed']}/{len(tests)} correctos; {gate['counts']['failed']} fallos |
| Fuentes | {execution['source_count']} SHA antes/después; {len(execution['changed_sources'])} cambios |
| Escenarios |12 archivos,3 proyectos; conteo tomado del JSON, no supuesto |
| Sesión / PID |77306; helper{execution['helper_pid']}, Playwright{execution['playwright_pid']} |
| Raíz | `{execution['runroot']}` |

## Veredicto y alcance

```json
{gates['qa-gate']['stdout'].strip()}
```

Exit Playwright{execution['exit_code']}; duración{extra['stats']['duration']/1000:.3f}s; errores de runner{len(extra['runner_errors'])}; reintentos{extra['retried_results']}. Desktop1440×900, mobile375×812 y mobile-360360×740: {dict(counts)}. Ejecución única, sin reiniciar por timeout de observación ni repetir casos. Las comprobaciones independientes de captions son {len(checks)-len(failed_checks)}/{len(checks)} correctas; detalle y cualquier discrepancia en [caption-independent-checks.json](raw/caption-independent-checks.json).

Este informe aporta evidencia local para que el orquestador decida los estados de T07/T11. **QA no modifica ni cierra tareas, plan o spec**. El estado de entrada era6/23 tareas completadas y T07/T11 en progreso; no se acepta el roadmap completo por superar estos recorridos. La [QA1 original](../final-qa/report.md) conserva su NO-VERDE62/63 y no se altera. El diagnóstico SQLite y la regresión focal posteriores se documentan en [sqlite-concurrency](../sqlite-concurrency/review.md); la excepción del primer recorrido no se inventa retrospectivamente.

### QA2-V01 — Important: subtítulos casi ilegibles con ajuste permitido

El recorrido UI de captions importa texto, cambia el tamaño a22px y conserva el estilo por defecto: fill blanco, contorno negro de grosor2. Sobre el lienzo negro, las dos líneas son prácticamente ilegibles mientras el emoji púrpura permanece visible. QA y el orquestador inspeccionaron la misma evidencia nueva. Es una limitación funcional de lectura en un ajuste permitido, no una preferencia estética. El test automático puede pasar porque no comprueba legibilidad de ese estilo; los renders medidos con strokeWidth0 son otro escenario.

La causa raíz no se ha probado: que el contorno cubra el glifo por paint order sigue siendo hipótesis. No se afirma ratioWCAG ni se aplica un parche durante QA. T07/T11 permanecen abiertas; root hará reproducción RED, corrección y revisión afectada después de liberar el slot nativo. Se conserva la misma pasada, sin repetirla ni detenerla.

[Hallazgo estructurado](raw/visual-findings.json). Recorte fiel del original mobile375, x0/y900/375×600; original completo conservado en raw/artifacts.

![QA2-V01 detalle de preview casi ilegible](raw/mobile-preview-detail.png)

## Aislamiento, límite OS y estabilidad

URL http://localhost:8080 validada por el guardrail oficial. El puerto estaba libre; el runner exige servidor propio y `reuseExistingServer:false`. SQLite, storage y cuentas desechables pertenecen al UUID de esta pasada; sin reutilizar ni detener Node ajeno. Inicio{execution['started']}, fin{execution['finished']}. Sin .env/MySQL/VPS, ONNX/test_background/imaging, bucket R2 real ni publicación social.

El helper QA2 y sus hijos están en un JobObject de **3GiB de COMMIT agregado**, consultado al kernel: límite{memory['limit']}B, kill-on-close={memory['kill_on_close']}, breakaway={memory['breakaway']}, pico{memory['peak_commit']}B ({memory['peak_commit']/1024**3:.3f}GiB). El observador posterior y sus procesos FFmpeg/Chromium/Python tienen un Job equivalente: pico{observer_memory['memory_after']['peak_commit']}B, límite{observer_memory['memory_after']['limit']}B. **No es RSS ni una reserva previa de3GiB.** El límite se impone al árbol, además de los supervisores nativos de fase de la aplicación. No se induce OOM.

Snapshot directo: {execution['source_count']} rutas, incluidos API OpenAPI, guía API/MCP, README, módulo MCP, deploy, document-contract, matriz, contrato captions, ledger/test-plan, renderer estático/vector/fuentes, fixtures reales `tests/Fixtures/fonts`, soporte `.mjs`/`.d.mts` y public/build completo. Los hashes de [sources-before](raw/sources-before.json) y [sources-after](raw/sources-after.json) coinciden. T12 estuvo congelada y no se excluyó.

Adicionalmente se comprobaron las39 fuentes revisadas,76 generados y siete descriptores tanto antes del arranque como al terminar: [pre](raw/freeze-input-check.json), [post](raw/freeze-output-check.json). Cuatro fuentes del freeze están fuera de los árboles generales: test_caption_archive.py y tres helpers de storage. Su baseline adicional es el [manifiesto revisado](raw/reviewed-freeze-manifest.json), verificado antes; no se modificó el archivo sources-before para fingir que esas cuatro estaban en el snapshot directo. No hay exenciones de integridad.

## Cobertura, ledger y trazabilidad

Ledger-lint exit{gates['ledger-lint']['exit_code']}:0 incoherencias/18 avisos,16 Changelog y dos cabeceras de revisión no canónicas. No se editaron durante QA. Coverage-check exit{gates['coverage-check']['exit_code']}: {coverage['broken_refs']} referencias rotas, {len(coverage['tests_sin_referencia'])} tests sin referencia y ningún GWT huérfano. T11 incluye E2E11–25 yM03; T07 comparte exportaciones estáticas y multipágina. Sin bloques API-xx/A11Y-xx definidos ni mínimo unitario configurado; no se inventa porcentaje de cobertura de código o auditoría axe.

La puerta sigue mostrando tareas fuera del alcance sin mapeo: **{', '.join(coverage['tasks_sin_cobertura'])}**. No reciben exención ni verde global. T10/E2E09–10 siguen sin ejecución por la restricción de memoria/ONNX. Salidas y códigos literales: [gates.json](raw/gates.json).

| Criterio T11/T07 | Evidencia actual | Resultado / límite |
|---|---|---|
| v2, corte, pistas, ventanas, página2, undo/reapertura | E2E11–19 y regresiones Studio | Resultado individual del JSON en tabla inferior |
| Compuesto vídeo/audio/overlays/fade, MP4/GIF, revisión y borrador | E2E21; recálculo de MP4/GIF descargados | Muestras nuevas porviewport, sin reutilizar resultados QA1 |
| Cola, cancel/retry, historial paginado, orden/escala | E2E20/22 | Worker real local y55 exportaciones para paginar |
| Importar/editar/estilo/offset/eliminar/undo/reabrir y exportSRT/VTT/error | E2E23 | Unicode y tiempos observados; no ASR/traducción |
| Ventana `[start,end)` y opacidad en fade | E2E24 + observador independiente | Decode antes/inicio/dentro/fin/después, ratios y máscaras |
| A sola, C→A y A→C | E2E24 + frames decodificados | Ventanas de origen/cue y orden de páginas observables |
| Fuente privada y SVG/PNG/PDF reales multipágina | E2E24 + XML/ZIP/SHA/raster independiente | Texto/fuente portable y contenido, no solo firma o DTO |
| Revisión fijada tras limpiar subtítulos actuales | E2E24,25 | Outputs revisión1 y documento actualrevisión2 |
| UI/API/MCP abilities, propietario y conflicto | E2E25 + revisión backend previa | Canales compartidos; no publicación externa |
| Bordes LF, separadores, importappend e IDs globales | A2/B2/C2 y testsPHP/JS previos fijados en freeze | Revisados antes de QA; no se suman como tests de esta pasada |
| Presupuesto OS | Job QA/observador y runtime de fases | WindowsCOMMIT medido; no RSS/multimediaLinux/VPS |

## Medición propia de vídeo y audio compuesto

Los MP4/GIF descargados de **esta QA2** se decodificaron nuevamente, sin generar más exportaciones. MP4H264100×80,42frames/3,5s,AAC48000Hz/estéreo; GIF50×40,42frames/3,5s y sin audio. Se contrastan píxeles de overlays/fade, movimiento RMS, revisión1 frente a actual2 y borrador con marca/medio exactos. Detalle completo: [composite-audit.json](raw/composite-audit.json) y samples/desktop,mobile,mobile-360.

El recálculo Python del PCM es independiente del helperJS del test. Mesetas440/880 deben estar0,04–0,06; rampas0,015–0,025; marcador1760 fuera deltrim<0,003, colaRMS<0,002 y diferenciaL/R<0,001. Ambos canales se comprueban. El880 posterior al fin delvídeo acredita continuidad de música; no es una valoración musical humana.

| Viewport | 440 L/R | 880 L/R | Ataque L/R | Salida L/R | Marcador1760 L/R | ColaRMS L/R |
|---|---|---|---|---|---|---|
{chr(10).join(tone_rows)}

## Medición propia de subtítulos

El observador no importa captions.js, captions-renderer.js ni sus asserts. Trabaja sobre los bytes descargados: ffprobe/FFmpeg, ZIP/XML, SHA, Pillow y PyMuPDF; Chromium sin red para volver a rasterizar el SVG portable. El perfil de observación registra archivos, índices y ventanas esperadas; no deduce PASS del nombre del caso. SVG contiene texto y la fuente WOFF2 embebida, cuyo SHA se contrasta con el snapshot privado validado. Los PNG y cada página PDF se rasterizan realmente; una firma PDF o tamañoZIP no basta.

La fixture de ventana es160×120/FPS12, cue«Hola» global[0,25,1,25), con transición entre0,5 y1s. Frames0/2 anteriores,3 en inicio,4/6/9/14 activos,15 en fin exacto y17 posterior. Se cuentan múltiples umbrales16/64/128/200/240, bbox, luminancia y diferenciasRGB. Se compara luminancia de la máscara de glifos muy brillantes antes ydurante elfade; ratio aceptado0,94–1,06, con tolerancia a codec. No se afirma inferir el número interno de llamadas de dibujo cuando dos composiciones producen píxeles matemáticamente idénticos.

| Viewport/formato | Frames decodificados | Píxeles>200 por índice | Ratio máscara brillante entre frames |
|---|---|---|---|
{chr(10).join(windows)}

Selección fuente: A dura2s y tiene fade0,5s; cueAA1,8–1,95. C contiene cueCCCCCCCC3,8–4 global. A sola conserva elcue completo; C→A lo remapea trasC; A→C cambia propiedad de ventana en elmidpoint para conservar la cue de C sin duplicar la deA. El contenido de las cues ylos fondos permiten distinguir fase yorden. No se reusa el compilador de selección del producto para calcular estos índices.

| Viewport/selección | Frames reales | Píxeles>200 por índice |
|---|---|---|
{chr(10).join(selections)}

| SVG/página | Texto vectorial | SHA WOFF2 embebido | Coincide fuente privada |
|---|---|---|---|
{chr(10).join(font_rows)}

Artefactos: [observaciones](raw/caption-independent/observations.json), [checks](raw/caption-independent-checks.json), [estáticos](raw/caption-static-observations.json), [raster SVG](raw/svg-independent-pixel-observations.json). Los PNG extraídos, PDF, SVG extraídos/rasterizados y MP4/GIF/selecciones están en samples/captions/<viewport>/. El SRT/VTT descargado se conserva en raw/artifacts, con Unicode y tiempos del recorridoUI.

## Resultados por escenario

Cada caso se ejecutó una vez por proyecto; [test-index.json](raw/test-index.json) conserva todos los resultados/retries/errores. La tabla no sustituye el JSON original.

| Archivo/caso | Desktop | Mobile375 | Mobile360 |
|---|---|---|---|
{chr(10).join(rows)}

## Inspección visual y manuales

Las capturas del editor de captions, diálogo y medios se inspeccionan porviewport. Se conservan originales completos; enPDF se puede mostrar un detalle superior explícitamente identificado para mantener legibles controles. Losframes de ventana/fade/selección y rasterSVG/PDF se inspeccionan aparte. El resultado exacto de la revisión visual y delPDF real se conserva en raw/pdf-validation.json.

Las tres imágenes siguientes son detalles recortados del panel para conservar legibilidad en PDF; `raw/caption-screenshot-crops.json` registra las coordenadas. Los originales completos se conservan en screenshots/captions-<viewport>-full.png y raw/artifacts, sin alterar sus píxeles.

### Captions — desktop

![Captions desktop](screenshots/captions-desktop.png)

### Captions — mobile375

![Captions mobile375](screenshots/captions-mobile.png)

### Captions — mobile360

![Captions mobile360](screenshots/captions-mobile-360.png)

Linuxcgroup previo con procesosPython no es multimediaLinux/VPS. R2 simulado no certifica bucketreal. Permanecen:

- [ ] **M01 — Usuario:** scheduler/worker/heartbeat y muestras delVPS, sin volver a pedirSSH.
- [ ] **M02 — Usuario:** smokes reales Bluesky/Threads ypublicación porred; sin resultados comunicados.
- [ ] **M03 — Desarrollo+usuario:** Node/Chromium/fuentes/cola ymedios/R2 reales delservidor, multimediaLinux ypolíticasOS efectivas.
- [ ] **M04 — Usuario:** ClaudeDesktop aplazado; no se reabre su autorización.
- [ ] Revisión audiovisual humana de los archivos descargados; las medidas no sustituyen apreciación de producto.

ASR/traducción sigue enT14, ONNX/T10 bloqueado y ClaudeCLI final después detodo elroadmap. Sin Jira/Confluence, knowledge, commits ni cierre de estados. No se declara paridad Metricool/Canva.

## Evidencias e informe PDF

[Manifiesto](manifest.json) · [Ejecución/PID/límite](raw/execution.json) · [JSON real](raw/results.json) · [stdout](raw/playwright.stdout.txt) · [stderr](raw/playwright.stderr.txt). QA1 se preserva íntegra.

PDF por el adaptador existente de to-pdf: CommonMark instalado→HTML/temaCSS→Chromium instalado; sin instalaciones. Rasterización de las páginas delPDF real con PyMuPDF y revisión visual; no se confunde una capturaHTML con ese PDF.
'''
for old,new in {
    'helper33672':'helper 33672','Playwright6096':'Playwright 6096','Exit Playwright':'Exit Playwright ',
    'duración':'duración ', 'runner0':'runner 0','reintentos0':'reintentos 0',
    'antes/después':'antes/después','COMMIT agregado':'COMMIT agregado',
    '3GiB':'3 GiB','6/23':'6/23','72correctos':'72 correctos','0cambios':'0 cambios',
    'Desktop1440':'Desktop 1440','mobile375':'mobile 375','mobile-360360':'mobile-360 360',
    '12 archivos,3':'12 archivos, 3','era6':'era 6','NO-VERDE62':'NO-VERDE 62',
    'Inicio2026':'Inicio 2026','fin2026':'fin 2026','límite322':'límite 322',
    'B, kill':' B, kill','pico':'pico ', 'de3 GiB':'de 3 GiB','39 fuentes':'39 fuentes',
    '39fuentes':'39 fuentes','76 generados':'76 generados','siete descriptores':'siete descriptores',
    '0 incoherencias/18':'0 incoherencias/18','16 Changelog':'16 Changelog',
    'E2E11':'E2E-11','E2E21':'E2E-21','E2E20':'E2E-20','E2E23':'E2E-23','E2E24':'E2E-24','E2E25':'E2E-25',
    'yM03':'y M03','testsPHP/JS':'tests PHP/JS','WindowsCOMMIT':'Windows COMMIT',
    'MP4H264':'MP4 H264','42frames':'42 frames','3,5s':'3,5 s','AAC48000Hz':'AAC 48000 Hz',
    'helperJS':'helper JS','PCM es':'PCM es','estar0':'estar 0','rampas0':'rampas 0',
    'marcador1760':'marcador 1760','colaRMS':'cola RMS','diferenciaL/R':'diferencia L/R',
    'deltrim':'del trim','El880':'El 880','delvídeo':'del vídeo','tamañoZIP':'tamaño ZIP',
    'es160':'es 160','cue«':'cue «','entre0':'entre 0','y1s':'y 1 s','umbral16':'umbral 16',
    'ydurante':'y durante','elfade':'el fade','aceptado0':'aceptado 0','diferenciasRGB':'diferencias RGB',
    'dura2s':'dura 2 s','fade0':'fade 0','cueAA1':'cue AA 1','cueCCCCCCCC3':'cue CCCCCCCC 3',
    'elcue':'el cue','trasC':'tras C','elmidpoint':'el midpoint','deA':'de A','ylos':'y los','yorden':'y orden',
    'recorridoUI':'recorrido UI','porviewport':'por viewport','enPDF':'en PDF', 'controles':'controles',
    'Losframes':'Los frames','rasterSVG/PDF':'raster SVG/PDF','delPDF':'del PDF',
    'Linuxcgroup':'Linux cgroup','procesosPython':'procesos Python','multimediaLinux':'multimedia Linux',
    'bucketreal':'bucket real','delVPS':'del VPS','pedirSSH':'pedir SSH','ypublicación':'y publicación',
    'porred':'por red','Desarrollo+usuario':'Desarrollo + usuario','ymedios':'y medios','delservidor':'del servidor',
    'ypolíticasOS':'y políticas OS','ClaudeDesktop':'Claude Desktop','enT14':'en T14','ClaudeCLI':'Claude CLI',
    'detodo elroadmap':'de todo el roadmap','instalado→HTML/temaCSS→Chromium':'instalado → HTML/tema CSS → Chromium',
    'capturaHTML':'captura HTML', 'revisión1':'revisión 1','actual2':'actual 2','actualrevisión2':'actual revisión 2',
}.items():body=body.replace(old,new)
(OUT/'report.md').write_text(body,encoding='utf-8')
print(json.dumps({'gate':gate,'independent_checks':len(checks),'independent_failures':failed_checks,
                  'overall':overall,'freeze_after':final_freeze},ensure_ascii=False,indent=2))
