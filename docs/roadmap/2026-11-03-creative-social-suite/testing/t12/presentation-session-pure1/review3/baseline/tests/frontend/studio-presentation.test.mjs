import test from 'node:test';
import assert from 'node:assert/strict';
import { validateDocument } from '../../resources/js/Studio/document.js';

const target = new URL('../../resources/js/Studio/presentation.js', import.meta.url);
const subject = await import(target).catch(error => {
    if (error.code === 'ERR_MODULE_NOT_FOUND' && error.url === target.href) return {};
    throw error;
});
const create = (...args) => {
    assert.equal(typeof subject.createPresentationSession, 'function', 'presentation session export is absent');
    return subject.createPresentationSession(...args);
};
const document = () => ({ schema_version: 1, width: 800, height: 500,
    pages: [100, 250, 600].map((ms, i) => ({ id: `p${i}`, name: `Página ${i} é`, duration: ms / 1000, background: '#ffffff', elements: [] })) });

test('initial session exposes selected order, duration and immutable presentation state', () => {
    const input = document(); const session = create(input, { pageIds: ['p2', 'p0'], startPageId: 'p0' });
    assert.deepEqual(session.snapshot(), { pageIds: ['p2', 'p0'], index: 1, pageId: 'p0', pageName: 'Página 0 é', nextPageId: null,
        elapsedMs: 0, durationMs: 100, playing: false, ended: false, blackout: false, disposed: false });
    assert.ok(Object.isFrozen(session.snapshot())); assert.ok(Object.isFrozen(session.snapshot().pageIds));
    assert.throws(() => session.snapshot().pageIds.push('evil'), TypeError);
    input.pages[0].name = 'changed'; input.pages[0].duration = 90; input.pages.reverse();
    assert.equal(session.snapshot().pageName, 'Página 0 é'); assert.equal(session.snapshot().durationMs, 100);
    assert.deepEqual(session.first().pageIds, ['p2', 'p0']); assert.equal(session.snapshot().pageId, 'p2');
});

test('autoplay crosses exact boundaries and preserves fractional milliseconds', () => {
    const s = create(document()); s.play();
    assert.equal(s.advance(99.5).elapsedMs, 99.5);
    assert.equal(s.advance(0.5).pageId, 'p1'); assert.equal(s.snapshot().elapsedMs, 0);
    assert.equal(s.advance(300.125).pageId, 'p2'); assert.equal(s.snapshot().elapsedMs, 50.125);
    assert.equal(s.snapshot().playing, true); assert.equal(s.snapshot().nextPageId, null);
});

test('paused time is ignored and resuming preserves the current fraction', () => {
    const s = create(document()); assert.equal(s.advance(300).elapsedMs, 0);
    s.play(); s.advance(33.75); s.pause();
    assert.equal(s.advance(900000).elapsedMs, 33.75); assert.equal(s.snapshot().playing, false);
    s.play(); assert.equal(s.advance(66.25).pageId, 'p1'); assert.equal(s.snapshot().elapsedMs, 0);
});

test('nonloop completion stops on the final page and play restarts the selected order', () => {
    const s = create(document(), { pageIds: ['p1', 'p0'] }); s.play();
    assert.equal(s.advance(350).pageId, 'p0');
    assert.equal(s.snapshot().elapsedMs, 100); assert.equal(s.snapshot().ended, true); assert.equal(s.snapshot().playing, false);
    s.advance(1); assert.equal(s.snapshot().elapsedMs, 100);
    assert.equal(s.play().pageId, 'p1'); assert.equal(s.snapshot().elapsedMs, 0); assert.equal(s.snapshot().ended, false);
    s.advance(Number.MAX_SAFE_INTEGER); assert.equal(s.snapshot().pageId, 'p0'); assert.equal(s.snapshot().elapsedMs, 100);
});

test('loop handles enormous deltas in bounded work and retains an existing fractional offset', () => {
    const s = create(document(), { loop: true }); s.play(); s.advance(0.125);
    const remainder = Number.MAX_SAFE_INTEGER % 950;
    let index = 0; let offset = remainder + 0.125;
    for (const ms of [100, 250, 600]) { if (offset < ms) break; offset -= ms; index++; }
    s.advance(Number.MAX_SAFE_INTEGER);
    assert.equal(s.snapshot().index, index); assert.equal(s.snapshot().elapsedMs, offset); assert.equal(s.snapshot().ended, false);
    const single = create(document(), { pageIds: ['p1'], loop: true }); single.play();
    assert.equal(single.advance(250 * 1000000000).elapsedMs, 0); assert.equal(single.snapshot().pageId, 'p1');
    assert.equal(single.snapshot().nextPageId, 'p1');
});

test('manual navigation resets timing while preserving play and handles both endpoints', () => {
    const s = create(document()); s.play(); s.advance(10); s.next();
    assert.equal(s.snapshot().pageId, 'p1'); assert.equal(s.snapshot().elapsedMs, 0); assert.equal(s.snapshot().playing, true);
    assert.equal(s.previous().pageId, 'p0'); assert.equal(s.previous().pageId, 'p0');
    assert.equal(s.last().pageId, 'p2'); assert.equal(s.next().ended, true); assert.equal(s.snapshot().elapsedMs, 600);
    assert.equal(s.first().pageId, 'p0'); assert.equal(s.snapshot().ended, false); assert.equal(s.snapshot().playing, false);
    assert.equal(s.select('p1').pageId, 'p1'); assert.equal(s.snapshot().elapsedMs, 0);
    const looping = create(document(), { loop: true });
    assert.equal(looping.previous().pageId, 'p2'); assert.equal(looping.next().pageId, 'p0');
});

test('selection and advance failures are atomic even while paused', () => {
    const s = create(document()); s.play(); s.advance(12.5); s.pause(); const before = s.snapshot();
    for (const id of ['missing', 0, undefined, {}, null]) { assert.throws(() => s.select(id), /página|page/i); assert.deepEqual(s.snapshot(), before); }
    for (const delta of [-1, Infinity, NaN, -Infinity, Number.MAX_SAFE_INTEGER + 1, '5', null, undefined, {}]) {
        assert.throws(() => s.advance(delta), /tiempo|milisegundos|delta/i); assert.deepEqual(s.snapshot(), before);
    }
});

test('blackout changes only visibility state and rejects nonbooleans atomically', () => {
    const s = create(document()); s.play(); s.advance(50); const before = s.snapshot();
    assert.deepEqual(s.setBlackout(true), { ...before, blackout: true });
    s.advance(100); assert.equal(s.snapshot().pageId, 'p1'); assert.equal(s.snapshot().elapsedMs, 50); assert.equal(s.snapshot().playing, true);
    const black = s.snapshot(); assert.throws(() => s.setBlackout('yes'), /boolean/i); assert.deepEqual(s.snapshot(), black);
    assert.equal(s.setBlackout(false).blackout, false);
});

test('dispose is idempotent and rejects every later command without mutation', () => {
    const s = create(document()); s.play(); s.advance(25); s.setBlackout(true);
    const closed = s.dispose(); assert.equal(closed.disposed, true); assert.equal(closed.playing, false); assert.equal(closed.elapsedMs, 25);
    assert.deepEqual(s.dispose(), closed);
    for (const invoke of [() => s.play(), () => s.pause(), () => s.next(), () => s.previous(), () => s.first(), () => s.last(), () => s.select('p0'), () => s.advance(1), () => s.setBlackout(false)]) {
        assert.throws(invoke, /sesión cerrada/i); assert.deepEqual(s.snapshot(), closed);
    }
});

test('session validates the real document and preserves temporal document inputs', () => {
    const input = document(); input.schema_version = 2;
    input.timeline = { fps: 30, audio_tracks: [] };
    input.pages[0].transition = { type: 'fade', duration: 0.05 };
    const valid = validateDocument(input); const original = JSON.stringify(valid);
    const s = create(valid); s.play(); s.advance(200); s.dispose();
    assert.equal(JSON.stringify(valid), original); assert.equal(s.snapshot().pageId, 'p1'); assert.equal(s.snapshot().durationMs, 250);
    for (const bad of [null, {}, { ...document(), schema_version: 17 }, { ...document(), pages: [] }]) assert.throws(() => create(bad));
});

test('options reject unknown fields, foreign prototypes, accessors and malformed page selections without invoking getters', () => {
    let calls = 0; const accessor = {}; Object.defineProperty(accessor, 'loop', { enumerable: true, get() { calls++; return true; } });
    const pages = ['p0']; Object.defineProperty(pages, '0', { enumerable: true, get() { calls++; return 'p0'; } });
    const hidden = {}; Object.defineProperty(hidden, 'loop', { value: true });
    const foreignArray = ['p0']; Object.setPrototypeOf(foreignArray, Object.create(Array.prototype));
    const invalid = [null, [], true, Object.create({}), accessor, hidden, { unknown: 1 }, { [Symbol('x')]: true }, { loop: 1 }, { loop: undefined },
        { startPageId: 'missing' }, { startPageId: 'p1', pageIds: ['p0'] }, { pageIds: [] }, { pageIds: ['p0', 'p0'] }, { pageIds: ['missing'] },
        { pageIds: Array(2) }, { pageIds: ['p0', 1] }, { pageIds: ['p0', ...Array(100).fill('p1')] }, { pageIds: pages }, { pageIds: foreignArray }];
    for (const options of invalid) assert.throws(() => create(document(), options));
    assert.equal(calls, 0); assert.equal(create(document(), Object.assign(Object.create(null), { loop: true })).snapshot().nextPageId, 'p1');
});

test('selection and snapshots never retain caller-owned option arrays', () => {
    const options = { pageIds: ['p2', 'p0'], loop: true }; const s = create(document(), options);
    options.pageIds.reverse(); options.pageIds.push('p1'); options.loop = false;
    const snapshot = s.snapshot(); assert.deepEqual(snapshot.pageIds, ['p2', 'p0']);
    s.next(); assert.equal(snapshot.pageId, 'p2'); assert.equal(s.snapshot().pageId, 'p0');
    assert.equal(s.next().pageId, 'p2');
});

test('fractional duration clocks never reconstruct an earlier page at zero or small positive delta', () => {
    const durations = [0.1, 0.1234, 0.2345, 0.3456, 0.4567, 0.5678, 0.6789, 0.7891];
    let triples = 0;
    for (const a of durations) for (const b of durations) for (const c of durations) {
        const input = document(); [a, b, c].forEach((duration, i) => { input.pages[i].duration = duration; });
        for (let index = 0; index < 3; index++) for (const loop of [false, true]) {
            const s = create(input, { startPageId: `p${index}`, loop }); s.play();
            const original = s.snapshot();
            assert.deepEqual(s.advance(0), original, `zero delta at ${a}/${b}/${c}, page ${index}, loop ${loop}`);
            const offset = 0.125;
            assert.equal(s.advance(offset).pageId, `p${index}`);
            assert.equal(s.snapshot().elapsedMs, offset);
            const atOffset = s.snapshot(); assert.deepEqual(s.advance(0), atOffset);
            s.advance(0.25);
            assert.equal(s.snapshot().pageId, `p${index}`); assert.equal(s.snapshot().elapsedMs, 0.375);
            s.advance(input.pages[index].duration * 1000 - 0.375);
            if (index < 2 || loop) {
                assert.equal(s.snapshot().pageId, `p${(index + 1) % 3}`); assert.equal(s.snapshot().elapsedMs, 0);
            } else { assert.equal(s.snapshot().ended, true); assert.equal(s.snapshot().elapsedMs, c * 1000); }
        }
        triples++;
    }
    assert.equal(triples, 512);
});

test('zero delta preserves the selected fractional-duration page in v1 and v2', () => {
    for (const schemaVersion of [1, 2]) for (const loop of [false, true]) {
        const input = document(); [0.1, 0.1234, 0.1].forEach((duration, i) => { input.pages[i].duration = duration; });
        if (schemaVersion === 2) { input.schema_version = 2; input.timeline = { fps: 30, audio_tracks: [] }; }
        const s = create(input, { startPageId: 'p2', loop }); s.play(); const before = s.snapshot();
        assert.deepEqual(s.advance(0), before);
    }
});

test('exact fractional page duration advances into the next page in v1 and v2', () => {
    for (const schemaVersion of [1, 2]) for (const loop of [false, true]) {
        const input = document(); [0.1, 0.1234, 0.1].forEach((duration, i) => { input.pages[i].duration = duration; });
        if (schemaVersion === 2) { input.schema_version = 2; input.timeline = { fps: 30, audio_tracks: [] }; }
        const s = create(input, { startPageId: 'p1', loop }); s.play();
        assert.equal(s.advance(s.snapshot().durationMs).pageId, 'p2'); assert.equal(s.snapshot().elapsedMs, 0);
    }
});

test('fractional loops preserve local page and offset across whole cycles and enormous deltas', () => {
    const input = document(); input.pages[1].duration = 0.1234;
    const cycle = input.pages.reduce((total, page) => total + page.duration * 1000, 0);
    for (const startPageId of ['p1', 'p2']) {
        const s = create(input, { startPageId, loop: true }); s.play(); s.advance(0.125);
        const before = s.snapshot(); assert.deepEqual(s.advance(cycle), before);
        const delta = Number.MAX_SAFE_INTEGER;
        let remainder = delta % cycle; let index = before.index; let offset = before.elapsedMs;
        while (remainder >= input.pages[index].duration * 1000 - offset) {
            remainder -= input.pages[index].duration * 1000 - offset;
            index = (index + 1) % 3; offset = 0;
        }
        const after = s.advance(delta);
        assert.equal(after.pageId, `p${index}`); assert.equal(after.elapsedMs, offset + remainder);
        assert.equal(after.ended, false); assert.equal(after.playing, true);
    }
    const temporal = structuredClone(input); temporal.schema_version = 2; temporal.timeline = { fps: 30, audio_tracks: [] };
    const s = create(temporal, { startPageId: 'p2' }); s.play();
    assert.equal(s.advance(0).pageId, 'p2'); assert.equal(s.advance(0.375).elapsedMs, 0.375);
});
