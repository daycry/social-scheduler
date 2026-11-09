import test from 'node:test';
import assert from 'node:assert/strict';

const target = new URL('../../resources/js/Studio/presentation-tools.js', import.meta.url);
const subject = await import(target).catch(error => {
    if (error.code === 'ERR_MODULE_NOT_FOUND' && error.url === target.href) return {};
    throw error;
});
const create = options => {
    assert.equal(typeof subject.createPresentationTools, 'function', 'visual tools controller capability is absent');
    return subject.createPresentationTools(options);
};
const p = (x = 0.2, y = 0.4) => ({ x, y });
const pen = options => { const s = create(options); s.setTool('pen'); return s; };

test('contain geometry maps actual image bounds and rejects black bands and nonfinite inputs', () => {
    assert.equal(typeof subject.presentationPoint, 'function', 'contain geometry capability is absent');
    const map = subject.presentationPoint;
    assert.deepEqual(map({ left: 10, top: 20, width: 400, height: 400 }, 800, 400, 210, 220), p(0.5, 0.5));
    assert.equal(map({ left: 10, top: 20, width: 400, height: 400 }, 800, 400, 210, 100), null);
    assert.deepEqual(map({ left: 0, top: 0, width: 400, height: 200 }, 100, 200, 150, 0), p(0, 0));
    assert.deepEqual(map({ left: 0, top: 0, width: 400, height: 200 }, 100, 200, 250, 200), p(1, 1));
    assert.equal(map({ left: 0, top: 0, width: 400, height: 200 }, 100, 200, 149, 100), null);
    for (const bad of [NaN, Infinity, -Infinity]) {
        assert.equal(map({ left: 0, top: 0, width: 400, height: 200 }, 100, 200, bad, 100), null);
        assert.equal(map({ left: 0, top: 0, width: bad, height: 200 }, 100, 200, 200, 100), null);
    }
    assert.equal(map(null, 10, 10, 0, 0), null);
    assert.equal(map({ left: 0, top: 0, width: 1, height: 1 }, 0, 1, 0, 0), null);
});

test('controller publishes immutable detached snapshots only for mutations and validates settings', () => {
    const changes = []; const s = create({ now: () => 0, onChange: value => changes.push(value) });
    assert.equal(changes.length, 0);
    assert.deepEqual(s.snapshot(), { pageId: null, cursor: 'normal', tool: 'pointer', pointer: null,
        penColor: '#ff0000', penWidth: 0.005, trailSeconds: 3, strokes: [], needsFrame: false });
    assert.equal(changes.length, 0);
    s.setPage('page-a'); s.setCursor('laser'); s.setTool('pen'); s.setPenColor('#00abFF'); s.setPenWidth(0.04); s.setTrailSeconds(10);
    const input = p(); s.pointerDown(input, 1); input.x = 0.9;
    const before = s.snapshot();
    assert.equal(before.strokes[0].points[0].x, 0.2);
    assert.ok(Object.isFrozen(before)); assert.ok(Object.isFrozen(before.strokes[0].points[0]));
    assert.throws(() => { before.pointer.x = 1; }, TypeError);
    assert.throws(() => before.strokes.push({}), TypeError);
    s.pointerMove(p(0.7, 0.8), 1); assert.equal(before.strokes[0].points.length, 1);
    for (const [method, values] of Object.entries({ setCursor: ['bad', null], setTool: ['bad'], setPenColor: ['red', '#abcd', '#11223344'],
        setPenWidth: [NaN, Infinity, 0, 0.041], setTrailSeconds: [0, 11, 1.5, NaN] })) {
        for (const value of values) assert.throws(() => s[method](value), /invalid/i);
    }
    assert.equal(s.snapshot().penColor, '#00abFF');
    assert.ok(changes.length > 0); assert.ok(changes.every(Object.isFrozen));
});

test('pointer ownership prevents hijacking and leave cancel page changes finish or clear strokes', () => {
    const s = pen({ now: () => 0 }); s.setPage('a'); s.setCursor('laser');
    s.pointerDown(p(), 1); s.pointerDown(p(0.9, 0.9), 2); s.pointerMove(p(0.8, 0.8), 2); s.pointerUp(2);
    assert.deepEqual(s.snapshot().pointer, p()); assert.equal(s.snapshot().strokes.length, 1);
    assert.equal(s.snapshot().strokes[0].points.length, 1);
    s.pointerMove(p(0.3, 0.5), 1); s.pointerMove(null, 1);
    assert.equal(s.snapshot().pointer, null); assert.equal(s.snapshot().needsFrame, false);
    s.pointerUp(1); assert.equal(s.snapshot().needsFrame, true);
    s.pointerDown(p(), 3); s.pointerLeave(); assert.equal(s.snapshot().pointer, null); assert.equal(s.snapshot().needsFrame, true);
    s.pointerDown(p(), 4); s.setPage('b'); assert.equal(s.snapshot().strokes.length, 0); assert.equal(s.snapshot().pointer, null);
    s.pointerMove(p(0.7, 0.1), 4); assert.equal(s.snapshot().strokes.length, 0);
    s.pointerDown(p(), 5); s.clear(); s.pointerMove(p(), 5); assert.equal(s.snapshot().strokes.length, 0);
    s.setTool('pointer'); s.pointerMove(p(), 9); assert.deepEqual(s.snapshot().pointer, p()); assert.equal(s.snapshot().needsFrame, false);
    for (const invalid of [p(NaN, 0), p(0, Infinity), p(-0.01, 0), p(0, 1.01)]) {
        s.pointerMove(invalid, 9); assert.equal(s.snapshot().pointer, null);
    }
});

test('fake clock fades completed strokes from release and expires exactly without active or laser loops', () => {
    let time = 0; let changes = 0; const s = pen({ now: () => time, onChange: () => changes++ });
    s.pointerDown(p(), 1); time = 10000; s.tick(); assert.equal(s.snapshot().strokes[0].alpha, 1); assert.equal(s.snapshot().needsFrame, false);
    s.pointerUp(1); time = 11500; assert.equal(s.tick().strokes[0].alpha, 0.5);
    assert.equal(s.snapshot().needsFrame, true); time = 13000; assert.equal(s.tick().strokes.length, 0); assert.equal(s.snapshot().needsFrame, false);
    s.setTrailSeconds(1); s.pointerDown(p(), 2); s.pointerUp(2); time = 14000; assert.equal(s.tick().strokes.length, 0);
    s.setCursor('laser'); s.pointerMove(p(), 3); assert.equal(s.snapshot().needsFrame, false);
    assert.ok(changes > 0);
});

test('stroke and total budgets evict oldest completed traces while sampling preserves both current extremes', () => {
    const s = pen({ now: () => 0 });
    for (let i = 0; i < 70; i++) { s.pointerDown(p(i / 100, 0), i); s.pointerUp(i); }
    assert.equal(s.snapshot().strokes.length, 64); assert.equal(s.snapshot().strokes[0].points[0].x, 0.06);
    s.clear();
    for (let stroke = 0; stroke < 6; stroke++) {
        s.pointerDown(p(0, stroke / 10), stroke);
        for (let i = 1; i <= 5000; i++) s.pointerMove(p(i / 5000, stroke / 10), stroke);
        const snapshot = s.snapshot(); const active = snapshot.strokes.at(-1);
        assert.ok(active.points.length <= 1024); assert.deepEqual(active.points[0], p(0, stroke / 10));
        assert.deepEqual(active.points.at(-1), p(1, stroke / 10));
        assert.ok(snapshot.strokes.reduce((n, item) => n + item.points.length, 0) <= 4096);
        s.pointerUp(stroke);
    }
    assert.ok(s.snapshot().strokes.length < 6);
});

test('dispose clears tools and suppresses all later publications including pending tick calls', () => {
    let calls = 0; const s = pen({ now: () => 0, onChange: () => calls++ }); s.pointerDown(p(), 1);
    s.dispose(); const after = calls; const disposed = s.snapshot();
    assert.equal(disposed.pointer, null); assert.equal(disposed.strokes.length, 0); assert.equal(disposed.needsFrame, false);
    s.dispose(); s.setPage('new'); s.setTool('pen'); s.setCursor('laser'); s.setPenColor('#ffffff'); s.setPenWidth(0.02);
    s.setTrailSeconds(2); s.pointerDown(p(), 2); s.pointerMove(p(), 2); s.pointerUp(2); s.pointerLeave(); s.clear(); s.tick();
    assert.equal(calls, after); assert.deepEqual(s.snapshot(), disposed);
});

test('unchanged settings repeated pointer samples and unrelated releases do not publish changes', () => {
    let count = 0; const s = create({ now: () => 0, onChange: () => count++ });
    s.setCursor('normal'); s.setTool('pointer'); s.setPage(null); s.setPenColor('#ff0000');
    s.setPenWidth(0.005); s.setTrailSeconds(3); s.clear(); s.pointerLeave(); s.pointerUp(9); s.tick();
    assert.equal(count, 0);
    s.pointerMove(p(), 1); assert.equal(count, 1); s.pointerMove(p(), 1); assert.equal(count, 1);
    s.setTool('pen'); s.pointerDown(p(), 1); const before = count;
    s.pointerMove(p(), 1); s.pointerMove(p(0.9, 0.9), 2); s.pointerUp(2); assert.equal(count, before);
    s.pointerMove(null, 1); const outside = count; s.pointerMove(null, 1); assert.equal(count, outside);
});

const canvas = () => {
    const calls = []; const ctx = new Proxy({}, { get: (_, key) => (...args) => calls.push([key, ...args]),
        set: (_, key, value) => { calls.push(['set', key, value]); return true; } });
    return { ctx, calls };
};
test('painter projects normalized strokes laser and allowed timer while restoring context on failure', () => {
    assert.equal(typeof subject.paintPresentationTools, 'function', 'tools canvas painter capability is absent');
    const s = pen({ now: () => 0 }); s.setCursor('laser'); s.pointerDown(p(0.1, 0.2), 1); s.pointerMove(p(0.8, 0.9), 1);
    const { ctx, calls } = canvas(); subject.paintPresentationTools(ctx, s.snapshot(), { width: 1000, height: 500,
        timer: { visible: true, status: 'running', label: '04:59', notes: 'PRIVATE NOTES' } });
    assert.equal(calls[0][0], 'save'); assert.equal(calls.at(-1)[0], 'restore');
    assert.ok(calls.some(c => c[0] === 'moveTo' && c[1] === 100 && c[2] === 100));
    assert.ok(calls.some(c => c[0] === 'lineTo' && c[1] === 800 && c[2] === 450));
    assert.ok(calls.some(c => c[0] === 'set' && c[1] === 'lineWidth' && c[2] === 2.5));
    assert.ok(calls.some(c => c[0] === 'arc' && c[1] === 800 && c[2] === 450));
    assert.ok(calls.some(c => c[0] === 'fillText' && c[1] === '04:59')); assert.ok(!JSON.stringify(calls).includes('PRIVATE NOTES'));
    for (const timer of [{ visible: false, status: 'running', label: 'x' }, { visible: true, status: 'idle', label: 'x' },
        { visible: true, status: 'disposed', label: 'x' }]) {
        const c = canvas(); subject.paintPresentationTools(c.ctx, s.snapshot(), { width: 10, height: 10, timer });
        assert.ok(!c.calls.some(call => call[0] === 'fillText'));
    }
    // A concrete context is used for the throwing path, since the call recorder deliberately synthesizes methods.
    const operations = []; const bad = { save: () => operations.push('save'), restore: () => operations.push('restore'),
        beginPath() {}, moveTo() {}, lineTo() { throw new Error('paint failed'); } };
    assert.throws(() => subject.paintPresentationTools(bad, s.snapshot(), { width: 10, height: 10 }), /paint failed/);
    assert.deepEqual(operations, ['save', 'restore']);
});

test('single points paint as visible dots and cursor CSS respects laser hidden and pen', () => {
    assert.equal(typeof subject.presentationCursorStyle, 'function', 'tools cursor style capability is absent');
    const s = pen({ now: () => 0 }); s.pointerDown(p(), 1); const c = canvas();
    subject.paintPresentationTools(c.ctx, s.snapshot(), { width: 100, height: 100 }); assert.ok(c.calls.some(call => call[0] === 'arc'));
    assert.equal(subject.presentationCursorStyle(s.snapshot()), 'crosshair');
    s.setCursor('hidden'); assert.equal(subject.presentationCursorStyle(s.snapshot()), 'none');
    s.setTool('pointer'); s.setCursor('laser'); assert.equal(subject.presentationCursorStyle(s.snapshot()), 'none');
    s.setCursor('normal'); assert.equal(subject.presentationCursorStyle(s.snapshot()), 'default');
});
