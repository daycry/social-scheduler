import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import * as Vue from 'vue';
import * as tools from '../../resources/js/Studio/presentation-tools.js';
import * as timer from '../../resources/js/Studio/presentation-timer.js';
import { presentationKey, presentationPreviewScale } from '../../resources/js/Studio/presentation-player.js';
import { createAudienceWindow } from '../../resources/js/Studio/presentation-audience.js';

// Real compiled Vue SFC, real overlay/timer cores. Only rendering/media/clock
// boundaries are controlled. This is not browser or native playback evidence.
const source = await readFile(new URL('../../resources/js/Components/Studio/PresentationDialog.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const script = compileScript(descriptor, { id: 'tools-dialog', inlineTemplate: true });
const code = script.content.replace(/import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g, (_, names) =>
    `const {${names.replace(/\bas\b/g, ':')}} = imports;`).replace('export default', 'return');

function node(type = 'canvas') {
    const calls = [], listeners = new Map();
    const target = { type, tagName: type.toUpperCase(), props: {}, style: {}, children: [], parent: null, width: 320, height: 180,
        calls, listeners, captured: new Set(), value: '', paused: true,
        setAttribute(name, value) { this.props[name] = value; }, removeAttribute(name) { delete this.props[name]; },
        addEventListener(name, fn) { listeners.set(name, fn); }, removeEventListener(name) { listeners.delete(name); },
        setPointerCapture(id) { this.captured.add(id); }, releasePointerCapture(id) { this.captured.delete(id); }, hasPointerCapture(id) { return this.captured.has(id); },
        getBoundingClientRect: () => ({ left: 0, top: 0, width: 400, height: 400 }),
        showModal() {}, close() {}, focus() {},
        play() { this.paused = false; calls.push(['play']); return Promise.resolve(); }, pause() { this.paused = true; calls.push(['pause']); }, load() { calls.push(['load']); },
        requestFullscreen() { calls.push(['fullscreen']); return Promise.resolve(); },
    };
    const ctx = new Proxy({ canvas: target }, { get(obj, key) { if (key in obj) return obj[key]; return (...args) => calls.push([key, ...args]); }, set(obj, key, value) { obj[key] = value; calls.push(['set', key, value]); return true; } });
    target.getContext = () => ctx;
    Object.defineProperty(target, 'options', { get: () => tree(target).filter(n => n.type === 'option') });
    return Vue.markRaw(target); // Native DOM nodes are also excluded from Vue proxies.
}
function tree(root) { return [root, ...root.children.flatMap(tree)]; }
async function mount({ assets = [], audioPlay, rendererError = '' } = {}) {
    let now = 0, serial = 0; const frames = new Map(), ticks = new Map(), players = [], audios = [], popups = [];
    const old = Object.fromEntries(['document', 'window', 'requestAnimationFrame', 'cancelAnimationFrame', 'setTimeout', 'clearTimeout', 'performance'].map(key => [key, globalThis[key]]));
    const root = node('root'), body = node('body');
    globalThis.document = { activeElement: node('button'), createElement(type) { const n = node(type); if (type === 'audio') { audios.push(n); if (audioPlay) n.play = () => { n.paused = false; n.calls.push(['play']); return audioPlay(n); }; } return n; } };
    globalThis.performance = { now: () => now };
    globalThis.requestAnimationFrame = callback => { frames.set(++serial, callback); return serial; };
    globalThis.cancelAnimationFrame = id => frames.delete(id);
    globalThis.setTimeout = callback => { ticks.set(++serial, callback); return serial; };
    globalThis.clearTimeout = id => ticks.delete(id);
    globalThis.window = { open() { const canvas = node(), events = new Map(); const popup = { closed: false, document: { body: { style: {}, replaceChildren(n) { this.canvas = n; } }, createElement: () => canvas }, addEventListener: (k, f) => events.set(k, f), removeEventListener: k => events.delete(k), close() { this.closed = true; }, events, canvas }; popups.push(popup); return popup; } };
    const value = { pageId: 'one', pageIds: ['one', 'two'], nextPageId: 'two', index: 0, pageName: 'One', nextPageName: 'Two', notes: 'PRIVATE', playing: false, blackout: false, error: rendererError };
    function createPresentationPlayer(options) {
        const base = node(), calls = [], ownState = { ...value }; const player = { options, base, calls,
            ready: async () => { options.onState?.({ ...ownState }); options.publish?.(base, { ...ownState }); },
            select: async id => { calls.push(['select', id]); ownState.pageId = id; options.onState?.({ ...ownState }); },
            play: async () => calls.push(['play']), pause: () => calls.push(['pause']),
            setBlackout: async v => { ownState.blackout = v; options.onState?.({ ...ownState }); options.publish?.(base, { ...ownState }); },
            dispose: async () => calls.push(['dispose']),
        }; players.push(player); return player;
    }
    const component = new Function('imports', code)({ ...Vue, ...tools, ...timer, createPresentationPlayer, presentationKey, presentationPreviewScale, createTimelinePreview() {}, createAudienceWindow });
    const renderer = Vue.createRenderer({
        createElement: type => node(type), createText: text => Object.assign(node('text'), { text }), createComment: text => Object.assign(node('comment'), { text }),
        setText(n, text) { n.text = text; }, setElementText(n, text) { n.text = text; n.children = []; },
        parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1], querySelector: () => body,
        insert(n, parent, anchor) { if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = parent; const i = parent.children.indexOf(anchor); parent.children.splice(i < 0 ? parent.children.length : i, 0, n); },
        remove(n) { n.parent?.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; },
        patchProp(n, key, previous, next) { n.props[key] = next; if (key === 'style') n.style = { ...next }; },
    });
    const app = renderer.createApp(component, { document: { pages: [{ id: 'one' }, { id: 'two' }] }, assets }); app.mount(root);
    await Vue.nextTick(); await Vue.nextTick(); await Vue.nextTick();
    const all = () => tree(body);
    const find = label => { const n = all().find(n => n.props['aria-label'] === label || n.text === label); assert.ok(n, `Usable control is absent: ${label}`); return n; };
    async function event(label, name, value) { const n = find(label); const e = { target: n, currentTarget: n, type: name.toLowerCase(), ...value }; n.props[`on${name}`]?.(e); await Vue.nextTick(); await Promise.resolve(); return n; }
    const flush = () => { const batch = [...frames.values()]; frames.clear(); for (const callback of batch) callback(now); };
    return { all, find, event, frames, ticks, players, audios, popups, value, flush, body,
        advance(ms) { now += ms; const batch = [...ticks.values()]; ticks.clear(); for (const callback of batch) callback(); flush(); },
        async dispose() { app.unmount(); for (const [key, value] of Object.entries(old)) { if (value === undefined) delete globalThis[key]; else globalThis[key] = value; } await Vue.nextTick(); },
    };
}
const asset = { id: 7, kind: 'audio', status: 'listo', name: 'Own sound', duration: 1, audio_codec: 'pcm_s16le', studio_url: '/brands/2/studio/assets/7/file' };

test('compiled dialog exposes connected cursor pen and timer controls without autoplay', async () => {
    const h = await mount({ assets: [asset] });
    try {
        h.find('Herramientas');
        await h.event('Cursor de presentación', 'Change', { target: { value: 'laser' } });
        await h.event('Dibujar', 'Change', { target: { checked: true } });
        await h.event('Color del trazo', 'Input', { target: { value: '#00ff00' } });
        await h.event('Grosor del trazo', 'Input', { target: { value: '0.02' } });
        await h.event('Duración de los trazos en segundos', 'Change', { target: { value: '1' } });
        await h.event('Música del temporizador', 'Change', { target: { value: '7' } });
        assert.equal(h.audios.length, 0); assert.equal(h.ticks.size, 0);
        await h.event('Duración del temporizador en segundos', 'Change', { target: { value: '2' } });
        await h.event('Iniciar temporizador', 'Click'); assert.equal(h.audios.length, 1); assert.equal(h.audios[0].paused, false);
        assert.equal(h.audios[0].loop, true); assert.equal(h.audios[0].volume, .4);
        await h.event('Volumen de la música', 'Input', { target: { value: '0.2' } });
        await h.event('Silenciar música', 'Change', { target: { checked: true } });
        assert.equal(h.audios[0].volume, .2); assert.equal(h.audios[0].muted, true);
        h.advance(500); await h.event('Pausar temporizador', 'Click'); assert.equal(h.audios[0].paused, true); assert.equal(h.ticks.size, 0);
        await h.event('Iniciar temporizador', 'Click'); h.advance(2000); assert.equal(h.audios.at(-1).paused, true); assert.equal(h.ticks.size, 0);
        await h.event('Reiniciar temporizador', 'Click');
        assert.ok(h.all().some(n => n.text === '00:02'));
        assert.equal(h.players[0].calls.filter(c => c[0] === 'play').length, 0);
    } finally { await h.dispose(); }
});

test('pointer composition uses contain coordinates, capture and real core painter on paused base only', async () => {
    const h = await mount();
    try {
        await h.event('Cursor de presentación', 'Change', { target: { value: 'laser' } });
        const canvas = h.find('Diapositiva de audiencia');
        await h.event('Diapositiva de audiencia', 'Pointermove', { pointerId: 1, clientX: 200, clientY: 200 });
        h.flush(); assert.ok(canvas.calls.some(c => c[0] === 'arc' && c[1] === 160 && c[2] === 90));
        assert.equal(h.frames.size, 0, 'stationary laser must not loop');
        await h.event('Dibujar', 'Change', { target: { checked: true } });
        await h.event('Diapositiva de audiencia', 'Pointerdown', { pointerId: 1, clientX: 100, clientY: 160 }); assert.ok(canvas.captured.has(1));
        await h.event('Diapositiva de audiencia', 'Pointermove', { pointerId: 2, clientX: 300, clientY: 160 });
        await h.event('Diapositiva de audiencia', 'Pointermove', { pointerId: 1, clientX: 200, clientY: 200 });
        assert.equal(h.frames.size, 1, 'pointer updates coalesce'); h.flush();
        assert.ok(canvas.calls.some(c => c[0] === 'lineTo' && c[1] === 160 && c[2] === 90));
        const next = h.find('Vista previa siguiente'); assert.equal(next.calls.length, 0);
        await h.event('Diapositiva de audiencia', 'Pointerup', { pointerId: 1, clientX: 200, clientY: 10 }); assert.equal(canvas.captured.size, 0);
        h.flush(); assert.equal(h.frames.size, 1, 'only fading strokes animate');
        h.advance(4000); assert.equal(h.frames.size, 0);
        assert.equal(h.players[0].calls.filter(c => ['select', 'play'].includes(c[0])).length, 0);
    } finally { await h.dispose(); }
});

test('audience receives composite immediately, owns pointer listeners and blackout hides every cursor', async () => {
    const h = await mount();
    try {
        await h.event('Cursor de presentación', 'Change', { target: { value: 'laser' } });
        await h.event('Diapositiva de audiencia', 'Pointermove', { pointerId: 1, clientX: 200, clientY: 200 }); h.flush();
        await h.event('Abrir audiencia', 'Click'); const popup = h.popups[0], canvas = h.find('Diapositiva de audiencia');
        assert.equal(popup.canvas.calls.find(c => c[0] === 'drawImage')[1], canvas);
        popup.canvas.listeners.get('pointerdown')?.({ type: 'pointerdown', pointerId: 3, clientX: 200, clientY: 200, preventDefault() {} });
        assert.ok(popup.canvas.captured.has(3));
        await h.event('Pantalla negra', 'Click'); h.flush();
        assert.equal(canvas.style.cursor, 'none'); assert.equal(popup.canvas.style.cursor, 'none');
        assert.equal(canvas.calls.at(-1)[0], 'fillRect');
        await h.event('Pantalla completa', 'Click'); assert.ok(popup.canvas.calls.some(c => c[0] === 'fullscreen'));
        popup.events.get('pagehide')(); assert.equal(popup.canvas.listeners.size, 0);
    } finally { await h.dispose(); }
});

test('button Space keeps native activation while modal stops editor shortcuts', async () => {
    const h = await mount();
    try {
        const dialog = h.find('Presentación'); let stopped = 0, prevented = 0;
        dialog.props.onKeydown({ key: ' ', target: { tagName: 'BUTTON' }, stopPropagation() { stopped++; }, preventDefault() { prevented++; } });
        await Promise.resolve(); assert.equal(stopped, 1); assert.equal(prevented, 0); assert.equal(h.players[0].calls.filter(c => c[0] === 'play').length, 0);
        dialog.props.onKeydown({ key: ' ', target: { tagName: 'INPUT' }, stopPropagation() {}, preventDefault() { prevented++; } }); assert.equal(prevented, 0);
    } finally { await h.dispose(); }
});

test('music error is independent of renderer error and late play cannot survive synchronous close', async () => {
    let reject; const h = await mount({ assets: [asset], rendererError: 'Renderer failure', audioPlay: () => new Promise((_, r) => { reject = r; }) });
    try {
        await h.event('Música del temporizador', 'Change', { target: { value: '7' } }); await h.event('Iniciar temporizador', 'Click');
        reject(new Error('Own music failure')); await Promise.resolve(); await Promise.resolve(); await Vue.nextTick();
        assert.ok(h.all().some(n => n.text === 'Own music failure')); assert.ok(h.all().some(n => n.text === 'Renderer failure'));
        await h.event('Iniciar temporizador', 'Click'); const audio = h.audios.at(-1);
        await h.event('Cerrar presentación', 'Click'); assert.equal(audio.paused, true); assert.equal(h.frames.size, 0); assert.equal(h.ticks.size, 0);
        reject(new Error('Late failure')); await Promise.resolve(); await Promise.resolve(); assert.equal(h.frames.size, 0); assert.equal(audio.paused, true);
    } finally { await h.dispose(); }
});

test('popup pagehide cancels its captured pointer so the main canvas can continue drawing', async () => {
    const h = await mount();
    try {
        await h.event('Dibujar', 'Change', { target: { checked: true } }); await h.event('Abrir audiencia', 'Click');
        const popup = h.popups[0];
        popup.canvas.listeners.get('pointerdown')({ type: 'pointerdown', pointerId: 4, clientX: 200, clientY: 200, preventDefault() {} });
        assert.ok(popup.canvas.captured.has(4)); popup.events.get('pagehide')();
        assert.equal(popup.canvas.captured.size, 0, 'closing the popup must release its gesture');
        const main = await h.event('Diapositiva de audiencia', 'Pointerdown', { pointerId: 8, clientX: 200, clientY: 200 });
        assert.ok(main.captured.has(8), 'the main canvas must not be locked by the vanished popup');
    } finally { await h.dispose(); }
});

test('configured pen paint, clearing, page changes and timer visibility reuse the stable committed base', async () => {
    const h = await mount();
    try {
        await h.event('Dibujar', 'Change', { target: { checked: true } });
        await h.event('Color del trazo', 'Input', { target: { value: '#00ff00' } });
        await h.event('Grosor del trazo', 'Input', { target: { value: '0.02' } });
        await h.event('Duración de los trazos en segundos', 'Change', { target: { value: '1' } });
        const main = await h.event('Diapositiva de audiencia', 'Pointerdown', { pointerId: 4, clientX: 200, clientY: 10 });
        assert.equal(main.captured.size, 0, 'letterbox bands must not capture or draw');
        await h.event('Diapositiva de audiencia', 'Pointerdown', { pointerId: 4, clientX: 100, clientY: 200 });
        await h.event('Diapositiva de audiencia', 'Pointermove', { pointerId: 4, clientX: 300, clientY: 200 }); h.flush();
        assert.ok(main.calls.some(c => c[0] === 'set' && c[1] === 'strokeStyle' && c[2] === '#00ff00'));
        assert.ok(main.calls.some(c => c[0] === 'set' && c[1] === 'lineWidth' && c[2] === 3.6));
        const base = main.calls.find(c => c[0] === 'drawImage')[1]; assert.notEqual(base, h.players[0].base, 'the mutable renderer surface cannot be the retained base');
        assert.equal(base.calls.filter(c => c[0] === 'drawImage').length, 1);
        const before = main.calls.length; await h.event('Borrar trazos', 'Click'); h.flush();
        assert.equal(main.captured.size, 0); assert.equal(main.calls.slice(before).filter(c => c[0] === 'stroke').length, 0);
        await h.event('Diapositiva de audiencia', 'Pointerdown', { pointerId: 5, clientX: 200, clientY: 200 });
        await h.event('Diapositiva de audiencia', 'Pointercancel', { pointerId: 5, clientX: 200, clientY: 200 }); h.flush();
        assert.equal(main.captured.size, 0); h.advance(1100); assert.equal(h.frames.size, 0);
        await h.event('Cursor de presentación', 'Change', { target: { value: 'laser' } });
        await h.event('Diapositiva de audiencia', 'Pointermove', { pointerId: 7, clientX: 200, clientY: 200 }); h.flush();
        const pageStart = main.calls.length; await h.players[0].select('two'); h.flush();
        assert.equal(main.calls.slice(pageStart).filter(c => c[0] === 'arc').length, 0, 'navigation clears pointer and trails');
        await h.event('Iniciar temporizador', 'Click'); h.flush();
        assert.ok(main.calls.some(c => c[0] === 'fillText' && c[1] === '05:00'));
        await h.event('Mostrar temporizador a la audiencia', 'Change', { target: { checked: false } });
        const hiddenStart = main.calls.length; h.flush(); assert.equal(main.calls.slice(hiddenStart).filter(c => c[0] === 'fillText').length, 0);
        assert.equal(h.ticks.size, 1, 'hiding the clock keeps its countdown alive');
        assert.equal(base.calls.filter(c => c[0] === 'drawImage').length, 1, 'tool changes must never seek or refresh renderer pixels');
        await h.event('Pausar temporizador', 'Click'); h.flush(); assert.equal(h.ticks.size, 0); assert.equal(h.frames.size, 0);
        await h.event('Pantalla completa', 'Click'); assert.ok(main.calls.some(c => c[0] === 'fullscreen'));
    } finally { await h.dispose(); }
});

test('unmount synchronously stops the timer and cancels overlay work before async renderer disposal', async () => {
    let resolve; const h = await mount({ assets: [asset], audioPlay: () => new Promise(r => { resolve = r; }) });
    await h.event('Música del temporizador', 'Change', { target: { value: '7' } }); await h.event('Iniciar temporizador', 'Click');
    assert.equal(h.ticks.size, 1); assert.equal(h.frames.size, 1);
    let disposing = 0;
    for (const player of h.players) player.dispose = async () => {
        assert.equal(h.audios[0].paused, true); assert.equal(h.ticks.size, 0); assert.equal(h.frames.size, 0); disposing++;
    };
    const main = h.find('Diapositiva de audiencia'); await h.dispose(); const count = main.calls.length;
    assert.equal(disposing, 2); resolve(); await Promise.resolve(); await Promise.resolve();
    assert.equal(h.audios[0].paused, true); assert.equal(main.calls.length, count); assert.equal(h.frames.size, 0);
});

test('recomposition clears previous overlays before drawing a possibly transparent base', async () => {
    const h = await mount();
    try {
        h.flush(); const canvas = h.find('Diapositiva de audiencia');
        const firstImage = canvas.calls.findIndex(c => c[0] === 'drawImage');
        assert.ok(canvas.calls.slice(0, firstImage).some(c => c[0] === 'clearRect'), 'transparent base pixels cannot erase old overlays by source-over alone');
        const start = canvas.calls.length; await h.event('Iniciar temporizador', 'Click'); h.flush();
        const draw = canvas.calls.slice(start).findIndex(c => c[0] === 'drawImage');
        assert.ok(canvas.calls.slice(start, start + draw).some(c => c[0] === 'clearRect'));
    } finally { await h.dispose(); }
});

function audienceHarness(options = {}) {
    const canvas = node(), events = new Map(), forwarded = [], errors = [];
    const document = { fullscreenElement: null, body: { style: {}, children: [], replaceChildren(n) { this.children = [n]; } }, createElement: () => canvas,
        exitFullscreen() { canvas.calls.push(['exitFullscreen']); this.fullscreenElement = null; return Promise.resolve(); } };
    canvas.requestFullscreen = () => { canvas.calls.push(['fullscreen']); document.fullscreenElement = canvas; return Promise.resolve(); };
    const popup = { closed: false, document, addEventListener: (k, f) => events.set(k, f), removeEventListener: k => events.delete(k), close() { this.closed = true; } };
    const bridge = createAudienceWindow({ opener: { open: () => popup }, onKey: e => forwarded.push(e), onError: e => errors.push(e), ...options });
    const fire = (type, extra = {}) => {
        const e = { type, isTrusted: true, key: 'f', preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; }, ...extra };
        (type === 'keydown' ? events : canvas.listeners).get(type)?.(e); return e;
    };
    return { canvas, events, popup, document, bridge, forwarded, errors, fire };
}

test('audience gestures synchronously toggle fullscreen with trusted double click and F or Shift-F', async () => {
    const h = audienceHarness();
    try {
        h.fire('dblclick'); assert.equal(h.document.fullscreenElement, h.canvas, 'double click requests fullscreen synchronously in its own context');
        h.fire('keydown'); assert.equal(h.document.fullscreenElement, null, 'F exits synchronously');
        h.fire('keydown', { key: 'F', shiftKey: true }); assert.equal(h.document.fullscreenElement, h.canvas);
        assert.equal(h.forwarded.length, 0, 'fullscreen keys must not also command the presenter');
        assert.equal(h.canvas.props.tabindex, '0');
        assert.match(h.canvas.props.title, /Doble clic o F para pantalla completa/);
        assert.match(h.canvas.props['aria-label'], /Doble clic o F para pantalla completa/);
        assert.deepEqual(h.document.body.children, [h.canvas]);
        await Promise.resolve();
    } finally { h.bridge.close(); }
});

test('audience gestures reject untrusted modified and repeat inputs while forwarding other keys', async () => {
    const h = audienceHarness();
    try {
        for (const extra of [{ isTrusted: false }, { ctrlKey: true }, { altKey: true }, { metaKey: true }, { repeat: true }]) h.fire('keydown', extra);
        h.fire('dblclick', { isTrusted: false });
        assert.equal(h.canvas.calls.filter(c => c[0] === 'fullscreen').length, 0);
        assert.equal(h.forwarded.length, 0, 'rejected F keys are consumed instead of becoming a presenter command');
        const arrow = h.fire('keydown', { key: 'ArrowRight' }); assert.equal(h.forwarded.at(-1), arrow);
        await h.bridge.fullscreen(); assert.equal(h.document.fullscreenElement, h.canvas, 'legacy public method still works');
    } finally { h.bridge.close(); }
});

test('audience gestures report actionable current rejection and preserve public rejection semantics', async () => {
    const h = audienceHarness();
    try {
        h.canvas.requestFullscreen = () => Promise.reject(new Error('Activation denied'));
        h.fire('dblclick'); await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
        assert.equal(h.errors.length, 1); assert.match(h.errors[0].message, /Doble clic o F/);
        await assert.rejects(h.bridge.fullscreen(), /Doble clic o F/);
        h.document.fullscreenElement = h.canvas;
        h.document.exitFullscreen = () => { throw new Error('Exit denied'); };
        h.fire('keydown'); await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
        assert.equal(h.errors.length, 2);
    } finally { h.bridge.close(); }
});

test('audience gestures clean listeners and captures and suppress pending completion after close or pagehide', async () => {
    for (const kind of ['close', 'pagehide']) for (const fail of [false, true]) {
        const cancelled = [], h = audienceHarness({ onPointer: e => cancelled.push(e) }); let settle;
        h.canvas.requestFullscreen = () => new Promise((resolve, reject) => { settle = fail ? reject : resolve; });
        h.fire('pointerdown', { pointerId: 12 }); h.canvas.setPointerCapture(12);
        const lateGesture = h.canvas.listeners.get('dblclick'), lateKey = h.events.get('keydown');
        h.fire('dblclick'); const before = h.canvas.calls.length;
        if (kind === 'close') h.bridge.close(); else h.events.get('pagehide')();
        assert.equal(h.canvas.listeners.size, 0); assert.equal(h.events.size, 0);
        assert.equal(h.canvas.captured.size, 0, 'bridge releases its captures even without a Dialog callback');
        assert.ok(cancelled.some(e => e.type === 'pointercancel' && e.pointerId === 12));
        settle(fail ? new Error('late denied') : undefined); await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
        lateGesture({ isTrusted: true }); lateKey({ isTrusted: true, key: 'f' });
        assert.equal(h.canvas.calls.length, before); assert.equal(h.errors.length, 0);
        assert.equal(h.bridge.paint(node()), false);
        await h.bridge.fullscreen();
    }
});

test('compiled dialog displays own audience gesture rejection as an actionable alert', async () => {
    const h = await mount();
    try {
        await h.event('Abrir audiencia', 'Click'); const popup = h.popups[0];
        popup.canvas.requestFullscreen = () => Promise.reject(new Error('Denied'));
        popup.canvas.listeners.get('dblclick')?.({ isTrusted: true, preventDefault() {}, stopPropagation() {} });
        await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); await Vue.nextTick();
        assert.ok(h.all().some(n => n.props.role === 'alert' && /Doble clic o F/.test(n.text)), 'audience rejection must reach the visible dialog alert');
    } finally { await h.dispose(); }
});

test('audience public fullscreen pending at pagehide returns stale without throwing or publishing', async () => {
    for (const fail of [false, true]) {
        const h = audienceHarness(); let settle;
        h.canvas.requestFullscreen = () => new Promise((resolve, reject) => { settle = fail ? reject : resolve; });
        const pending = h.bridge.fullscreen(); h.events.get('pagehide')();
        settle(fail ? new Error('old request') : undefined);
        assert.equal(await pending, false); assert.equal(h.errors.length, 0);
    }
});

test('compiled dialog ignores old audience fullscreen completion after replacing the popup', async () => {
    for (const fail of [false, true]) {
        const h = await mount(); let settle;
        try {
            await h.event('Abrir audiencia', 'Click'); const old = h.popups[0];
            old.canvas.requestFullscreen = () => new Promise((resolve, reject) => { settle = fail ? reject : resolve; });
            await h.event('Pantalla completa', 'Click');
            await h.event('Abrir audiencia', 'Click'); const fresh = h.popups[1];
            fresh.canvas.requestFullscreen = () => Promise.reject(new Error('current request'));
            fresh.canvas.listeners.get('dblclick')({ isTrusted: true });
            await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); await Vue.nextTick();
            const alert = () => h.all().find(n => n.props.role === 'alert' && /Doble clic o F/.test(n.text));
            assert.ok(alert()); const currentText = alert().text;
            settle(fail ? new Error('old request') : undefined);
            await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); await Vue.nextTick();
            assert.equal(alert()?.text, currentText, 'a stale completion cannot clear or replace the current popup error');
            assert.equal(h.popups.length, 2); assert.equal(old.closed, true); assert.equal(fresh.closed, false);
        } finally { await h.dispose(); }
    }
});

for (const fail of [false, true]) test(`compiled dialog preserves current popup error after old main fullscreen ${fail ? 'rejects' : 'resolves'}`, async () => {
    const h = await mount(); let settle;
    try {
        const main = h.find('Diapositiva de audiencia');
        main.requestFullscreen = () => new Promise((resolve, reject) => { settle = fail ? reject : resolve; });
        await h.event('Pantalla completa', 'Click');
        await h.event('Abrir audiencia', 'Click'); const popup = h.popups[0];
        popup.canvas.requestFullscreen = () => Promise.reject(new Error('current popup request'));
        popup.canvas.listeners.get('dblclick')({ isTrusted: true });
        await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); await Vue.nextTick();
        const alert = () => h.all().find(n => n.props.role === 'alert');
        assert.match(alert()?.text, /Doble clic o F/);
        const currentText = alert().text;
        settle(fail ? new Error('old main request failed') : undefined);
        await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); await Vue.nextTick();
        assert.equal(alert()?.text, currentText, 'old main surface cannot clear or replace the current popup alert');
        assert.equal(popup.closed, false);
    } finally { await h.dispose(); }
});

test('compiled dialog publishes current main fullscreen rejection and clears it on current main success', async () => {
    const h = await mount();
    try {
        const main = h.find('Diapositiva de audiencia'); let reject, resolve;
        main.requestFullscreen = () => new Promise((_, r) => { reject = r; });
        await h.event('Pantalla completa', 'Click');
        reject(new Error('current main request failed'));
        await Promise.resolve(); await Promise.resolve(); await Vue.nextTick();
        const alert = () => h.all().find(n => n.props.role === 'alert');
        assert.equal(alert()?.text, 'current main request failed');
        main.requestFullscreen = () => new Promise(r => { resolve = r; });
        await h.event('Pantalla completa', 'Click');
        assert.equal(alert()?.text, 'current main request failed', 'pending request keeps the current error visible');
        resolve(); await Promise.resolve(); await Promise.resolve(); await Vue.nextTick();
        assert.equal(alert(), undefined, 'a successful request by the current main owner clears its error');
        assert.equal(h.popups.length, 0);
    } finally { await h.dispose(); }
});
