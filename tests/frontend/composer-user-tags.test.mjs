import assert from 'node:assert/strict';
import test from 'node:test';
import { MAX_USER_TAGS, clamp01, normalizeUsername, pointToCoordinates, removeTag, upsertTag } from '../../resources/js/Composables/userTags.js';

test('el usuario se normaliza sin arroba ni mayúsculas y se rechaza lo que no es un usuario', () => {
    assert.equal(normalizeUsername(' @Ana.Ruiz '), 'ana.ruiz');
    assert.match(upsertTag([], 'https://instagram.com/ana', 0.5, 0.5).error, /usuario de Instagram válido/);
    assert.match(upsertTag([], '', 0.5, 0.5).error, /usuario de Instagram válido/);
});

test('las coordenadas se mantienen entre 0 y 1 y un clic se convierte a posición relativa', () => {
    assert.equal(clamp01(-3), 0);
    assert.equal(clamp01(7), 1);
    assert.equal(clamp01(0.12345), 0.123);
    assert.equal(clamp01('x'), 0);
    assert.deepEqual(pointToCoordinates({ left: 100, top: 50, width: 200, height: 100 }, 150, 125), { x: 0.25, y: 0.75 });
    assert.deepEqual(pointToCoordinates({ left: 0, top: 0, width: 0, height: 0 }, 5, 5), { x: 0, y: 0 });
});

test('repetir un usuario mueve su etiqueta y hay un máximo de etiquetas', () => {
    const first = upsertTag([], '@ana', 0.1, 0.2);
    assert.deepEqual(first.tags, [{ username: 'ana', x: 0.1, y: 0.2 }]);
    assert.deepEqual(upsertTag(first.tags, 'ANA', 0.9, 0.8).tags, [{ username: 'ana', x: 0.9, y: 0.8 }]);

    let tags = [];
    for (let i = 0; i < MAX_USER_TAGS; i++) tags = upsertTag(tags, `user${i}`, 0.5, 0.5).tags;
    const over = upsertTag(tags, 'otro', 0.5, 0.5);
    assert.equal(over.tags.length, MAX_USER_TAGS);
    assert.match(over.error, /hasta 20/);
    assert.deepEqual(removeTag(first.tags, 'ana'), []);
});
