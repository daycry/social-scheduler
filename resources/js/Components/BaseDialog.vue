<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';

/** @typedef {HTMLElement | SVGElement} FocusTarget */
/** @typedef {FocusTarget | {$el: Node | null}} ReturnFocusTarget */
/** @typedef {{first: HTMLInputElement, last: HTMLInputElement, checked?: HTMLInputElement}} RadioGroup */

defineOptions({ inheritAttrs: false });
const props = defineProps({
    open: Boolean,
    closeable: { type: Boolean, default: true },
    closeOnBackdrop: Boolean,
    returnFocusTo: { type: /** @type {import('vue').PropType<ReturnFocusTarget | null>} */ (Object), default: null },
});
const emit = defineEmits(['close', 'opened']);
const dialog = ref(/** @type {HTMLDialogElement | null} */ (null));
/** @type {HTMLDialogElement | null} */
let shownDialog = null;
/** @type {FocusTarget | null} */
let opener = null;
/** @type {FocusTarget | null} */
let lastFocus = null;
let pointerStartedOutside = false;

/**
 * @param {EventTarget | null} target
 * @returns {target is FocusTarget}
 */
function canFocus(target) {
    return target !== null && 'focus' in target && typeof target.focus === 'function';
}

function restoreFocus() {
    const destination = props.returnFocusTo;
    const explicit = destination && '$el' in destination ? destination.$el : destination;
    const target = explicit?.isConnected && canFocus(explicit) ? explicit : opener;
    opener = null;
    lastFocus = null;
    if (target?.isConnected) target.focus?.({ preventScroll: true });
}

/** @param {HTMLDialogElement | null} element */
function closeDialog(element) {
    if (!element || shownDialog !== element) return;
    shownDialog = null;
    if (element.open) element.close();
    restoreFocus();
}

/** @param {Event} event */
function handleNativeClose(event) {
    const element = shownDialog;
    if (!element || event.currentTarget !== element || !element.isConnected || element.open || !props.open) return;
    // El diálogo debe seguir debajo de una posible confirmación de descarte.
    const previousFocus = lastFocus;
    element.showModal();
    if (previousFocus && element.contains(previousFocus)) previousFocus.focus({ preventScroll: true });
    requestClose();
}

/** @param {Event} event */
function handleCancel(event) {
    if (!event.cancelable) return;
    event.preventDefault();
    requestClose();
}

/** @param {FocusEvent} event */
function rememberFocus(event) {
    if (canFocus(event.target)) lastFocus = event.target;
}

watch([() => props.open, dialog], ([open, element]) => {
    if (!element) return;
    if (!open) {
        closeDialog(element);
        return;
    }
    if (element.open) return;
    const active = element.ownerDocument.activeElement;
    opener = canFocus(active) ? active : null;
    pointerStartedOutside = false;
    element.showModal();
    shownDialog = element;
    const initial = element.querySelector('[data-dialog-initial-focus]');
    if (canFocus(initial)) initial.focus();
    emit('opened');
}, { flush: 'post' });

onBeforeUnmount(() => closeDialog(dialog.value));

function requestClose() {
    if (props.closeable) emit('close');
}

/** @param {MouseEvent} event */
function isBackdrop(event) {
    if (!dialog.value || event.target !== dialog.value) return false;
    const { left, right, top, bottom } = dialog.value.getBoundingClientRect();
    return event.clientX < left || event.clientX >= right || event.clientY < top || event.clientY >= bottom;
}

/** @param {MouseEvent} event */
function markPointer(event) {
    pointerStartedOutside = isBackdrop(event);
}

/** @param {MouseEvent} event */
function closeFromBackdrop(event) {
    const outside = pointerStartedOutside && isBackdrop(event);
    pointerStartedOutside = false;
    if (outside && props.closeOnBackdrop) requestClose();
}

const embeddedControls = 'audio[controls], video[controls], iframe, embed, object';
const focusable = `a[href], area[href], button, input:not([type="hidden"]), select, textarea, summary, [tabindex], [contenteditable], ${embeddedControls}`;

/** @param {HTMLDialogElement} element @param {boolean} backwards */
function tabOrder(element, backwards) {
    const window = element.ownerDocument.defaultView;
    const candidates = [.../** @type {NodeListOf<HTMLElement>} */ (element.querySelectorAll(focusable))].filter(control =>
        (control.tabIndex >= 0 || (!control.hasAttribute('tabindex') && (control.isContentEditable || control.matches(embeddedControls))))
        && !control.matches(':disabled')
        && !control.closest('[inert]')
        && (control.checkVisibility?.({ visibilityProperty: true })
            ?? (control.getClientRects().length > 0 && window?.getComputedStyle(control).visibility === 'visible')),
    );
    /** @type {Map<HTMLFormElement | null, Map<string, RadioGroup>>} */
    const radioGroups = new Map();
    /** @type {Map<HTMLElement, RadioGroup>} */
    const groupsByRadio = new Map();
    for (const control of candidates) {
        if (!control.matches('input[type="radio"]')) continue;
        const radio = /** @type {HTMLInputElement} */ (control);
        if (!radio.name) continue;
        let namedGroups = radioGroups.get(radio.form);
        if (!namedGroups) {
            namedGroups = new Map();
            radioGroups.set(radio.form, namedGroups);
        }
        let group = namedGroups.get(radio.name);
        if (!group) {
            group = { first: radio, last: radio };
            namedGroups.set(radio.name, group);
        }
        group.last = radio;
        if (radio.checked) group.checked = radio;
        groupsByRadio.set(radio, group);
    }
    /** @param {HTMLElement} control */
    const rank = control => control.tabIndex > 0 ? control.tabIndex : Number.MAX_SAFE_INTEGER;
    return candidates.filter(control => {
        const group = groupsByRadio.get(control);
        return !group || control === (group.checked ?? (backwards ? group.last : group.first));
    }).sort((left, right) => rank(left) - rank(right));
}

/** @param {KeyboardEvent} event */
function handleKeydown(event) {
    if (event.defaultPrevented || event.isComposing || event.keyCode === 229) return;
    if (event.key === 'Escape') {
        // Un buscador con texto consume Escape antes de que el diálogo reciba cancel.
        event.preventDefault();
        event.stopPropagation();
        requestClose();
        return;
    }
    if (event.key !== 'Tab') return;
    const element = dialog.value;
    if (!element) return;
    const backwards = event.shiftKey;
    const order = tabOrder(element, backwards);
    if (!order.length) {
        event.preventDefault();
        return;
    }
    const active = element.ownerDocument.activeElement;
    const boundary = backwards ? order[0] : order.at(-1);
    const node = element.ownerDocument.defaultView?.Node;
    if (!node) return;
    const direction = backwards ? node.DOCUMENT_POSITION_PRECEDING : node.DOCUMENT_POSITION_FOLLOWING;
    const wrap = order.some(control => control === active)
        ? active === boundary
        : !active || !element.contains(active) || !order.some(control => active.compareDocumentPosition(control) & direction);
    if (wrap) {
        event.preventDefault();
        order[backwards ? order.length - 1 : 0].focus();
    }
}
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialog"
            aria-modal="true"
            v-bind="$attrs"
            @cancel="handleCancel"
            @focusin="rememberFocus"
            @close="handleNativeClose"
            @pointerdown="markPointer"
            @click="closeFromBackdrop"
            @keydown="handleKeydown"
            ><slot
        /></dialog>
    </Teleport>
</template>
