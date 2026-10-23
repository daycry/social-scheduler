/**
 * Puerta para el botón Atrás del navegador. Inertia gestiona `popstate` sin emitir un evento `before` cancelable y su
 * listener se registra al crear la app; para poder adelantarnos (y detener su propagación con `stopImmediatePropagation`)
 * este listener se instala ANTES de `createInertiaApp` (los listeners de un mismo objeto se ejecutan en orden de registro).
 * `UnsavedGuard` fija el manejador mientras el compositor está montado; sin manejador no hace nada.
 */
let handler = null;

export const setBackHandler = (fn) => {
    handler = fn;
};

export const installBackGuard = () => {
    window.addEventListener('popstate', (event) => handler?.(event));
};
