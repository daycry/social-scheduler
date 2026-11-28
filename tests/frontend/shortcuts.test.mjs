import assert from 'node:assert/strict';
import test from 'node:test';
import { hasOpenModal, searchShortcut, shortcutAction, shortcutHelp } from '../../resources/js/lib/shortcuts.js';

const keyboard = (key, overrides = {}, path = []) => ({
    key, ctrlKey: false, metaKey: false, altKey: false, shiftKey: false,
    defaultPrevented: false, repeat: false, isComposing: false, composedPath: () => path, ...overrides,
});

test('la búsqueda global deja el teclado al control que tiene el foco', () => {
    for (const type of ['input', 'textarea', 'select', '[contenteditable]', '[role="textbox"]', 'canvas']) {
        const focused = { closest: (selector) => selector.includes(type) ? focused : null };
        assert.equal(shortcutAction(keyboard('k', { ctrlKey: true }, [focused]), { mac: false }), undefined, type);
        assert.equal(shortcutAction(keyboard('n', {}, [focused])), undefined, type);
    }
});

test('un diálogo visible bloquea los atajos y se ignoran modificadores ajenos, composición y repetición', () => {
    const root = { querySelectorAll: () => [{ getClientRects: () => [1] }] };
    assert.equal(shortcutAction(keyboard('?'), { modalOpen: hasOpenModal(root) }), undefined);
    for (const overrides of [
        { altKey: true }, { metaKey: true }, { ctrlKey: true }, { shiftKey: true }, { repeat: true },
        { isComposing: true }, { keyCode: 229 }, { defaultPrevented: true },
    ]) assert.equal(shortcutAction(keyboard('n', overrides)), undefined);
    assert.equal(shortcutAction(keyboard('?', { shiftKey: true })), 'help');
});

test('Ctrl+K y Cmd+K se reservan para la paleta abierta aunque su buscador tenga el foco', () => {
    const searchbox = { closest: (selector) => selector.includes('input') ? searchbox : null };
    const options = { modalOpen: true, paletteOpen: true };

    assert.equal(shortcutAction(keyboard('k', { ctrlKey: true }, [searchbox]), { ...options, mac: false }), 'search');
    assert.equal(shortcutAction(keyboard('k', { metaKey: true }, [searchbox]), { ...options, mac: true }), 'search');
    assert.equal(shortcutAction(keyboard('/', {}, [searchbox]), options), undefined);
    assert.equal(shortcutAction(keyboard('n', {}, [searchbox]), options), undefined);
    assert.equal(shortcutAction(keyboard('k', { ctrlKey: true }, [searchbox]), { modalOpen: true, mac: false }), undefined);
});

test('el ajuste desactiva las teclas simples y conserva Ctrl+K o Cmd+K según la plataforma', () => {
    assert.equal(shortcutAction(keyboard('n'), { singleKeys: false }), undefined);
    assert.equal(shortcutAction(keyboard('k', { ctrlKey: true }), { mac: false, singleKeys: false }), 'search');
    assert.equal(shortcutAction(keyboard('k', { ctrlKey: true }), { mac: true }), undefined);
    assert.equal(shortcutAction(keyboard('K', { metaKey: true }), { mac: true }), 'search');
    assert.equal(shortcutAction(keyboard('k', { ctrlKey: true, metaKey: true }), { mac: false }), undefined);
    assert.deepEqual(searchShortcut(true), { keys: '⌘K', aria: 'Meta+K' });
});

test('la ayuda enumera las teclas activas con los mismos destinos disponibles que el manejador', () => {
    const available = (id) => id !== 'create';
    const options = { mac: false, available };
    const help = shortcutHelp('global', options);
    assert.ok(help.some(({ id }) => id === 'planning'));
    assert.ok(help.every(({ id }) => id !== 'create'));
    assert.equal(shortcutAction(keyboard('n'), options), undefined);
    for (const { id, keys } of help) {
        assert.equal(shortcutAction(keyboard(keys.at(-1), { ctrlKey: keys.startsWith('Ctrl+') }), options), id);
    }
});

test('el Estudio conserva los atajos de edición y del lienzo en su ámbito', () => {
    const bindings = [
        ['studio-editor', 'z', { ctrlKey: true }, 'undo'],
        ['studio-editor', 'z', { metaKey: true, shiftKey: true }, 'redo'],
        ['studio-editor', 's', { ctrlKey: true }, 'save'],
        ['studio-canvas', 'a', { ctrlKey: true }, 'select-all'],
        ['studio-canvas', 'ArrowRight', { shiftKey: true, repeat: true }, 'nudge'],
        ['studio-canvas', 'Delete', {}, 'delete'],
    ];
    for (const [scope, key, overrides, expected] of bindings) {
        assert.equal(shortcutAction(keyboard(key, overrides), { scope, singleKeys: false }), expected);
        assert.ok(shortcutHelp(scope).some(({ id }) => id === expected));
    }
});
