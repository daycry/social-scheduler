# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: studio-presentation-tools.spec.ts >> E2E-29 P3 paused laser pen erase TTL page and blackout compose actual pixels; audience and fullscreen include tools
- Location: tests\e2e\studio-presentation-tools.spec.ts:83:1

# Error details

```
Error: expect(received).toEqual(expected) // deep equality

- Expected  - 2
+ Received  + 2

  Array [
+   0,
+   0,
    255,
-   34,
-   34,
    255,
  ]

Call Log:
- Timeout 5000ms exceeded while waiting on the predicate
```

# Page snapshot

```yaml
- generic [ref=f2e1]:
  - generic [ref=f2e3]:
    - link "Saltar al contenido" [ref=f2e4] [cursor=pointer]:
      - /url: "#contenido"
    - banner [ref=f2e5]:
      - generic [ref=f2e6]:
        - button "Abrir menú" [ref=f2e7] [cursor=pointer]
        - link "Social Scheduler, inicio" [ref=f2e10] [cursor=pointer]:
          - /url: http://localhost:8080/dashboard?brand_id=17
        - generic [ref=f2e16]:
          - button "Cambiar de marca" [ref=f2e19] [cursor=pointer]:
            - generic [aria-hidden] [ref=f2e20]: E
            - generic [ref=f2e21]: e2e-composer-charts-mw5xy49z
          - link "Avisos" [ref=f2e24] [cursor=pointer]:
            - /url: http://localhost:8080/notifications
          - button "Menú de usuario" [ref=f2e29] [cursor=pointer]:
            - generic [ref=f2e30]: ES
    - main [ref=f2e33]:
      - generic [ref=f2e34]:
        - generic [ref=f2e35]:
          - link "Volver al estudio" [ref=f2e36] [cursor=pointer]:
            - /url: http://localhost:8080/studio?brand_id=17
          - generic [ref=f2e39]:
            - generic [ref=f2e40]: Nombre del diseño
            - textbox "Nombre del diseño" [ref=f2e41]: Diseño sin título
            - generic [ref=f2e42]:
              - generic [ref=f2e43]: e2e-composer-charts-mw5xy49z
              - status [ref=f2e44]: Guardado
              - generic [ref=f2e45]: Revisión 2
          - generic [ref=f2e46]:
            - button "Deshacer" [disabled] [ref=f2e47]: ↶
            - button "Rehacer" [disabled] [ref=f2e48]: ↷
            - button "Guardar ahora" [ref=f2e49] [cursor=pointer]
            - button "Presentar" [ref=f2e50] [cursor=pointer]
            - button "Exportar" [ref=f2e51] [cursor=pointer]
            - button "Usar en publicación" [ref=f2e52] [cursor=pointer]
        - generic "Plantillas y formatos" [ref=f2e53]:
          - button "Guardar como plantilla" [ref=f2e54] [cursor=pointer]
          - button "Crear variantes" [ref=f2e55] [cursor=pointer]
          - button "Aplicar identidad de marca" [ref=f2e56] [cursor=pointer]
          - button "Tablas de datos" [ref=f2e57] [cursor=pointer]
          - button "Tipografías de marca" [ref=f2e58] [cursor=pointer]
          - button "Línea de tiempo" [pressed] [ref=f2e59] [cursor=pointer]
        - generic [ref=f2e60]:
          - complementary "Herramientas de diseño" [ref=f2e61]:
            - generic [ref=f2e62]:
              - button "Elementos" [ref=f2e63] [cursor=pointer]
              - button "Recursos" [ref=f2e64] [cursor=pointer]
            - generic [ref=f2e65]:
              - button "Añadir texto" [ref=f2e66] [cursor=pointer]:
                - generic [aria-hidden] [ref=f2e67]: T
                - text: Añadir texto
              - button "Añadir gráfico con datos" [ref=f2e68] [cursor=pointer]
              - generic [ref=f2e69]:
                - heading "Formas" [level=2] [ref=f2e70]
                - generic [ref=f2e71]:
                  - button "Añadir rectángulo" [ref=f2e72] [cursor=pointer]: ▭
                  - button "Añadir elipse" [ref=f2e73] [cursor=pointer]: ◯
                  - button "Añadir triángulo" [ref=f2e74] [cursor=pointer]: △
                  - button "Añadir línea" [ref=f2e75] [cursor=pointer]: ╱
                  - button "Añadir trazado" [ref=f2e76] [cursor=pointer]: 〰
              - button "Dibujo libre" [ref=f2e78] [cursor=pointer]
              - generic [ref=f2e79]:
                - heading "Colores de e2e-composer-charts-mw5xy49z" [level=3] [ref=f2e80]
                - generic [ref=f2e81]:
                  - 'button "Color de marca #7c3aed" [disabled] [ref=f2e82]'
                  - 'button "Color de marca #111827" [disabled] [ref=f2e83]'
                  - 'button "Color de marca #ffffff" [disabled] [ref=f2e84]'
                - paragraph [ref=f2e85]: Selecciona un elemento para aplicarle un color.
          - main "Editor de diseño" [ref=f2e86]:
            - generic [ref=f2e87]:
              - generic [ref=f2e88]: Azul
              - generic [ref=f2e89]:
                - button "Duplicar elemento" [disabled] [ref=f2e90]: Duplicar
                - button "Eliminar elemento" [disabled] [ref=f2e91]: Eliminar
                - button "Bloquear elemento" [disabled] [ref=f2e92]: Bloquear
            - generic "Lienzo de diseño. Usa el panel Capas para seleccionar y editar con teclado." [ref=f2e97]
            - generic [ref=f2e98]:
              - generic [ref=f2e99]: 320 × 180 px
              - generic [ref=f2e100]:
                - button "Reducir zoom" [ref=f2e101] [cursor=pointer]: −
                - generic [ref=f2e102]: Zoom
                - spinbutton "Zoom" [ref=f2e103]: "99"
                - generic [ref=f2e104]: "%"
                - button "Aumentar zoom" [ref=f2e105] [cursor=pointer]: +
                - button "Ajustar" [ref=f2e106] [cursor=pointer]
            - region "Línea de tiempo" [ref=f2e107]:
              - generic [ref=f2e108]:
                - generic [ref=f2e109]:
                  - heading "Línea de tiempo" [level=2] [ref=f2e110]
                  - paragraph [ref=f2e111]: Azul · 40 s en total
                - button "Reproducir" [ref=f2e112] [cursor=pointer]
                - button "Volver a editar" [ref=f2e113] [cursor=pointer]
                - generic [ref=f2e114]:
                  - text: FPS
                  - combobox "FPS" [ref=f2e115]:
                    - option "12"
                    - option "15"
                    - option "24"
                    - option "25"
                    - option "30" [selected]
                    - option "50"
                    - option "60"
              - generic [ref=f2e116]:
                - generic [ref=f2e117]:
                  - text: Tiempo de reproducción
                  - spinbutton "Tiempo de reproducción" [ref=f2e118]: "0"
                - slider "Desplazar cabezal" [ref=f2e119]: "0"
              - paragraph [ref=f2e121]: Añade texto, imágenes o vídeo para crear clips.
              - paragraph [ref=f2e122]: Selecciona un clip para ajustar sus tiempos o dividirlo.
              - region "Pistas de audio" [ref=f2e123]:
                - heading "Pistas de audio 0/64" [level=3] [ref=f2e124]
                - paragraph [ref=f2e125]: Sube un audio en Recursos y añádelo a la línea de tiempo.
              - region "Subtítulos" [ref=f2e126]:
                - generic [ref=f2e127]:
                  - heading "Subtítulos 0/5000" [level=3] [ref=f2e128]
                  - button "Añadir subtítulo" [ref=f2e129] [cursor=pointer]
                - paragraph [ref=f2e130]: Una pista para todo el proyecto. Importa SRT/VTT o escribe texto; el reconocimiento de voz y la traducción aún no están disponibles.
                - generic [ref=f2e131]:
                  - generic [ref=f2e132]:
                    - text: Al importar
                    - combobox "Al importar" [ref=f2e133]:
                      - option "Añadir a la pista" [selected]
                      - option "Reemplazar la pista"
                  - generic [ref=f2e134]:
                    - text: Archivo SRT o VTT
                    - button "Importar subtítulos" [ref=f2e135]
                  - button "Descargar SRT" [disabled] [ref=f2e136]
                  - button "Descargar VTT" [disabled] [ref=f2e137]
                - generic [ref=f2e138]:
                  - text: Buscar subtítulos
                  - searchbox "Buscar subtítulos" [ref=f2e139]
                - paragraph [ref=f2e141]: No hay subtítulos para mostrar.
                - generic [ref=f2e142]:
                  - generic [ref=f2e143]:
                    - text: Desplazar toda la pista (segundos)
                    - spinbutton "Desplazar toda la pista (segundos)" [ref=f2e144]: "0"
                  - button "Aplicar desplazamiento" [disabled] [ref=f2e145]
              - paragraph [ref=f2e146]: La reproducción recorre todas las páginas. Los tiempos de los clips pertenecen a la página seleccionada; los del audio, al proyecto completo.
            - region "Páginas del diseño" [ref=f2e147]:
              - generic [ref=f2e148]:
                - heading "Páginas 2/100" [level=2] [ref=f2e149]
                - generic [ref=f2e150]:
                  - button "Añadir página" [ref=f2e151] [cursor=pointer]
                  - button "Duplicar página" [ref=f2e152] [cursor=pointer]
                  - button "Eliminar página" [ref=f2e153] [cursor=pointer]
              - generic [ref=f2e154]:
                - button "01 Azul" [pressed] [ref=f2e155] [cursor=pointer]:
                  - generic [ref=f2e156]: "01"
                  - generic [ref=f2e157]: Azul
                - button "02 Verde" [ref=f2e158] [cursor=pointer]:
                  - generic [ref=f2e159]: "02"
                  - generic [ref=f2e160]: Verde
              - generic [ref=f2e161]:
                - button "← Mover página" [disabled] [ref=f2e162]
                - button "Mover página →" [ref=f2e163] [cursor=pointer]
          - complementary "Panel de edición" [ref=f2e164]:
            - generic [ref=f2e165]:
              - button "Capas" [ref=f2e166] [cursor=pointer]
              - button "Propiedades" [ref=f2e167] [cursor=pointer]
              - button "Página" [ref=f2e168] [cursor=pointer]
            - region "Capas del diseño" [ref=f2e169]:
              - generic [ref=f2e170]:
                - heading "Capas" [level=2] [ref=f2e171]
                - generic [ref=f2e172]: 0 elementos
              - paragraph [ref=f2e173]: La primera capa está delante. Usa Ctrl o ⌘ para seleccionar varias.
              - list
              - paragraph [ref=f2e174]: Tu lienzo está listo.Añade texto, una forma o un recurso.
  - dialog "Presentación" [ref=f2e175]:
    - generic [ref=f2e176]:
      - banner [ref=f2e177]:
        - generic [ref=f2e178]:
          - heading "Presentación" [level=2] [ref=f2e179]
          - status [ref=f2e180]: 1 / 2 · Azul
        - generic [ref=f2e181]:
          - generic [ref=f2e182]: Vista de presentación
          - combobox "Vista de presentación" [ref=f2e183]:
            - option "Presentador" [selected]
            - option "Audiencia"
          - button "Abrir audiencia" [ref=f2e184] [cursor=pointer]
          - button "Pantalla completa" [ref=f2e185] [cursor=pointer]
          - button "Cerrar presentación" [ref=f2e186] [cursor=pointer]: Cerrar
      - group [ref=f2e187]:
        - generic "Herramientas" [active] [ref=f2e188] [cursor=pointer]
        - generic [ref=f2e189]:
          - group "Cursor y trazos" [ref=f2e190]:
            - generic [ref=f2e192]:
              - text: Cursor
              - combobox "Cursor de presentación" [ref=f2e193]:
                - option "Normal"
                - option "Láser" [selected]
                - option "Oculto"
            - generic [ref=f2e194]:
              - checkbox "Dibujar" [ref=f2e195]
              - text: Dibujar
            - generic [ref=f2e196]:
              - text: Color
              - textbox "Color del trazo" [ref=f2e197]: "#ff0000"
            - generic [ref=f2e198]:
              - text: Grosor
              - slider "Grosor del trazo" [ref=f2e199]: "0.005"
            - generic [ref=f2e200]:
              - text: Duración del trazo (s)
              - spinbutton "Duración de los trazos en segundos" [ref=f2e201]: "3"
            - button "Borrar trazos" [ref=f2e202] [cursor=pointer]
          - group "Temporizador" [ref=f2e203]:
            - status "Tiempo restante" [ref=f2e205]: 05:00
            - generic [ref=f2e206]:
              - text: Duración (s)
              - spinbutton "Duración del temporizador en segundos" [ref=f2e207]: "300"
            - button "Iniciar temporizador" [ref=f2e208] [cursor=pointer]
            - button "Reiniciar temporizador" [ref=f2e209] [cursor=pointer]
            - generic [ref=f2e210]:
              - checkbox "Mostrar temporizador a la audiencia" [checked] [ref=f2e211]
              - text: Mostrar a la audiencia
            - generic [ref=f2e212]:
              - text: Música
              - combobox "Música del temporizador" [ref=f2e213]:
                - option "Sin música" [selected]
            - generic [ref=f2e214]:
              - text: Volumen
              - slider "Volumen de la música" [ref=f2e215]: "0.4"
            - generic [ref=f2e216]:
              - checkbox "Silenciar música" [ref=f2e217]
              - text: Silenciar
      - generic:
        - region "Diapositiva actual" [ref=f2e218]:
          - img "Diapositiva de audiencia" [ref=f2e220]
        - complementary [ref=f2e221]:
          - region "Diapositiva siguiente" [ref=f2e222]:
            - heading "Siguiente · Verde" [level=3] [ref=f2e223]
            - img "Vista previa siguiente" [ref=f2e224]
          - region "Notas del presentador" [ref=f2e225]:
            - heading "Notas del presentador" [level=3] [ref=f2e226]
            - paragraph [ref=f2e227]: PRIVATE TOOLS NOTES Ω
      - contentinfo [ref=f2e228]:
        - generic [ref=f2e229]:
          - button "Primera diapositiva" [ref=f2e230] [cursor=pointer]: Inicio
          - button "Diapositiva anterior" [ref=f2e231] [cursor=pointer]: Anterior
          - button "Reproducir presentación" [ref=f2e232] [cursor=pointer]
          - button "Diapositiva siguiente" [ref=f2e233] [cursor=pointer]: Siguiente
          - button "Última diapositiva" [ref=f2e234] [cursor=pointer]: Final
        - generic [ref=f2e235]:
          - generic [ref=f2e236]:
            - text: Diapositiva
            - combobox "Diapositiva" [ref=f2e237]:
              - option "1 · Azul" [selected]
              - option "2 · Verde"
          - generic [ref=f2e238]:
            - checkbox "Bucle" [ref=f2e239]
            - text: Bucle
          - button "Pantalla negra" [ref=f2e240] [cursor=pointer]
        - paragraph [ref=f2e241]: "Flechas / RePág / AvPág: navegar · Espacio: reproducir · B: pantalla negra · Esc: cerrar"
```

# Test source

```ts
  1   | import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
  2   | import fs from 'node:fs';
  3   | import crypto from 'node:crypto';
  4   | import { cleanComposerBrands } from './helpers';
  5   | import { setup, read, web } from './support/studio-chart-ui';
  6   | import { armPresentationCloseObserver, readPresentationCloseObservation } from './support/studio-presentation-observer.mjs';
  7   | 
  8   | // Definitions only until root authorizes a fresh SourceDone/build/native phase.
  9   | // The existing 24 P2 cases are unchanged. These three cases run in the same
  10  | // disposable SQLite/loopback harness at its authorized 375/768/1440 viewports.
  11  | const modal = (page: Page) => page.getByRole('dialog', { name: 'Presentación', exact: true });
  12  | const button = (page: Page, name: string) => modal(page).getByRole('button', { name, exact: true });
  13  | const current = (page: Page) => modal(page).getByRole('img', { name: 'Diapositiva de audiencia', exact: true });
  14  | async function deck(page: Page, id: number) {
  15  |     const before = await read(page, id);
  16  |     const document = { schema_version: 2, width: 320, height: 180, timeline: { fps: 30, audio_tracks: [] }, pages: [
  17  |         { id: 'tools-first', name: 'Azul', duration: 20, background: '#0000ff', notes: 'PRIVATE TOOLS NOTES Ω', elements: [] },
  18  |         { id: 'tools-second', name: 'Verde', duration: 20, background: '#00ff00', notes: 'PRIVATE NEXT TOOLS NOTES Ω', elements: [] },
  19  |     ] };
  20  |     expect((await web(page, 'PATCH', `/studio/designs/${id}`, { name: before.name, revision: before.revision, document })).status).toBe(200);
  21  |     await page.reload(); await expect(page.getByRole('button', { name: 'Presentar', exact: true })).toBeEnabled();
  22  | }
  23  | async function open(page: Page) {
  24  |     await page.getByRole('button', { name: 'Presentar', exact: true }).click();
  25  |     await expect(button(page, 'Reproducir presentación')).toBeEnabled();
  26  |     await modal(page).locator('summary').filter({ hasText: 'Herramientas' }).click();
  27  |     await expect(modal(page).getByLabel('Cursor de presentación', { exact: true })).toBeVisible();
  28  | }
  29  | async function point(canvas: Locator, x: number, y: number) {
  30  |     return canvas.evaluate((n: HTMLCanvasElement, p) => {
  31  |         const r = n.getBoundingClientRect(), scale = Math.min(r.width / n.width, r.height / n.height);
  32  |         return { x: r.left + (r.width - n.width * scale) / 2 + n.width * scale * p.x,
  33  |             y: r.top + (r.height - n.height * scale) / 2 + n.height * scale * p.y };
  34  |     }, { x, y });
  35  | }
  36  | async function pixel(canvas: Locator, x = .5, y = .5) {
  37  |     return canvas.evaluate((n: HTMLCanvasElement, p) => [...n.getContext('2d')!.getImageData(Math.floor(n.width * p.x), Math.floor(n.height * p.y), 1, 1).data], { x, y });
  38  | }
  39  | async function image(canvas: Locator) { return canvas.evaluate((n: HTMLCanvasElement) => n.toDataURL()); }
  40  | async function hasGreenTrace(canvas: Locator) { const p = await pixel(canvas); return p[0] === 0 && p[1] > 0 && p[2] < 255 && p[3] === 255; }
  41  | async function draw(page: Page, canvas: Locator) {
  42  |     await canvas.scrollIntoViewIfNeeded(); const a = await point(canvas, .25, .5), b = await point(canvas, .75, .5);
  43  |     await page.mouse.move(a.x, a.y); await page.mouse.down(); await page.mouse.move(b.x, b.y, { steps: 8 }); await page.mouse.up();
  44  | }
  45  | async function observe(page: Page) {
  46  |     await page.evaluate(() => {
  47  |         const w = window as any, nodes: HTMLMediaElement[] = [], events: any[] = [], draws: any[] = [], ids = new WeakMap<object, number>(); let serial = 0;
  48  |         const id = (n: object) => { if (!ids.has(n)) ids.set(n, ++serial); return ids.get(n); };
  49  |         const record = (n: HTMLMediaElement, event: string) => events.push({ event, id: id(n), kind: n.tagName, src: n.currentSrc || n.src, time: n.currentTime, paused: n.paused, at: performance.now() });
  50  |         const watch = (n: HTMLMediaElement) => {
  51  |             if (nodes.includes(n)) return; nodes.push(n);
  52  |             for (const event of ['loadstart', 'loadedmetadata', 'loadeddata', 'play', 'playing', 'pause', 'ended', 'error', 'timeupdate', 'emptied']) n.addEventListener(event, () => record(n, event));
  53  |         };
  54  |         const play = HTMLMediaElement.prototype.play, pause = HTMLMediaElement.prototype.pause, load = HTMLMediaElement.prototype.load;
  55  |         HTMLMediaElement.prototype.play = function () { watch(this); record(this, 'call:play'); return play.call(this); };
  56  |         HTMLMediaElement.prototype.pause = function () { watch(this); record(this, 'call:pause'); return pause.call(this); };
  57  |         HTMLMediaElement.prototype.load = function () { watch(this); record(this, 'call:load'); return load.call(this); };
  58  |         const drawImage = CanvasRenderingContext2D.prototype.drawImage, fillText = CanvasRenderingContext2D.prototype.fillText;
  59  |         CanvasRenderingContext2D.prototype.drawImage = function (...args: any[]) { draws.push({ event: 'drawImage', target: id(this.canvas), source: id(args[0]), at: performance.now() }); return (drawImage as any).apply(this, args); };
  60  |         CanvasRenderingContext2D.prototype.fillText = function (...args: any[]) { draws.push({ event: 'fillText', target: id(this.canvas), text: String(args[0]), at: performance.now() }); return (fillText as any).apply(this, args); };
  61  |         w.__presentationProbe = { nodes, events, draws, id, closed: false };
  62  |     });
  63  | }
  64  | async function nativeSnapshot(page: Page) {
  65  |     return page.evaluate(() => {
  66  |         const p = (window as any).__presentationProbe;
  67  |         return { events: p.events, draws: p.draws, media: p.nodes.map((n: HTMLMediaElement) => ({ id: p.id(n), paused: n.paused, src: n.currentSrc || n.src,
  68  |             currentTime: n.currentTime, loop: n.loop, volume: n.volume, muted: n.muted, readyState: n.readyState, duration: Number.isFinite(n.duration) ? n.duration : null })) };
  69  |     });
  70  | }
  71  | async function saveNative(page: Page, info: TestInfo, name: string) { const data = await nativeSnapshot(page); fs.writeFileSync(info.outputPath(`${name}.json`), JSON.stringify(data, null, 2)); return data; }
  72  | async function musicUpload(page: Page, info: TestInfo, bytes: Buffer) {
  73  |     fs.writeFileSync(info.outputPath('own-timer.wav'), bytes);
  74  |     fs.writeFileSync(info.outputPath('own-timer-source.json'), JSON.stringify({ bytes: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex'), duration: .35, sampleRate: 16000, frequency: 440 }, null, 2));
  75  |     await page.getByRole('button', { name: 'Recursos', exact: true }).click();
  76  |     await page.getByLabel('Subir recursos de marca', { exact: true }).setInputFiles({ name: 'own-timer.wav', mimeType: 'audio/wav', buffer: bytes });
  77  |     // Upload alone captures a brand resource; never click “Añadir” or add a timeline track.
  78  |     await expect(page.getByRole('button', { name: 'Añadir own-timer.wav', exact: true })).toBeVisible();
  79  | }
  80  | async function setSeconds(page: Page, seconds: number) { const field = modal(page).getByLabel('Duración del temporizador en segundos', { exact: true }); await field.fill(String(seconds)); await field.press('Tab'); }
  81  | async function mutedAndPaused(page: Page) { await expect.poll(() => page.evaluate(() => (window as any).__presentationProbe.nodes.every((n: HTMLMediaElement) => n.paused))).toBe(true); }
  82  | 
  83  | test('E2E-29 P3 paused laser pen erase TTL page and blackout compose actual pixels; audience and fullscreen include tools', async ({ page }, info) => {
  84  |     const { user, id } = await setup(page, info);
  85  |     try {
  86  |         await deck(page, id); await observe(page); await open(page);
  87  |         const canvas = current(page), next = modal(page).getByRole('img', { name: 'Vista previa siguiente', exact: true });
  88  |         await expect.poll(() => pixel(canvas)).toEqual([0, 0, 255, 255]); const nextBefore = await image(next);
  89  |         await modal(page).getByLabel('Cursor de presentación', { exact: true }).selectOption('laser');
  90  |         await canvas.scrollIntoViewIfNeeded(); const middle = await point(canvas, .5, .5); await page.mouse.move(middle.x, middle.y);
> 91  |         await expect.poll(() => pixel(canvas)).toEqual([255, 34, 34, 255]);
      |                                                ^ Error: expect(received).toEqual(expected) // deep equality
  92  |         await expect(canvas).toHaveCSS('cursor', 'none'); expect(await image(next)).toBe(nextBefore);
  93  |         await modal(page).getByLabel('Cursor de presentación', { exact: true }).selectOption('hidden');
  94  |         await expect(canvas).toHaveCSS('cursor', 'none'); await expect.poll(() => pixel(canvas)).toEqual([0, 0, 255, 255]);
  95  |         await modal(page).getByLabel('Cursor de presentación', { exact: true }).selectOption('normal');
  96  |         await modal(page).getByLabel('Dibujar', { exact: true }).check();
  97  |         await modal(page).getByLabel('Color del trazo', { exact: true }).fill('#00ff00');
  98  |         await modal(page).getByLabel('Grosor del trazo', { exact: true }).fill('0.04');
  99  |         const ttl = modal(page).getByLabel('Duración de los trazos en segundos', { exact: true }); await ttl.fill('10'); await ttl.press('Tab');
  100 |         await draw(page, canvas); await expect.poll(() => hasGreenTrace(canvas)).toBe(true);
  101 |         await button(page, 'Pantalla completa').click(); await expect.poll(() => page.evaluate(() => document.fullscreenElement?.getAttribute('aria-label'))).toBe('Diapositiva de audiencia');
  102 |         await expect.poll(() => hasGreenTrace(canvas)).toBe(true); await page.screenshot({ path: info.outputPath('tools-fullscreen-main.png') });
  103 |         await page.evaluate(() => document.exitFullscreen());
  104 |         const popupPending = page.waitForEvent('popup'); await button(page, 'Abrir audiencia').click(); const popup = await popupPending, audience = popup.locator('body > canvas');
  105 |         await expect(audience).toHaveCount(1); expect(await popup.locator('body').innerText()).toBe('');
  106 |         await expect.poll(() => hasGreenTrace(audience)).toBe(true); expect(await image(next)).toBe(nextBefore);
  107 |         await page.screenshot({ path: info.outputPath('tools-pen-presenter.png'), fullPage: true }); await popup.screenshot({ path: info.outputPath('tools-pen-audience.png') });
  108 |         await button(page, 'Borrar trazos').click(); await expect.poll(() => pixel(canvas)).toEqual([0, 0, 255, 255]);
  109 |         // Popup pointer events are genuine gestures on its canvas too.
  110 |         await draw(popup, audience); await expect.poll(() => hasGreenTrace(canvas)).toBe(true);
  111 |         await button(page, 'Borrar trazos').click(); await ttl.fill('1'); await ttl.press('Tab'); await draw(page, canvas);
  112 |         await expect.poll(() => hasGreenTrace(canvas)).toBe(true); await expect.poll(() => pixel(canvas)).toEqual([0, 0, 255, 255]);
  113 |         await ttl.fill('10'); await ttl.press('Tab'); await draw(page, canvas);
  114 |         await button(page, 'Diapositiva siguiente').click(); await expect.poll(() => pixel(canvas)).toEqual([0, 255, 0, 255]);
  115 |         await button(page, 'Primera diapositiva').click(); await expect.poll(() => pixel(canvas)).toEqual([0, 0, 255, 255]);
  116 |         await draw(page, canvas); await button(page, 'Pantalla negra').click();
  117 |         for (const output of [canvas, audience]) {
  118 |             await expect.poll(() => output.evaluate((n: HTMLCanvasElement) => {
  119 |                 const data = n.getContext('2d')!.getImageData(0, 0, n.width, n.height).data;
  120 |                 for (let i = 0; i < data.length; i += 4) if (data[i] || data[i + 1] || data[i + 2] || data[i + 3] !== 255) return false; return true;
  121 |             })).toBe(true); await expect(output).toHaveCSS('cursor', 'none');
  122 |         }
  123 |         await button(page, 'Mostrar diapositiva').click();
  124 |         await popup.close(); const againPending = page.waitForEvent('popup'); await button(page, 'Abrir audiencia').click();
  125 |         const secondPopup = await againPending;
  126 |         const secondAudience = secondPopup.locator('body > canvas');
  127 |         await secondAudience.dblclick();
  128 |         await expect.poll(() => secondAudience.evaluate(n => document.fullscreenElement === n)).toBe(true);
  129 |         await secondPopup.screenshot({ path: info.outputPath('tools-fullscreen-audience.png') });
  130 |         await secondAudience.press('f');
  131 |         await expect.poll(() => secondPopup.evaluate(() => document.fullscreenElement === null)).toBe(true);
  132 |         await secondAudience.press('Shift+F');
  133 |         await expect.poll(() => secondAudience.evaluate(n => document.fullscreenElement === n)).toBe(true);
  134 |         await secondPopup.evaluate(() => document.exitFullscreen());
  135 |         await secondPopup.close();
  136 |         // After a vanished popup, reopening and closing the dialog must remain safe.
  137 |         const data = await saveNative(page, info, 'tools-pixels'); expect(data.events.filter((e: any) => e.event === 'call:play')).toEqual([]);
  138 |         await button(page, 'Cerrar presentación').click();
  139 |         expect((await read(page, id)).document.timeline.audio_tracks).toHaveLength(0);
  140 |     } finally { cleanComposerBrands(user.email); }
  141 | });
  142 | 
  143 | test('E2E-29 P3 timer uses a real own WAV with native start pause resume reset visibility volume mute loop and finish', async ({ page }, info) => {
  144 |     const { user, id } = await setup(page, info);
  145 |     const responses: any[] = [], responseWrites: Promise<void>[] = [];
  146 |     try {
  147 |         // Generated only when this future native test body actually executes.
  148 |         const count = 5600, bytes = Buffer.alloc(44 + count * 2);
  149 |         bytes.write('RIFF'); bytes.writeUInt32LE(bytes.length - 8, 4); bytes.write('WAVEfmt ', 8); bytes.writeUInt32LE(16, 16); bytes.writeUInt16LE(1, 20); bytes.writeUInt16LE(1, 22); bytes.writeUInt32LE(16000, 24); bytes.writeUInt32LE(32000, 28); bytes.writeUInt16LE(2, 32); bytes.writeUInt16LE(16, 34); bytes.write('data', 36); bytes.writeUInt32LE(count * 2, 40);
  150 |         for (let i = 0; i < count; i++) bytes.writeInt16LE(Math.round(10000 * Math.sin(2 * Math.PI * 440 * i / 16000)), 44 + i * 2);
  151 |         await deck(page, id); await musicUpload(page, info, bytes); await observe(page);
  152 |         page.on('response', response => { if (/\/studio\/assets\/\d+\/file/.test(response.url())) { const index = responses.length; const entry: any = { url: response.url(), status: response.status() }; responses.push(entry); responseWrites.push((async () => { const data = await response.body(); const file = `timer-response-${index}.bin`; fs.writeFileSync(info.outputPath(file), data); Object.assign(entry, { file, bytes: data.length, sha256: crypto.createHash('sha256').update(data).digest('hex'), headers: await response.allHeaders() }); })()); } });
  153 |         await open(page); const music = modal(page).getByLabel('Música del temporizador', { exact: true });
  154 |         const own = music.getByRole('option', { name: 'own-timer.wav', exact: true }); const ownId = await own.getAttribute('value'); expect(ownId).toMatch(/^\d+$/); await music.selectOption(ownId!);
  155 |         expect((await nativeSnapshot(page)).media).toEqual([]); await setSeconds(page, 30); await button(page, 'Iniciar temporizador').click();
  156 |         await expect.poll(() => page.evaluate(() => (window as any).__presentationProbe.events.some((e: any) => e.kind === 'AUDIO' && e.event === 'playing'))).toBe(true);
  157 |         await expect.poll(() => page.evaluate(() => {
  158 |             const events = (window as any).__presentationProbe.events.filter((e: any) => e.kind === 'AUDIO' && e.event === 'timeupdate');
  159 |             return events.some((e: any, i: number) => i > 0 && e.id === events[i - 1].id && e.time < events[i - 1].time);
  160 |         })).toBe(true);
  161 |         let snapshot = await saveNative(page, info, 'timer-running'); expect(snapshot.media).toHaveLength(1); expect(snapshot.media[0]).toMatchObject({ loop: true, volume: .4, muted: false, paused: false });
  162 |         await modal(page).getByLabel('Volumen de la música', { exact: true }).fill('0.2'); await modal(page).getByLabel('Silenciar música', { exact: true }).check();
  163 |         snapshot = await saveNative(page, info, 'timer-muted'); expect(snapshot.media[0]).toMatchObject({ volume: .2, muted: true });
  164 |         await button(page, 'Pausar temporizador').click(); await mutedAndPaused(page); const remaining = await modal(page).getByLabel('Tiempo restante', { exact: true }).textContent();
  165 |         await page.waitForTimeout(350); await expect(modal(page).getByLabel('Tiempo restante', { exact: true })).toHaveText(remaining!);
  166 |         const paused = await saveNative(page, info, 'timer-paused'); const songPosition = paused.media[0].currentTime;
  167 |         await button(page, 'Iniciar temporizador').click(); await expect.poll(() => page.evaluate(() => (window as any).__presentationProbe.nodes.filter((n: HTMLMediaElement) => !n.paused).length)).toBe(1);
  168 |         snapshot = await saveNative(page, info, 'timer-resumed'); expect(snapshot.media).toHaveLength(1); expect(snapshot.media[0].id).toBe(paused.media[0].id);
  169 |         const resumeCall = snapshot.events.filter((e: any) => e.event === 'call:play' && e.id === paused.media[0].id).at(-1); expect(resumeCall.time).toBe(songPosition);
  170 |         const visible = modal(page).getByLabel('Mostrar temporizador a la audiencia', { exact: true }); await visible.uncheck();
  171 |         const canvas = current(page); await expect.poll(() => pixel(canvas, .95, .05)).toEqual([0, 0, 255, 255]);
  172 |         const base = await image(canvas); await page.waitForTimeout(250); expect(await image(canvas)).toBe(base);
  173 |         expect((await nativeSnapshot(page)).media.some((n: any) => !n.paused)).toBe(true); await visible.check(); await expect.poll(() => image(canvas)).not.toBe(base);
  174 |         await page.screenshot({ path: info.outputPath('timer-own-music.png'), fullPage: true });
  175 |         await button(page, 'Reiniciar temporizador').click(); await mutedAndPaused(page); await expect(modal(page).getByLabel('Tiempo restante', { exact: true })).toHaveText('00:30');
  176 |         await setSeconds(page, 1); await button(page, 'Iniciar temporizador').click(); await expect(modal(page).getByLabel('Tiempo restante', { exact: true })).toHaveText('00:00'); await mutedAndPaused(page);
  177 |         await expect(modal(page).getByTestId('presentation-notes')).toHaveText('PRIVATE TOOLS NOTES Ω'); await expect(button(page, 'Reproducir presentación')).toBeVisible();
  178 |         await button(page, 'Cerrar presentación').evaluate(armPresentationCloseObserver); await button(page, 'Cerrar presentación').click();
  179 |         const close = await page.evaluate(readPresentationCloseObservation); expect(close?.trusted).toBe(true); expect(Number.isFinite(close?.at)).toBe(true);
  180 |         await page.waitForTimeout(250); snapshot = await saveNative(page, info, 'timer-finished-closed'); expect(snapshot.events.slice(close.eventIndex).filter((e: any) => ['call:play', 'play', 'playing'].includes(e.event))).toEqual([]);
  181 |         await Promise.all(responseWrites); expect(responses.some(r => [200, 206].includes(r.status) && r.bytes > 0)).toBe(true); fs.writeFileSync(info.outputPath('timer-responses.json'), JSON.stringify(responses, null, 2));
  182 |         expect((await read(page, id)).document.timeline.audio_tracks).toHaveLength(0);
  183 |     } finally { cleanComposerBrands(user.email); }
  184 | });
  185 | 
  186 | test('E2E-29 P3 pending own timer audio is cancelled by a real close and cannot publish or play after release', async ({ page }, info) => {
  187 |     const { user, id } = await setup(page, info); let release = () => {}, delivered = false;
  188 |     try {
  189 |         // This test owns an actual uploaded WAV, generated only inside its body.
  190 |         const count = 5600, bytes = Buffer.alloc(44 + count * 2);
  191 |         bytes.write('RIFF'); bytes.writeUInt32LE(bytes.length - 8, 4); bytes.write('WAVEfmt ', 8); bytes.writeUInt32LE(16, 16); bytes.writeUInt16LE(1, 20); bytes.writeUInt16LE(1, 22); bytes.writeUInt32LE(16000, 24); bytes.writeUInt32LE(32000, 28); bytes.writeUInt16LE(2, 32); bytes.writeUInt16LE(16, 34); bytes.write('data', 36); bytes.writeUInt32LE(count * 2, 40);
```