/** @typedef {'global' | 'studio-editor' | 'studio-canvas'} ShortcutScope */
/**
 * @typedef {{ id: string, key: string, label: string, modifier?: 'platform' | 'either',
 * shift?: boolean | 'optional', repeat?: boolean }} Shortcut
 */

/** @type {Shortcut} */
const searchBinding = { id: 'search', key: 'k', label: 'Buscar en la aplicación', modifier: 'platform' };

/** @type {Record<ShortcutScope, Shortcut[]>} */
const shortcuts = {
    global: [
        { id: 'help', key: '?', label: 'Ayuda y atajos', shift: 'optional' },
        searchBinding,
        { id: 'search', key: '/', label: searchBinding.label, shift: 'optional' },
        { id: 'analytics', key: 'a', label: 'Analítica' },
        { id: 'planning', key: 'p', label: 'Planificación' },
        { id: 'studio', key: 'e', label: 'Estudio' },
        { id: 'create', key: 'n', label: 'Crear publicación' },
    ],
    'studio-editor': [
        { id: 'undo', key: 'z', label: 'Deshacer', modifier: 'either', repeat: true },
        { id: 'redo', key: 'z', label: 'Rehacer', modifier: 'either', shift: true, repeat: true },
        { id: 'save', key: 's', label: 'Guardar diseño', modifier: 'either' },
    ],
    'studio-canvas': [
        { id: 'delete', key: 'Delete', label: 'Eliminar la selección', repeat: true },
        { id: 'delete', key: 'Backspace', label: 'Eliminar la selección', repeat: true },
        { id: 'deselect', key: 'Escape', label: 'Quitar la selección' },
        { id: 'select-all', key: 'a', label: 'Seleccionar todo', modifier: 'either' },
        { id: 'copy', key: 'c', label: 'Copiar', modifier: 'either' },
        { id: 'paste', key: 'v', label: 'Pegar', modifier: 'either' },
        { id: 'duplicate', key: 'd', label: 'Duplicar', modifier: 'either' },
        { id: 'nudge', key: 'ArrowLeft', label: 'Mover a la izquierda', shift: 'optional', repeat: true },
        { id: 'nudge', key: 'ArrowRight', label: 'Mover a la derecha', shift: 'optional', repeat: true },
        { id: 'nudge', key: 'ArrowUp', label: 'Mover hacia arriba', shift: 'optional', repeat: true },
        { id: 'nudge', key: 'ArrowDown', label: 'Mover hacia abajo', shift: 'optional', repeat: true },
    ],
};

const editable = 'input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"], [role="searchbox"], [role="combobox"]:not(button)';
const modals = 'dialog[open], [aria-modal="true"]:not(dialog):not([hidden])';
/** @type {Record<string, string>} */
const keyNames = { Delete: 'Supr', Backspace: 'Retroceso', Escape: 'Esc', ArrowUp: '↑', ArrowLeft: '←', ArrowRight: '→', ArrowDown: '↓' };

/** @param {Document} [root] */
export function hasOpenModal(root = document) {
    return [...root.querySelectorAll(modals)].some((node) => node.getClientRects().length > 0);
}

export function isApplePlatform() {
    return typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
}

/** @param {KeyboardEvent} event @param {Shortcut} binding @param {boolean} mac */
function matches(event, binding, mac) {
    if (event.key.toLowerCase() !== binding.key.toLowerCase() || (event.repeat && !binding.repeat)) return false;
    if (binding.shift !== 'optional' && event.shiftKey !== Boolean(binding.shift)) return false;
    if (binding.modifier === 'either') return event.ctrlKey !== event.metaKey;
    if (binding.modifier === 'platform') return mac ? event.metaKey && !event.ctrlKey : event.ctrlKey && !event.metaKey;
    return !event.ctrlKey && !event.metaKey;
}

/**
 * @param {KeyboardEvent} event
 * @param {{ scope?: ShortcutScope, mac?: boolean, singleKeys?: boolean, modalOpen?: boolean, paletteOpen?: boolean, available?: (id: string) => boolean }} [options]
 */
export function shortcutAction(event, {
    scope = 'global', mac = isApplePlatform(), singleKeys = true, modalOpen = false, paletteOpen = false, available = () => true,
} = {}) {
    if (event.defaultPrevented || event.isComposing || event.keyCode === 229 || event.altKey) return undefined;
    if (scope === 'global' && paletteOpen) {
        return matches(event, searchBinding, mac) && available('search') ? 'search' : undefined;
    }
    if (modalOpen) return undefined;
    const blockedTargets = scope === 'global' ? `${editable}, canvas` : editable;
    if (event.composedPath().some((node) => 'closest' in node && /** @type {Element} */ (node).closest(blockedTargets))) return undefined;
    return shortcuts[scope].find((binding) =>
        (scope !== 'global' || singleKeys || binding.modifier) && matches(event, binding, mac) && available(binding.id),
    )?.id;
}

/** @param {Shortcut} binding @param {boolean} mac */
function displayKeys(binding, mac) {
    const modifier = binding.modifier ? (mac ? '⌘' : 'Ctrl+') : '';
    return modifier + (binding.shift === true ? 'Mayús+' : '') + (keyNames[binding.key] ?? binding.key.toUpperCase());
}

/** @param {ShortcutScope} scope @param {{mac?: boolean, available?: (id: string) => boolean}} [options] */
export function shortcutHelp(scope, { mac = isApplePlatform(), available = () => true } = {}) {
    return shortcuts[scope].filter((binding) => available(binding.id)).map((binding) => ({
        id: binding.id, keys: displayKeys(binding, mac), label: binding.label, single: scope === 'global' && !binding.modifier,
    }));
}

/** @param {boolean} mac */
export function searchShortcut(mac) {
    return { keys: displayKeys(searchBinding, mac), aria: `${mac ? 'Meta' : 'Control'}+${searchBinding.key.toUpperCase()}` };
}
