import { nextTick } from 'vue';

/**
 * Muestra el mensaje general del grupo o el primer error de uno de sus elementos.
 * @param {Record<string, string | undefined>} errors
 * @param {string} field
 */
export function firstGroupError(errors, field) {
    return errors[field] || Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1];
}

/**
 * Espera al render de Inertia antes de enfocar el primer error visible del formulario enviado.
 * @param {HTMLFormElement | null | undefined} form
 */
export async function focusFirstFormError(form) {
    await nextTick();
    const first = [...(/** @type {NodeListOf<HTMLElement> | undefined} */ (form?.querySelectorAll('[aria-invalid="true"], [data-error-target="true"]')) ?? [])]
        .find(control => control.getClientRects().length > 0 && !control.matches(':disabled'));
    first?.focus();
}

/**
 * Conserva un destino de foco cuando se mueve o se elimina el bloque editado.
 * @param {HTMLFormElement | null | undefined} form
 * @param {number} index
 * @param {string | null | undefined} direction
 */
export async function focusEditedBlock(form, index, direction) {
    await nextTick();
    const block = form?.querySelector(`[data-block-index="${index}"]`);
    const preferred = direction && block?.querySelector(`[data-block-shift="${direction}"]:not(:disabled)`);
    const other = direction && block?.querySelector('[data-block-shift]:not(:disabled)');
    (/** @type {HTMLElement | null | undefined} */ (preferred || other || block || form?.querySelector('[data-add-block]:not(:disabled)')))?.focus();
}

/**
 * Conserva las claves locales de bloques nuevos al moverlos y mientras el servidor asigna sus IDs.
 * @template {object} T
 * @template Key
 * @param {(block: T) => Key} serverKey
 */
export function createBlockKey(serverKey) {
    /** @type {WeakMap<T, symbol>} */
    const local = new WeakMap();
    /** @param {T} block */
    return block => {
        const key = serverKey(block);
        if (key != null) return key;
        if (!local.has(block)) local.set(block, Symbol());
        return local.get(block);
    };
}
