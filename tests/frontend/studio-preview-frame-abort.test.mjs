import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse } from '@babel/parser';
import { validateDocument, newElement } from '../../resources/js/Studio/document.js';
import { compileTimeline, sampleTimeline, clipMediaState } from '../../resources/js/Studio/timeline.js';

// Fabrica real de produccion; solo se sustituyen sus imports de navegador. `videoFrameReady` es un doble que NUNCA presenta
// fotograma y solo termina cuando se aborta la senal que recibe: cerrar el dialogo (dispose) tiene que abortar esa espera.
const source = await readFile(new URL('../../resources/js/Studio/timeline-preview.js', import.meta.url), 'utf8');
const ast = parse(source, { sourceType: 'module' });
const factory = ast.program.body.find(node => node.type === 'ExportNamedDeclaration' && node.declaration?.id?.name === 'createTimelinePreview').declaration;
const instantiate = Function('validateDocument', 'renderStudioPage', 'seekVideoFrame', 'clipMediaState', 'compileTimeline', 'sampleTimeline',
    'createTimelineAudio', 'createCaptionLayer', 'assertChartDocumentBudget', 'videoFrameReady', `return (${source.slice(factory.start, factory.end)});`);
const document = () => ({ schema_version: 2, width: 320, height: 180, timeline: { fps: 30, audio_tracks: [] }, pages: [
    { id: 'a', duration: 1, background: '#ff0000', elements: [newElement('video', { id: 'clip', asset_id: 1 })] },
] });

test('disposing an offline preview aborts a video frame wait that would never present', async () => {
    let received, entered;
    const reached = new Promise(resolve => { entered = resolve; });
    const video = { duration: 4, currentTime: 0, paused: true, volume: 1, pause() { this.paused = true; }, async play() { this.paused = false; } };
    const object = { studioVideo: video, set() {} };
    const create = instantiate(validateDocument,
        async () => ({ canvas: { getObjects: () => [object], renderAll() {}, lowerCanvasEl: {} }, async dispose() {} }),
        async (target, time) => { target.currentTime = time; }, clipMediaState, compileTimeline, sampleTimeline,
        () => ({ prepare: async () => ({ bufferingMs: 0 }), pause() {}, pauseMedia() {}, play: async () => {}, state: () => [], dispose: async () => {} }),
        async () => null, async () => {},
        (_video, { signal }) => new Promise((_resolve, reject) => {
            received = signal; entered();
            signal.addEventListener('abort', () => reject(new DOMException('Carga cancelada', 'AbortError')), { once: true });
        }));
    const canvas = { getContext: () => ({}) };
    const preview = await create(canvas, { document: document(), offline: true });
    const frame = preview.seek(0.1).catch(error => error);
    await reached;
    assert.ok(received instanceof AbortSignal);
    assert.equal(received.aborted, false);
    await preview.dispose();
    assert.equal(received.aborted, true);
    assert.equal((await frame)?.name, 'AbortError');
});
