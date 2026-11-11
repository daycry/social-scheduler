import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { cleanComposerBrands } from './helpers';
import { setup, read, web } from './support/studio-chart-ui';
import { armPresentationCloseObserver, readPresentationCloseObservation } from './support/studio-presentation-observer.mjs';

// Definitions only until root authorizes a fresh SourceDone/build/native phase.
// The existing 24 P2 cases are unchanged. These three cases run in the same
// disposable SQLite/loopback harness at its authorized 375/768/1440 viewports.
const modal = (page: Page) => page.getByRole('dialog', { name: 'Presentación', exact: true });
const button = (page: Page, name: string) => modal(page).getByRole('button', { name, exact: true });
const current = (page: Page) => modal(page).getByRole('img', { name: 'Diapositiva de audiencia', exact: true });
async function deck(page: Page, id: number) {
    const before = await read(page, id);
    const document = { schema_version: 2, width: 320, height: 180, timeline: { fps: 30, audio_tracks: [] }, pages: [
        { id: 'tools-first', name: 'Azul', duration: 20, background: '#0000ff', notes: 'PRIVATE TOOLS NOTES Ω', elements: [] },
        { id: 'tools-second', name: 'Verde', duration: 20, background: '#00ff00', notes: 'PRIVATE NEXT TOOLS NOTES Ω', elements: [] },
    ] };
    expect((await web(page, 'PATCH', `/studio/designs/${id}`, { name: before.name, revision: before.revision, document })).status).toBe(200);
    await page.reload(); await expect(page.getByRole('button', { name: 'Presentar', exact: true })).toBeEnabled();
}
async function open(page: Page) {
    await page.getByRole('button', { name: 'Presentar', exact: true }).click();
    await expect(button(page, 'Reproducir presentación')).toBeEnabled();
    await modal(page).locator('summary').filter({ hasText: 'Herramientas' }).click();
    await expect(modal(page).getByLabel('Cursor de presentación', { exact: true })).toBeVisible();
}
async function point(canvas: Locator, x: number, y: number) {
    return canvas.evaluate((n: HTMLCanvasElement, p) => {
        const r = n.getBoundingClientRect(), scale = Math.min(r.width / n.width, r.height / n.height);
        return { x: r.left + (r.width - n.width * scale) / 2 + n.width * scale * p.x,
            y: r.top + (r.height - n.height * scale) / 2 + n.height * scale * p.y };
    }, { x, y });
}
async function pixel(canvas: Locator, x = .5, y = .5) {
    return canvas.evaluate((n: HTMLCanvasElement, p) => [...n.getContext('2d')!.getImageData(Math.floor(n.width * p.x), Math.floor(n.height * p.y), 1, 1).data], { x, y });
}
async function image(canvas: Locator) { return canvas.evaluate((n: HTMLCanvasElement) => n.toDataURL()); }
async function hasGreenTrace(canvas: Locator) { const p = await pixel(canvas); return p[0] === 0 && p[1] > 0 && p[2] < 255 && p[3] === 255; }
async function draw(page: Page, canvas: Locator) {
    await canvas.scrollIntoViewIfNeeded(); const a = await point(canvas, .25, .5), b = await point(canvas, .75, .5);
    await page.mouse.move(a.x, a.y); await page.mouse.down(); await page.mouse.move(b.x, b.y, { steps: 8 }); await page.mouse.up();
}
async function observe(page: Page) {
    await page.evaluate(() => {
        const w = window as any, nodes: HTMLMediaElement[] = [], events: any[] = [], draws: any[] = [], ids = new WeakMap<object, number>(); let serial = 0;
        const id = (n: object) => { if (!ids.has(n)) ids.set(n, ++serial); return ids.get(n); };
        const record = (n: HTMLMediaElement, event: string) => events.push({ event, id: id(n), kind: n.tagName, src: n.currentSrc || n.src, time: n.currentTime, paused: n.paused, at: performance.now() });
        const watch = (n: HTMLMediaElement) => {
            if (nodes.includes(n)) return; nodes.push(n);
            for (const event of ['loadstart', 'loadedmetadata', 'loadeddata', 'play', 'playing', 'pause', 'ended', 'error', 'timeupdate', 'emptied']) n.addEventListener(event, () => record(n, event));
        };
        const play = HTMLMediaElement.prototype.play, pause = HTMLMediaElement.prototype.pause, load = HTMLMediaElement.prototype.load;
        HTMLMediaElement.prototype.play = function () { watch(this); record(this, 'call:play'); return play.call(this); };
        HTMLMediaElement.prototype.pause = function () { watch(this); record(this, 'call:pause'); return pause.call(this); };
        HTMLMediaElement.prototype.load = function () { watch(this); record(this, 'call:load'); return load.call(this); };
        const drawImage = CanvasRenderingContext2D.prototype.drawImage, fillText = CanvasRenderingContext2D.prototype.fillText;
        CanvasRenderingContext2D.prototype.drawImage = function (...args: any[]) { draws.push({ event: 'drawImage', target: id(this.canvas), source: id(args[0]), at: performance.now() }); return (drawImage as any).apply(this, args); };
        CanvasRenderingContext2D.prototype.fillText = function (...args: any[]) { draws.push({ event: 'fillText', target: id(this.canvas), text: String(args[0]), at: performance.now() }); return (fillText as any).apply(this, args); };
        w.__presentationProbe = { nodes, events, draws, id, closed: false };
    });
}
async function nativeSnapshot(page: Page) {
    return page.evaluate(() => {
        const p = (window as any).__presentationProbe;
        return { events: p.events, draws: p.draws, media: p.nodes.map((n: HTMLMediaElement) => ({ id: p.id(n), paused: n.paused, src: n.currentSrc || n.src,
            currentTime: n.currentTime, loop: n.loop, volume: n.volume, muted: n.muted, readyState: n.readyState, duration: Number.isFinite(n.duration) ? n.duration : null })) };
    });
}
async function saveNative(page: Page, info: TestInfo, name: string) { const data = await nativeSnapshot(page); fs.writeFileSync(info.outputPath(`${name}.json`), JSON.stringify(data, null, 2)); return data; }
async function musicUpload(page: Page, info: TestInfo, bytes: Buffer) {
    fs.writeFileSync(info.outputPath('own-timer.wav'), bytes);
    fs.writeFileSync(info.outputPath('own-timer-source.json'), JSON.stringify({ bytes: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex'), duration: .35, sampleRate: 16000, frequency: 440 }, null, 2));
    await page.getByRole('button', { name: 'Recursos', exact: true }).click();
    await page.getByLabel('Subir recursos de marca', { exact: true }).setInputFiles({ name: 'own-timer.wav', mimeType: 'audio/wav', buffer: bytes });
    // Upload alone captures a brand resource; never click “Añadir” or add a timeline track.
    await expect(page.getByRole('button', { name: 'Añadir own-timer.wav', exact: true })).toBeVisible();
}
async function setSeconds(page: Page, seconds: number) { const field = modal(page).getByLabel('Duración del temporizador en segundos', { exact: true }); await field.fill(String(seconds)); await field.press('Tab'); }
async function mutedAndPaused(page: Page) { await expect.poll(() => page.evaluate(() => (window as any).__presentationProbe.nodes.every((n: HTMLMediaElement) => n.paused))).toBe(true); }

test('E2E-29 P3 paused laser pen erase TTL page and blackout compose actual pixels; audience and fullscreen include tools', async ({ page }, info) => {
    const { user, id } = await setup(page, info);
    try {
        await deck(page, id); await observe(page); await open(page);
        const canvas = current(page), next = modal(page).getByRole('img', { name: 'Vista previa siguiente', exact: true });
        await expect.poll(() => pixel(canvas)).toEqual([0, 0, 255, 255]); const nextBefore = await image(next);
        await modal(page).getByLabel('Cursor de presentación', { exact: true }).selectOption('laser');
        await canvas.scrollIntoViewIfNeeded(); const middle = await point(canvas, .5, .5); await page.mouse.move(middle.x, middle.y);
        await expect.poll(() => pixel(canvas)).toEqual([255, 34, 34, 255]);
        await expect(canvas).toHaveCSS('cursor', 'none'); expect(await image(next)).toBe(nextBefore);
        await modal(page).getByLabel('Cursor de presentación', { exact: true }).selectOption('hidden');
        await expect(canvas).toHaveCSS('cursor', 'none'); await expect.poll(() => pixel(canvas)).toEqual([0, 0, 255, 255]);
        await modal(page).getByLabel('Cursor de presentación', { exact: true }).selectOption('normal');
        await modal(page).getByLabel('Dibujar', { exact: true }).check();
        await modal(page).getByLabel('Color del trazo', { exact: true }).fill('#00ff00');
        await modal(page).getByLabel('Grosor del trazo', { exact: true }).fill('0.04');
        const ttl = modal(page).getByLabel('Duración de los trazos en segundos', { exact: true }); await ttl.fill('10'); await ttl.press('Tab');
        await draw(page, canvas); await expect.poll(() => hasGreenTrace(canvas)).toBe(true);
        await button(page, 'Pantalla completa').click(); await expect.poll(() => page.evaluate(() => document.fullscreenElement?.getAttribute('aria-label'))).toBe('Diapositiva de audiencia');
        await expect.poll(() => hasGreenTrace(canvas)).toBe(true); await page.screenshot({ path: info.outputPath('tools-fullscreen-main.png') });
        await page.evaluate(() => document.exitFullscreen());
        const popupPending = page.waitForEvent('popup'); await button(page, 'Abrir audiencia').click(); const popup = await popupPending, audience = popup.locator('body > canvas');
        await expect(audience).toHaveCount(1); expect(await popup.locator('body').innerText()).toBe('');
        await expect.poll(() => hasGreenTrace(audience)).toBe(true); expect(await image(next)).toBe(nextBefore);
        await page.screenshot({ path: info.outputPath('tools-pen-presenter.png'), fullPage: true }); await popup.screenshot({ path: info.outputPath('tools-pen-audience.png') });
        await button(page, 'Borrar trazos').click(); await expect.poll(() => pixel(canvas)).toEqual([0, 0, 255, 255]);
        // Popup pointer events are genuine gestures on its canvas too.
        await draw(popup, audience); await expect.poll(() => hasGreenTrace(canvas)).toBe(true);
        await button(page, 'Borrar trazos').click(); await ttl.fill('1'); await ttl.press('Tab'); await draw(page, canvas);
        await expect.poll(() => hasGreenTrace(canvas)).toBe(true); await expect.poll(() => pixel(canvas)).toEqual([0, 0, 255, 255]);
        await ttl.fill('10'); await ttl.press('Tab'); await draw(page, canvas);
        await button(page, 'Diapositiva siguiente').click(); await expect.poll(() => pixel(canvas)).toEqual([0, 255, 0, 255]);
        await button(page, 'Primera diapositiva').click(); await expect.poll(() => pixel(canvas)).toEqual([0, 0, 255, 255]);
        await draw(page, canvas); await button(page, 'Pantalla negra').click();
        for (const output of [canvas, audience]) {
            await expect.poll(() => output.evaluate((n: HTMLCanvasElement) => {
                const data = n.getContext('2d')!.getImageData(0, 0, n.width, n.height).data;
                for (let i = 0; i < data.length; i += 4) if (data[i] || data[i + 1] || data[i + 2] || data[i + 3] !== 255) return false; return true;
            })).toBe(true); await expect(output).toHaveCSS('cursor', 'none');
        }
        await button(page, 'Mostrar diapositiva').click();
        await popup.close(); const againPending = page.waitForEvent('popup'); await button(page, 'Abrir audiencia').click();
        const secondPopup = await againPending;
        const secondAudience = secondPopup.locator('body > canvas');
        await secondAudience.dblclick();
        await expect.poll(() => secondAudience.evaluate(n => document.fullscreenElement === n)).toBe(true);
        await secondPopup.screenshot({ path: info.outputPath('tools-fullscreen-audience.png') });
        await secondAudience.press('f');
        await expect.poll(() => secondPopup.evaluate(() => document.fullscreenElement === null)).toBe(true);
        await secondAudience.press('Shift+F');
        await expect.poll(() => secondAudience.evaluate(n => document.fullscreenElement === n)).toBe(true);
        await secondPopup.evaluate(() => document.exitFullscreen());
        await secondPopup.close();
        // After a vanished popup, reopening and closing the dialog must remain safe.
        const data = await saveNative(page, info, 'tools-pixels'); expect(data.events.filter((e: any) => e.event === 'call:play')).toEqual([]);
        await button(page, 'Cerrar presentación').click();
        expect((await read(page, id)).document.timeline.audio_tracks).toHaveLength(0);
    } finally { cleanComposerBrands(user.email); }
});

test('E2E-29 P3 timer uses a real own WAV with native start pause resume reset visibility volume mute loop and finish', async ({ page }, info) => {
    const { user, id } = await setup(page, info);
    const responses: any[] = [], responseWrites: Promise<void>[] = [];
    try {
        // Generated only when this future native test body actually executes.
        const count = 5600, bytes = Buffer.alloc(44 + count * 2);
        bytes.write('RIFF'); bytes.writeUInt32LE(bytes.length - 8, 4); bytes.write('WAVEfmt ', 8); bytes.writeUInt32LE(16, 16); bytes.writeUInt16LE(1, 20); bytes.writeUInt16LE(1, 22); bytes.writeUInt32LE(16000, 24); bytes.writeUInt32LE(32000, 28); bytes.writeUInt16LE(2, 32); bytes.writeUInt16LE(16, 34); bytes.write('data', 36); bytes.writeUInt32LE(count * 2, 40);
        for (let i = 0; i < count; i++) bytes.writeInt16LE(Math.round(10000 * Math.sin(2 * Math.PI * 440 * i / 16000)), 44 + i * 2);
        await deck(page, id); await musicUpload(page, info, bytes); await observe(page);
        page.on('response', response => { if (/\/studio\/assets\/\d+\/file/.test(response.url())) { const index = responses.length; const entry: any = { url: response.url(), status: response.status() }; responses.push(entry); responseWrites.push((async () => { const data = await response.body(); const file = `timer-response-${index}.bin`; fs.writeFileSync(info.outputPath(file), data); Object.assign(entry, { file, bytes: data.length, sha256: crypto.createHash('sha256').update(data).digest('hex'), headers: await response.allHeaders() }); })()); } });
        await open(page); const music = modal(page).getByLabel('Música del temporizador', { exact: true });
        const own = music.getByRole('option', { name: 'own-timer.wav', exact: true }); const ownId = await own.getAttribute('value'); expect(ownId).toMatch(/^\d+$/); await music.selectOption(ownId!);
        expect((await nativeSnapshot(page)).media).toEqual([]); await setSeconds(page, 30); await button(page, 'Iniciar temporizador').click();
        await expect.poll(() => page.evaluate(() => (window as any).__presentationProbe.events.some((e: any) => e.kind === 'AUDIO' && e.event === 'playing'))).toBe(true);
        await expect.poll(() => page.evaluate(() => {
            const events = (window as any).__presentationProbe.events.filter((e: any) => e.kind === 'AUDIO' && e.event === 'timeupdate');
            return events.some((e: any, i: number) => i > 0 && e.id === events[i - 1].id && e.time < events[i - 1].time);
        })).toBe(true);
        let snapshot = await saveNative(page, info, 'timer-running'); expect(snapshot.media).toHaveLength(1); expect(snapshot.media[0]).toMatchObject({ loop: true, volume: .4, muted: false, paused: false });
        await modal(page).getByLabel('Volumen de la música', { exact: true }).fill('0.2'); await modal(page).getByLabel('Silenciar música', { exact: true }).check();
        snapshot = await saveNative(page, info, 'timer-muted'); expect(snapshot.media[0]).toMatchObject({ volume: .2, muted: true });
        await button(page, 'Pausar temporizador').click(); await mutedAndPaused(page); const remaining = await modal(page).getByLabel('Tiempo restante', { exact: true }).textContent();
        await page.waitForTimeout(350); await expect(modal(page).getByLabel('Tiempo restante', { exact: true })).toHaveText(remaining!);
        const paused = await saveNative(page, info, 'timer-paused'); const songPosition = paused.media[0].currentTime;
        await button(page, 'Iniciar temporizador').click(); await expect.poll(() => page.evaluate(() => (window as any).__presentationProbe.nodes.filter((n: HTMLMediaElement) => !n.paused).length)).toBe(1);
        snapshot = await saveNative(page, info, 'timer-resumed'); expect(snapshot.media).toHaveLength(1); expect(snapshot.media[0].id).toBe(paused.media[0].id);
        const resumeCall = snapshot.events.filter((e: any) => e.event === 'call:play' && e.id === paused.media[0].id).at(-1); expect(resumeCall.time).toBe(songPosition);
        const visible = modal(page).getByLabel('Mostrar temporizador a la audiencia', { exact: true }); await visible.uncheck();
        const canvas = current(page); await expect.poll(() => pixel(canvas, .95, .05)).toEqual([0, 0, 255, 255]);
        const base = await image(canvas); await page.waitForTimeout(250); expect(await image(canvas)).toBe(base);
        expect((await nativeSnapshot(page)).media.some((n: any) => !n.paused)).toBe(true); await visible.check(); await expect.poll(() => image(canvas)).not.toBe(base);
        await page.screenshot({ path: info.outputPath('timer-own-music.png'), fullPage: true });
        await button(page, 'Reiniciar temporizador').click(); await mutedAndPaused(page); await expect(modal(page).getByLabel('Tiempo restante', { exact: true })).toHaveText('00:30');
        await setSeconds(page, 1); await button(page, 'Iniciar temporizador').click(); await expect(modal(page).getByLabel('Tiempo restante', { exact: true })).toHaveText('00:00'); await mutedAndPaused(page);
        await expect(modal(page).getByTestId('presentation-notes')).toHaveText('PRIVATE TOOLS NOTES Ω'); await expect(button(page, 'Reproducir presentación')).toBeVisible();
        await button(page, 'Cerrar presentación').evaluate(armPresentationCloseObserver); await button(page, 'Cerrar presentación').click();
        const close = await page.evaluate(readPresentationCloseObservation); expect(close?.trusted).toBe(true); expect(Number.isFinite(close?.at)).toBe(true);
        await page.waitForTimeout(250); snapshot = await saveNative(page, info, 'timer-finished-closed'); expect(snapshot.events.slice(close.eventIndex).filter((e: any) => ['call:play', 'play', 'playing'].includes(e.event))).toEqual([]);
        await Promise.all(responseWrites); expect(responses.some(r => [200, 206].includes(r.status) && r.bytes > 0)).toBe(true); fs.writeFileSync(info.outputPath('timer-responses.json'), JSON.stringify(responses, null, 2));
        expect((await read(page, id)).document.timeline.audio_tracks).toHaveLength(0);
    } finally { cleanComposerBrands(user.email); }
});

test('E2E-29 P3 pending own timer audio is cancelled by a real close and cannot publish or play after release', async ({ page }, info) => {
    const { user, id } = await setup(page, info); let release = () => {}, delivered = false;
    try {
        // This test owns an actual uploaded WAV, generated only inside its body.
        const count = 5600, bytes = Buffer.alloc(44 + count * 2);
        bytes.write('RIFF'); bytes.writeUInt32LE(bytes.length - 8, 4); bytes.write('WAVEfmt ', 8); bytes.writeUInt32LE(16, 16); bytes.writeUInt16LE(1, 20); bytes.writeUInt16LE(1, 22); bytes.writeUInt32LE(16000, 24); bytes.writeUInt32LE(32000, 28); bytes.writeUInt16LE(2, 32); bytes.writeUInt16LE(16, 34); bytes.write('data', 36); bytes.writeUInt32LE(count * 2, 40);
        for (let i = 0; i < count; i++) bytes.writeInt16LE(Math.round(10000 * Math.sin(2 * Math.PI * 440 * i / 16000)), 44 + i * 2);
        await deck(page, id); await musicUpload(page, info, bytes); await observe(page); await open(page);
        const music = modal(page).getByLabel('Música del temporizador', { exact: true }); const ownId = await music.getByRole('option', { name: 'own-timer.wav', exact: true }).getAttribute('value'); await music.selectOption(ownId!);
        const held = new Promise<void>(resolve => { release = resolve; }); let received!: () => void; const receivedResponse = new Promise<void>(resolve => { received = resolve; });
        const responses: any[] = [], routes: Promise<void>[] = [];
        await page.route(`**/studio/assets/${ownId}/file`, route => {
            const operation = (async () => {
                const response = await route.fetch(); expect([200, 206]).toContain(response.status()); const data = await response.body();
                const file = `pending-timer-response-${responses.length}.bin`; fs.writeFileSync(info.outputPath(file), data); responses.push({ file, url: response.url(), status: response.status(), bytes: data.length, sha256: crypto.createHash('sha256').update(data).digest('hex') }); received(); await held;
                // A page's cancelled request may reject fulfillment; preserve that event.
                try { await route.fulfill({ response }); delivered = true; } catch (error) { responses.push({ cancelledDelivery: String(error) }); }
            })(); routes.push(operation); return operation;
        });
        await button(page, 'Iniciar temporizador').click(); await receivedResponse;
        const outputs = await modal(page).locator('canvas').evaluateAll(nodes => nodes.map(n => (window as any).__presentationProbe.id(n)));
        await button(page, 'Cerrar presentación').evaluate(armPresentationCloseObserver); await button(page, 'Cerrar presentación').click();
        const close = await page.evaluate(readPresentationCloseObservation); expect(close?.trusted).toBe(true); expect(Number.isFinite(close?.at)).toBe(true); expect(Number.isSafeInteger(close?.eventIndex)).toBe(true);
        await expect(modal(page)).toHaveCount(0); await mutedAndPaused(page); release(); await Promise.all(routes); await page.waitForTimeout(350);
        const data = await saveNative(page, info, 'pending-timer-closed');
        expect(data.events.slice(close.eventIndex).filter((e: any) => ['call:play', 'play', 'playing'].includes(e.event))).toEqual([]);
        expect(data.draws.filter((d: any) => outputs.includes(d.target) && d.at > close.at)).toEqual([]); expect(data.media.every((n: any) => n.paused)).toBe(true);
        fs.writeFileSync(info.outputPath('pending-timer-responses.json'), JSON.stringify({ close, responses, delivered }, null, 2));
        await page.screenshot({ path: info.outputPath('pending-timer-after-release.png'), fullPage: true });
        expect((await read(page, id)).document.timeline.audio_tracks).toHaveLength(0);
    } finally { release(); cleanComposerBrands(user.email); }
});
