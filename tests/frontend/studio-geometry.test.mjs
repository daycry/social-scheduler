import test from 'node:test';
import assert from 'node:assert/strict';
import { Rect, Group, ActiveSelection } from 'fabric';
import { studioObjectToElement, elementToFabric } from '../../resources/js/Studio/canvas.js';

const assertMatrix = (first, second) => first.forEach((v, i) => assert.ok(Math.abs(v - second[i]) < 0.001, `matrix[${i}] ${v} != ${second[i]}`));

test('actual Fabric transform survives own document roundtrip', async () => {
    const object = new Rect({ left: 124, top: 89, width: 310, height: 180, angle: 27, scaleX: 1.3, scaleY: 0.8, skewX: 13, originX: 'left', originY: 'top', fill: '#abcdef' });
    object.studioId = 'real_rect';
    object.studioType = 'rect';
    const element = studioObjectToElement(object);
    const restored = await elementToFabric(element);
    assertMatrix(object.calcTransformMatrix(), restored.calcTransformMatrix());
    assert.equal(element.fill, '#abcdef');
});

test('multiple selection serializes absolute transforms without altering the selection', async () => {
    const one = new Rect({ left: 50, top: 90, width: 100, height: 80, angle: 12, originX: 'left', originY: 'top', fill: '#ff0000' });
    one.studioId = 'one'; one.studioType = 'rect';
    const two = new Rect({ left: 400, top: 130, width: 90, height: 150, angle: -20, originX: 'left', originY: 'top', fill: '#0000ff' });
    two.studioId = 'two'; two.studioType = 'rect';
    const selection = new ActiveSelection([one, two], { angle: 23, scaleX: 1.5, scaleY: 0.8 });
    const matrix = one.calcTransformMatrix();
    const element = studioObjectToElement(one, { absolute: true });
    const restored = await elementToFabric(element);
    assertMatrix(matrix, restored.calcTransformMatrix());
    assert.equal(one.group, selection);
});

test('nested group preserves local children geometry on save and reopen', async () => {
    const one = new Rect({ left: 30, top: 40, width: 110, height: 80, originX: 'left', originY: 'top', fill: '#ff0000' });
    one.studioId = 'nested_one'; one.studioType = 'rect';
    const two = new Rect({ left: 230, top: 100, width: 70, height: 60, originX: 'left', originY: 'top', fill: '#0000ff' });
    two.studioId = 'nested_two'; two.studioType = 'rect';
    const group = new Group([one, two], { angle: 14, scaleX: 1.2, scaleY: 0.9, originX: 'left', originY: 'top' });
    group.studioId = 'nested_group'; group.studioType = 'group';
    const before = group.getObjects().map(o => o.calcTransformMatrix());
    const restored = await elementToFabric(studioObjectToElement(group));
    restored.getObjects().forEach((object, i) => assertMatrix(before[i], object.calcTransformMatrix()));
});

test('a gradient angle stays stable on nonsquare shapes through repeated saves', async () => {
    const fill = { type: 'linear', angle: 45, stops: [{ offset: 0, color: '#ffffff' }, { offset: 1, color: '#7c3aed' }] };
    let element = { id: 'wide_gradient', type: 'rect', x: 10, y: 20, width: 900, height: 100, fill, strokeWidth: 0 };
    for (let i = 0; i < 3; i++) element = studioObjectToElement(await elementToFabric(element));
    assert.ok(Math.abs(element.fill.angle - 45) < 0.001);
});
