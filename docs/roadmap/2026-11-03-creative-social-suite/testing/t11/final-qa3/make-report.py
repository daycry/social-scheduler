"""QA3 report from original runner JSON and independently observed new media."""
import collections,json,pathlib,subprocess,sys
from datetime import datetime,timedelta
sys.stdout.reconfigure(encoding='utf-8',errors='replace')
ROOT=pathlib.Path('C:/laragon/www/metricool')
OUT=pathlib.Path(__file__).resolve().parent
RAW=OUT/'raw'; PLAN=ROOT/'docs/roadmap/2026-11-03-creative-social-suite'
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
gate=json.loads(gates['qa-gate']['stdout']); execution=read(RAW/'execution.json')
local_date=(datetime.fromisoformat(execution['finished'])+timedelta(hours=1)).date().isoformat()
tests=read(RAW/'test-index.json'); extra=read(RAW/'extra-checks.json')
private=read(RAW/'caption-independent-checks.json')
contrast=read(RAW/'default-contrast/checks.json'); contrast_rows=read(RAW/'default-contrast/observations.json')
visual=read(RAW/'visual-review.json')
private_ok=len(private)==42 and all(c['passed'] for c in private)
contrast_ok=len(contrast)==24 and all(c['passed'] for c in contrast)
post=read(RAW/'freeze-output-check.json')
stable=not execution['changed_sources'] and not any(g['mismatches'] for g in post.values())
accepted=(gates['qa-gate']['exit_code']==0 and private_ok and contrast_ok and visual['accepted'] and stable
          and extra['retried_results']==0 and not extra['runner_errors'])
verdict='ACEPTABLES LOCALMENTE T07/T11; cierre a decisión del orquestador' if accepted else 'NO ACEPTADO / evidencia o corrección pendiente'
coverage=json.loads(next(line for line in gates['coverage-check']['stdout'].splitlines() if line.startswith('{')))
memory=execution['memory_after']; observer_memory=read(RAW/'observer-memory-collect-evidence.json')['memory_after']
observations=read(RAW/'caption-independent/observations.json')
contrast_table=[]
for project in ['desktop','mobile','mobile-360']:
    pair={r['variant']:r for r in contrast_rows if r['project']==project}
    if set(pair)!= {'control','outline'}:continue
    for kind in ['png','mp4','gif','preview']:
        c=pair['control']['preview'] if kind=='preview' else pair['control']['formats'].get(kind)
        a=pair['outline']['preview'] if kind=='preview' else pair['outline']['formats'].get(kind)
        if not c or not a:continue
        contrast_table.append(f"| {project}/{kind} | {c['white']} | {a['white']} | {a['white']/c['white']:.6f} | {a['energy']/c['energy']:.6f} | {a.get('before_cue',{}).get('white','—')} |")
window_table=[];selection_table=[]
for media in observations['media']:
    project=next(p for p in ['mobile-360','mobile','desktop'] if f'/{p}/' in media['path'])
    values={f['frame']:f['threshold_counts']['200'] for f in media['frames']}
    if '/captions-mp4.' in media['path'] or '/captions-gif.' in media['path']:
        ratio='/'.join(f"{c['peak_mask_luminance_ratio']:.6f}" for c in media['comparisons'])
        window_table.append(f"| {project}/{pathlib.Path(media['path']).suffix[1:]} | {media['decoded_frame_count']} | {','.join(str(values[i]) for i in [0,2,3,4,9,14,15,17])} | {ratio} |")
    else:
        name=pathlib.Path(media['path']).stem.replace('captions-selection-','')
        selection_table.append(f"| {project}/{name} | {media['decoded_frame_count']} | {values} |")
case_table=[]
for title in dict.fromkeys(t['title'] for t in tests):
    group=[t for t in tests if t['title']==title]
    short=title.replace('Studio captions ','Captions ').replace('Studio timeline: ','Timeline: ').replace('Studio: ','')
    filename=group[0]['file'].replace('\\','/').split('/')[-1]
    states=['PASA' if any(t['project']==p and t['statuses']==['passed'] for t in group) else 'FALLA' for p in ['desktop','mobile','mobile-360']]
    case_table.append('| '+filename+' — '+short+' | '+' | '.join(states)+' |')
composite_table=[]
for sample in read(RAW/'composite-audit.json'):
    a=sample['independent_audio'];l=a['left'];r=a['right']
    composite_table.append(f"| {sample['project']} | {l['video']['amplitude']:.6f}/{r['video']['amplitude']:.6f} | {l['music']['amplitude']:.6f}/{r['music']['amplitude']:.6f} | {l['attack']['amplitude']:.6f}/{r['attack']['amplitude']:.6f} | {l['release']['amplitude']:.6f}/{r['release']['amplitude']:.6f} | {l['tail']['rms']:.6f}/{r['tail']['rms']:.6f} |")
counts=collections.Counter(t['project'] for t in tests)
body=f'''# QA independiente T07/T11 — intento 3

| Campo | Resultado |
|---|---|
| Fecha local de finalización | {local_date}, Europe/Madrid (CET) |
| Gate automático | **{gate['verdict']}**, exit {gates['qa-gate']['exit_code']} |
| Aceptación del alcance | **{verdict}** |
| Casos reales | {gate['counts']['passed']}/{len(tests)} pasan; {gate['counts']['failed']} fallos |
| Observación independiente | Privados/ventanas/selección {sum(c['passed'] for c in private)}/{len(private)}; DEFAULT/CONTROL {sum(c['passed'] for c in contrast)}/{len(contrast)} |
| Integridad | {execution['source_count']} SHA directos, {len(execution['changed_sources'])} cambios; unión39/76/7 comprobada |
| Sesión / PID |36509; helper {execution['helper_pid']}, Playwright {execution['playwright_pid']} |
| Raíz propia | `{execution['runroot']}` |

## Veredicto determinista

```json
{gates['qa-gate']['stdout'].strip()}
```

Una única pasada de12specs en desktop1440×900, mobile375×812 y mobile-360360×740. Conteo obtenido del JSON original: {dict(counts)}. Exit Playwright {execution['exit_code']}; duración {extra['stats']['duration']/1000:.3f}s; errores de runner {len(extra['runner_errors'])}; resultados con retry>0: {extra['retried_results']}. No se reinició el proceso por timeout de observación ni se excluyeron resultados del gate. [JSON real](raw/results.json) · [checks adicionales](raw/extra-checks.json).

La aceptación local se basa conjuntamente en gate, medios nuevos, inspección visual e integridad. QA no cambia estados del ledger/spec/plan ni cierra el roadmap de23tareas. T07/T11 entraron en progreso, con6/23 completadas; root decide su cierre. VPS/R2/social real, T10/ONNX y otros requisitos externos no se aceptan por esta pasada.

## QA2-V01: verificación de legibilidad con DEFAULT y CONTROL

El Important de QA2 era reproducible: Arial22normal blanco, contorno negro2 sobre negro dejaba las letras casi ilegibles. Root aisló el orden de pintura y cambió únicamente paintFirst a stroke en textos temporales de captions. No se cambiaron JSON de estilo, tamaños admitidos ni defaults globales de Fabric. A3/B3 sin gaps; C2/D1 preservadas, C/Ddelta3=false. Los dos focales históricos fallidos siguen siendo fallos de fixture/comparador; no se reetiquetan como PASS ni se atribuyen a QA3.

E2E23/24 actuales y la observación propia verifican el estilo por defecto, además de mantener la fixture privada con strokeWidth0. La serie nueva no tiene emoji:320×180,Arial22normal,texto«Modificado\\nsegunda línea»,fill blanco,stroke negro2(DEFAULT) y controlstroke0(CONTROL). Cue[0,25,1,25),FPS12. Se observan archivos PNG/MP4/GIF descargados de esta ejecución, sin crear otras exportaciones. PNG estático0,5s; MP4/GIF frame6 activo y frame0 anterior,18frames completos.

Métrica fijada antes de ejecutar: **min(R,G,B)>=200**, energía=sum(minRGB/255), umbrales inclusivos16/64/128/199/200/201/240 y recuento de píxeles exactamente200. Control PNG sin escala>1000blancos; controles de codec>500; DEFAULT≥50% de blancos y energía del CONTROL. El frame anterior al cue exige0blancos. No se cambió comparador ni umbral según los resultados nuevos. La serie privada conserva su propio comparador>200 y sus42checks, sin mezclar definiciones.

| Viewport/formato | CONTROL blancos | DEFAULT blancos | Razón blancos | Razón energía | Antes cue DEFAULT |
|---|---|---|---|---|---|
{chr(10).join(contrast_table)}

El preview se mide sobre el screenshot real, sin tomar el DTO del test como único oráculo. Se aísla una única ROI negra con aspecto320:180,ancho200–360,alto100–205,desviación<0,03 y componente negra>85% de bbox. Una ROI ambigua/ausente falla. Se guardan coordenadas, dimensiones y SHA del original: [observaciones](raw/default-contrast/observations.json). HTML blanco y headers no satisfacen ese perfil. La razón se calcula bajo la misma escala CSS; el control físico exige>500blancos y la calibración absoluta>1000 se contrasta en PNG320×180 sin redimensionar.

### Preview375 nuevo: DEFAULT frente a CONTROL

| DEFAULT22/contorno2 | CONTROL22/contorno0 |
|---|---|
| ![DEFAULT375](raw/default-contrast/mobile-outline-preview-crop.png) | ![CONTROL375](raw/default-contrast/mobile-control-preview-crop.png) |

### Referencia histórica QA2, sin usarla como esperado

![Preview oscuro histórico](raw/default-contrast/historical-qa2-preview-canvas.png)

La referencia antigua incluye el emoji y pertenece a otro snapshot; se presenta para mostrar el defecto, no para fijar los umbrales de QA3 ni como comparación numérica del mismo documento. QA1 y QA2 conservan sus resultados originales. La decisión visual actual consta en [visual-review.json](raw/visual-review.json). No se afirma un ratioWCAG no medido.

## Medios, fuente privada y selección: conservar la aceptación previa

Las42comprobaciones independientes de QA2 se ejecutaron sobre nuevos archivos QA3, sin importar captions.js/captions-renderer.js ni sus asserts. Se decodifican MP4/GIF con ffprobe/FFmpeg, ZIP/XML y SHA; se rasterizan PNG/PDF reales y SVG portable con Chromium sin red. Se contrasta la WOFF2 embebida con la fuente privada validada y se comprueba su carga real. SRT/VTT preserva Unicode, dos líneas y tiempos desplazados0,350–0,850/0,850–1,350.

Fixture privada160×120/FPS12,«Hola»,cue[0,25,1,25) con transición0,5–1s. Columnas de blancos>200 corresponden a frames0,2,3,4,9,14,15,17; se compara luminancia de máscara de glifos entre4→9 y4→14,ratio permitido0,94–1,06. El fin exacto es exclusivo. No se infiere el número interno de llamadas de dibujo cuando dos operaciones producen los mismos píxeles.

| Viewport/formato | Frames | Blancos por índice | Razones de luminancia |
|---|---|---|---|
{chr(10).join(window_table)}

Selección fuente: A2s/fade0,5s con cueAA1,8–1,95; cueCCCCCCCC enC3,8–4 global. A sola conserva la ventana completa, C→A remapea las dos fuentes y A→C cambia propiedad en elmidpoint de salida sin duplicar la cue. Los índices esperados se deducen de ese contrato, sin llamar al compilador del producto.

| Viewport/selección | Frames reales | Blancos por frame observado |
|---|---|---|
{chr(10).join(selection_table)}

Detalle: [42checks](raw/caption-independent-checks.json), [24checks DEFAULT/CONTROL](raw/default-contrast/checks.json), [frames/probes](raw/caption-independent/observations.json), [estáticos](raw/caption-static-observations.json), [SVG rasterizado](raw/svg-independent-pixel-observations.json), [SRT/VTT](raw/caption-text-observations.json). Los archivos y PNG decodificados están en samples/ y raw/. La métrica numérica se complementa con inspección de imágenes actuales legibles.

## Compuesto audiovisual y flujos conservados

Se vuelve a decodificar la descarga de E2E21: MP4H264100×80,42frames/3,5s,AAC48k estéreo; GIF50×40,42frames/3,5s sin pista de audio. Se comprueban overlays/fade/movimiento, revisión1 frente a edición actual2 y borrador con brand_id/media_ids exactos. La mezcla440/880 mantiene rampas, recorte/loop, música tras terminar elvídeo y cola silenciosa en ambos canales. El recálculo Python delPCM no importa el helperJS de test.

| Viewport | 440 L/R | 880 L/R | Ataque L/R | Salida L/R | ColaRMS L/R |
|---|---|---|---|---|---|
{chr(10).join(composite_table)}

No se confunden esos tonos sintéticos con aceptación musical humana. Las mediciones completas incluyen marcador1760fuera deltrim y diferenciaL/R en [composite-audit.json](raw/composite-audit.json). Los recorridos de timeline/audio, estáticos multipágina, conflicto, plantillas, métricas y marca se mantienen en los12specs actuales.

## Integridad, guardrails y memoria OS

URL http://localhost:8080 validada por guardrail oficial y puerto libre al arrancar. Runtime local con SQLite/storage/usuarios UUID propios y `reuseExistingServer:false`; no servidor ni Node ajeno. Inicio {execution['started']}; fin {execution['finished']}. No se leyó .env para este servidor ni se usó MySQL, VPS, ONNX/imaging/test_background, bucket real o publicación social.

Snapshot directo {execution['source_count']} rutas antes/después con {len(execution['changed_sources'])} cambios: código/UI/renderer/fuentes/fixtures, API, guías/README/móduloMCP/deploy/document-contract, matriz, contratos, ledger/test-plan, `.mjs`/`.d.mts` y public/build. Las cuatro rutas adicionales de QA2 se integran ahora directamente desde la unión revisada. T12 estuvo congelada y no se excluyó. [SHA antes](raw/sources-before.json) · [después](raw/sources-after.json). La unión39fuentes+76generados+7descriptores nueva coincide antes y después, sin usar legacyfreeze ni ajustar el esperado posterior: [pre](raw/freeze-input-check.json), [post](raw/freeze-output-check.json).

JobObject del runner/hijos: límite{memory['limit']}B de **COMMIT agregado**, kill-on-close={memory['kill_on_close']}, breakaway={memory['breakaway']}, pico{memory['peak_commit']}B. Observador posterior: límite{observer_memory['limit']}B,pico{observer_memory['peak_commit']}B. Cada árbol está protegido por3GiB; **no esRSS ni reserva previa**. PDF usa también el wrapper protegido y conserva su registro en raw/observer-memory-render-pdf.json. No se induceOOM. Linuxcgroup previo con Python no acredita multimediaLinux/VPS.

## Cobertura y resultado por escenario

Ledger-lint exit{gates['ledger-lint']['exit_code']}:0incoherencias/16avisos Changelog. Coverage-check exit{gates['coverage-check']['exit_code']}: {coverage['broken_refs']}referencias rotas y {len(coverage['tests_sin_referencia'])}tests sin referencia; ningún GWT huérfano. T07/T11 están mapeadas. Tareas sin mapeo fuera de este alcance: **{', '.join(coverage['tasks_sin_cobertura'])}**; no tienen exención ni aceptación global. T10/E2E09–10 no ejecutados por la restricción ONNX/memoria. Sin bloques API-xx/A11Y-xx ni mínimo unitario configurado, no se inventa cobertura de código ni auditoría axe. [gates.json](raw/gates.json).

| Archivo/caso | Desktop | Mobile375 | Mobile360 |
|---|---|---|---|
{chr(10).join(case_table)}

## Manuales y decisión de cierre

- [ ] **M01 — Usuario:** scheduler/worker/heartbeat y muestras deVPS; no se solicitaSSH de nuevo.
- [ ] **M02 — Usuario:** smokes Bluesky/Threads y publicación real porred; resultados aún no comunicados.
- [ ] **M03 — Desarrollo+usuario:** renderer Node/Chromium/fuentes/colas y R2 reales delservidor; multimediaLinux/políticasOS efectivas.
- [ ] **M04 — Usuario:** ClaudeDesktop aplazado; no se reabre autorización.
- [ ] Revisión audiovisual humana de los medios descargados.

ASR/traducción sigue enT14 y ClaudeCLI final después de TODO elroadmap. No se declara paridad Metricool/Canva ni aceptación de las23tareas. No commits, Jira/Confluence, knowledge ni handoffdocumenter porQA. Root recibe el resultado local y decide estados T07/T11 sin cerrar por ello plan/spec globales.

## Artefactos

[Manifiesto](manifest.json) · [Ejecución/PID/pico](raw/execution.json) · [JSON original](raw/results.json) · [stdout](raw/playwright.stdout.txt) · [stderr](raw/playwright.stderr.txt). PDF mediante CommonMark→HTML/tema de to-pdf→Chromium ya instalado; rasterización del PDF real con PyMuPDF e inspección de sus páginas, sin instalaciones. La revisión visual y los recortes documentados no alteran los PNG originales.
'''
for old,new in {
    '12specs':'12 specs','desktop1440':'desktop 1440','mobile375':'mobile 375',
    'mobile-360360':'mobile-360 360','23tareas':'23 tareas','con6/23':'con 6/23',
    'Arial22normal':'Arial 22 normal','negro2':'negro 2','controlstroke0':'control stroke 0',
    '320×180,Arial':'320×180, Arial','normal,texto':'normal, texto','texto«':'texto «',
    'cue[':'cue [','PNG estático0':'PNG estático 0','frame6':'frame 6','frame0':'frame 0',
    '18frames':'18 frames','inclusivos16':'inclusivos 16','exactamente200':'exactamente 200',
    '>1000blancos':'>1000 blancos','controlPNG':'control PNG','controlMP4':'control MP4',
    'exige0blancos':'exige 0 blancos','sus42checks':'sus 42 checks','aspecto320':'aspecto 320',
    'ancho200':'ancho 200','alto100':'alto 100','desviación<':'desviación <',
    'negra>85%':'negra >85%','exige>500blancos':'exige >500 blancos','PNG320':'PNG 320',
    'Las42comprobaciones':'Las 42 comprobaciones','desplazados0':'desplazados 0',
    'privada160':'privada 160','frames0':'frames 0','entre4':'entre 4','y4':'y 4',
    'ratio permitido0':'ratio permitido 0','fuente: A2s':'fuente: A 2 s','fade0':'fade 0',
    'cueAA1':'cue AA 1','cueCCCCCCCC':'cue CCCCCCCC','enC3':'en C 3','elmidpoint':'el midpoint',
    'MP4H264':'MP4 H264','42frames':'42 frames','3,5s':'3,5 s','AAC48k':'AAC 48 k',
    'revisión1':'revisión 1','actual2':'actual 2','mezcla440':'mezcla 440','elvídeo':'el vídeo',
    'delPCM':'del PCM','helperJS':'helper JS','ColaRMS':'Cola RMS','marcador1760fuera':'marcador 1760 fuera',
    'deltrim':'del trim','diferenciaL/R':'diferencia L/R','los12specs':'los 12 specs',
    'unión39fuentes+76generados+7descriptores':'unión 39 fuentes +76 generados +7 descriptores',
    'legacyfreeze':'legacy freeze','límite322':'límite 322','pico':'pico ',
    'por3GiB':'por 3 GiB','no esRSS':'no es RSS','induceOOM':'induce OOM',
    'Linuxcgroup':'Linux cgroup','multimediaLinux':'multimedia Linux',
    '0incoherencias/16avisos':'0 incoherencias/16 avisos','0referencias':'0 referencias',
    '0tests':'0 tests','deVPS':'de VPS','solicitaSSH':'solicita SSH','porred':'por red',
    'Desarrollo+usuario':'Desarrollo + usuario','delservidor':'del servidor','políticasOS':'políticas OS',
    'ClaudeDesktop':'Claude Desktop','enT14':'en T14','ClaudeCLI':'Claude CLI',
    'elroadmap':'el roadmap','las23tareas':'las 23 tareas','handoffdocumenter':'handoff documenter',
    'porQA':'por QA','CommonMark→HTML/tema':'CommonMark → HTML/tema','to-pdf→Chromium':'to-pdf → Chromium',
}.items():body=body.replace(old,new)
(OUT/'report.md').write_text(body,encoding='utf-8')
(RAW/'acceptance-summary.json').write_text(json.dumps({'automated_gate':gate,'private_checks':len(private),
    'private_failed':[c for c in private if not c['passed']],'contrast_checks':len(contrast),
    'contrast_failed':[c for c in contrast if not c['passed']],'visual':visual,
    'stable_sources':stable,'locally_acceptable':accepted,'verdict':verdict},ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps({'gate':gate['verdict'],'private':len(private),'contrast':len(contrast),'local_acceptance':accepted},ensure_ascii=False))
