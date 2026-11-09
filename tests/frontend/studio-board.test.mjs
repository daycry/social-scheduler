import test from 'node:test';
import assert from 'node:assert/strict';
import { anchorPoints, connectorCommands, normalizeConnector, rerouteConnector, routePoints } from '../../resources/js/Studio/board.js';
import { duplicatePage, newElement, validateDocument, StudioDocumentError } from '../../resources/js/Studio/document.js';

const page = elements => ({ schema_version: 1, width: 3200, height: 1800, pages: [{ id: 'p1', name: 'Pizarra', background: '#f8fafc', duration: 5, elements }] });

test('anchors join the facing sides of two shapes', () => {
    const a = { left: 0, top: 0, width: 100, height: 50 };
    assert.deepEqual(anchorPoints(a, { left: 300, top: 100, width: 100, height: 50 }), { horizontal: true, start: { x: 100, y: 25 }, end: { x: 300, y: 125 } });
    assert.deepEqual(anchorPoints(a, { left: 20, top: 400, width: 100, height: 50 }), { horizontal: false, start: { x: 50, y: 50 }, end: { x: 70, y: 400 } });
});

test('elbow routes have axis-aligned segments and the arrow stays inside the frame origin', () => {
    const points = routePoints({ x: 100, y: 25 }, { x: 300, y: 125 }, 'elbow', true);
    assert.deepEqual(points, [[100, 25], [200, 25], [200, 125], [300, 125]]);
    const commands = connectorCommands(points, 'both', 4);
    assert.equal(commands.length, 4 + 3 + 3);
    const frame = normalizeConnector({ x: 0, y: 0, route: 'elbow', arrow: 'both', strokeWidth: 4, points });
    assert.equal(Math.min(...frame.commands.map(c => c[1])), 0);
    assert.equal(Math.min(...frame.commands.map(c => c[2])), 0);
    assert.equal(frame.points.length, 4);
});

test('a table and a connector survive validation and reject bad shapes', () => {
    const table = newElement('table', { id: 'tabla_1' });
    const connector = newElement('connector', { id: 'conector_1', from: 'tabla_1' });
    const document = validateDocument(page([table, connector]));
    assert.deepEqual(document.pages[0].elements[0].table.rows[0], ['Concepto', 'Enero', 'Febrero']);
    assert.equal(document.pages[0].elements[1].from, 'tabla_1');
    assert.throws(() => validateDocument(page([{ ...table, table: { ...table.table, rows: [['a', 'b'], ['c']] } }])), StudioDocumentError);
    assert.throws(() => validateDocument(page([{ ...connector, route: 'curved' }])), StudioDocumentError);
    assert.throws(() => validateDocument(page([{ ...table, table: { ...table.table, css: 'x' } }])), StudioDocumentError);
});

test('duplicating a page re-attaches connectors to the copied shapes', () => {
    const copy = duplicatePage({ id: 'p1', name: 'x', background: '#ffffff', duration: 5, elements: [newElement('table', { id: 'tabla_1' }), newElement('connector', { id: 'c_1', from: 'tabla_1', to: 'fuera' })] });
    assert.equal(copy.elements[1].from, copy.elements[0].id);
    assert.notEqual(copy.elements[0].id, 'tabla_1');
    assert.equal(copy.elements[1].to, null);
});

test('rerouting redraws a connector with the identity transform, whatever it inherited', () => {
    const base = { x: 0, y: 0, route: 'straight', arrow: 'end', strokeWidth: 4, points: [[0, 0], [100, 0]], from: 'a', to: 'b', scaleX: 2, scaleY: 0.5, rotation: 30, flipX: true, flipY: true, skewX: 5, skewY: 5 };
    const moved = rerouteConnector(base, { left: 0, top: 0, width: 10, height: 10 }, { left: 300, top: 300, width: 10, height: 10 });
    assert.deepEqual([moved.scaleX, moved.scaleY, moved.rotation, moved.flipX, moved.flipY, moved.skewX, moved.skewY], [1, 1, 0, false, false, 0, 0]);
    assert.equal(moved.from, 'a');
    // Even when the geometry does not move, a stale transform forces a clean redraw.
    const still = rerouteConnector({ ...base, points: [[10, 5], [300, 305]], scaleX: 1.5 }, { left: 0, top: 0, width: 10, height: 10 }, { left: 300, top: 300, width: 10, height: 10 });
    assert.equal(still?.scaleX, 1);
});

test('deleting a shape detaches its end of the connector, the other end stays glued', () => {
    const base = { x: 0, y: 0, route: 'straight', arrow: 'end', strokeWidth: 4, points: [[10, 5], [300, 305]], from: 'a', to: 'b' };
    const gone = rerouteConnector(base, { left: 0, top: 0, width: 10, height: 10 }, undefined);
    assert.equal(gone.from, 'a');
    assert.equal(gone.to, null);
    const both = rerouteConnector(base, undefined, undefined);
    assert.deepEqual([both.from, both.to], [null, null]);
});
