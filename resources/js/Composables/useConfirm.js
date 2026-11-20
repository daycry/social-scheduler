import { inject, onScopeDispose, shallowReadonly, shallowRef } from 'vue';

/** @typedef {{message: string, title?: string, confirmLabel?: string, cancelLabel?: string}} ConfirmOptions */
/** @typedef {{id: number, accept: () => void, cancel: () => void, title: string, message: string, confirmLabel: string, cancelLabel: string}} ConfirmPrompt */
/** @type {import('vue').InjectionKey<ReturnType<typeof createConfirmState>>} */
export const confirmKey = Symbol('confirmation');

export function createConfirmState() {
    const pending = shallowRef(/** @type {ConfirmPrompt | null} */ (null));
    /** @type {((accepted: boolean) => void) | null} */
    let finish = null;
    let nextId = 0;

    /** @param {boolean} accepted */
    function answer(accepted, id = pending.value?.id) {
        if (!finish || id !== pending.value?.id) return;
        const resolve = finish;
        finish = null;
        pending.value = null;
        resolve(accepted === true);
    }

    /** @param {ConfirmOptions | string} question @param {...(AbortSignal | null | undefined)} signals @returns {Promise<boolean>} */
    function ask(question, ...signals) {
        const sources = signals.filter((signal) => signal != null);
        if (finish || sources.some(signal => signal.aborted)) return Promise.resolve(false);
        const options = typeof question === 'string' ? { message: question } : question;
        return new Promise(resolve => {
            const id = ++nextId;
            const cancel = () => answer(false, id);
            finish = accepted => {
                for (const signal of sources) signal.removeEventListener('abort', cancel);
                resolve(accepted);
            };
            for (const signal of sources) signal.addEventListener('abort', cancel, { once: true });
            pending.value = {
                id,
                cancel,
                accept: () => answer(true, id),
                title: options.title ?? 'Confirmar acción',
                message: options.message,
                confirmLabel: options.confirmLabel ?? 'Confirmar',
                cancelLabel: options.cancelLabel ?? 'Cancelar',
            };
        });
    }

    return { pending: shallowReadonly(pending), ask, answer };
}

export function useConfirm() {
    const state = inject(confirmKey);
    if (!state) throw new Error('Falta el diálogo de confirmación de la aplicación.');
    const scope = new AbortController();
    onScopeDispose(() => scope.abort());
    /** @param {ConfirmOptions | string} question @param {AbortSignal} [signal] */
    return (question, signal) => state.ask(question, scope.signal, signal);
}
