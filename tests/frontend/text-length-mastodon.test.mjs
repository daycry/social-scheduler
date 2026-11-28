import assert from 'node:assert/strict';
import test from 'node:test';
import { countText } from '../../resources/js/Composables/textLength.js';

// T-87: el espejo de `App\Support\TextLength` en modo `mastodon` (grafemas, cada URL como la reserva de la instancia, una mencion remota como @usuario).
// Los mismos casos que `tests/Unit/TextLengthTest.php`.

test('mastodon: 450 caracteres con un UTM largo cuentan 450 + 1 + 23', () => {
    const text = `${'a'.repeat(450)} https://ejemplo.org/p?${'u'.repeat(100)}`;
    assert.equal(countText(text, 'mastodon'), 474);
    assert.equal(countText(text, 'chars'), 573);
});

test('mastodon: un enlace corto cuenta lo que reserva la instancia, no su longitud', () => {
    const text = `${'a'.repeat(480)} https://bit.ly/3xYz`;
    assert.equal(countText(text, 'mastodon'), 504);
    assert.equal(countText('https://x.io/a', 'mastodon', 30), 30);
    assert.equal(countText('hola https://x.io/a y http://x.io', 'mastodon'), 5 + 23 + 3 + 23);
});

test('mastodon: una mencion remota cuenta como @usuario y un correo no es una mencion', () => {
    assert.equal(countText('hola @ana@mastodon.social!', 'mastodon'), 10);
    assert.equal(countText('@ana@mastodon.social @luis@otra.example', 'mastodon'), 4 + 1 + 5);
    assert.equal(countText('correo ana@example.org', 'mastodon'), 22);
    assert.equal(countText('https://x.io/@ana@mastodon.social', 'mastodon'), 23);
});

test('mastodon: un emoji compuesto cuenta 1 (grafema) y el texto se recorta', () => {
    assert.equal(countText('\u{1F468}‍\u{1F469}‍\u{1F467}‍\u{1F466}', 'mastodon'), 1);
    assert.equal(countText('  hola  ', 'mastodon'), 4);
    assert.equal(countText('', 'mastodon'), 0);
});
