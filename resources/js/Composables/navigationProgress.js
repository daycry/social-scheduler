/**
 * Indicador de navegacion de Inertia. La barra que trae Inertia (`@inertiajs/core` 2.x) inyecta `<div class="bar" role="bar">` y
 * `role="spinner"`, roles ARIA que no existen (axe: `aria-roles`, critical) y su plantilla no es configurable (`setupProgress` solo admite
 * `delay`, `color`, `includeCSS` y `showSpinner`; el selector `[role="bar"]` esta fijo en el componente). Por eso se desactiva
 * (`progress: false` en app.js) y se escucha el mismo par de eventos (`inertia:start`/`inertia:finish`) con una barra propia:
 * `role="progressbar"` con nombre accesible. Conserva el id `#nprogress` (los E2E esperan que desaparezca al terminar).
 */
const DELAY_MS = 250; // igual que Inertia: las navegaciones rapidas no parpadean
const ID = 'nprogress';

export const installNavigationProgress = () => {
    let active = 0;
    let timer = null;

    const show = () => {
        timer = null;
        if (active === 0 || document.getElementById(ID)) return;
        const host = document.createElement('div');
        host.id = ID;
        const bar = document.createElement('div');
        bar.className = 'bar';
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-label', 'Cargando página');
        host.appendChild(bar);
        document.body.appendChild(host);
    };

    const hide = () => {
        clearTimeout(timer);
        timer = null;
        document.getElementById(ID)?.remove();
    };

    document.addEventListener('inertia:start', (event) => {
        if (event.detail?.visit?.showProgress === false) return;
        active++;
        if (timer === null && !document.getElementById(ID)) timer = setTimeout(show, DELAY_MS);
    });

    document.addEventListener('inertia:finish', (event) => {
        if (event.detail?.visit?.showProgress === false) return;
        active = Math.max(0, active - 1);
        if (active === 0) hide();
    });
};
