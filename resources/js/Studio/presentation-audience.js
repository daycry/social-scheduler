/** Same-origin, user-gesture window. Never writes document data or HTML markup. */
export function createAudienceWindow({ opener = window, onKey = () => {}, onPointer, onError = () => {} } = {}) {
    const popup = opener.open('', '_blank', 'popup,width=1280,height=800');
    if (!popup) throw new Error('La ventana de audiencia está bloqueada. Permite ventanas emergentes y vuelve a abrirla.');
    let closed = false;
    let canvas = null;
    const pointerIds = new Set();
    const pointerEvents = ['pointerdown', 'pointermove', 'pointerup', 'pointercancel', 'pointerleave', 'lostpointercapture'];
    const pointer = event => {
        if (closed || popup.closed) return;
        if (event.type === 'pointerdown') pointerIds.add(event.pointerId);
        if (['pointerup', 'pointercancel', 'lostpointercapture'].includes(event.type)) pointerIds.delete(event.pointerId);
        onPointer?.(event, canvas);
    };
    const available = () => !closed && !popup.closed;
    const fullscreenHint = 'Doble clic o F para pantalla completa';
    async function fullscreen() {
        if (!available()) return false;
        try {
            // No async boundary before the native call: activation belongs to
            // the popup window when invoked by its own input listener.
            if (popup.document.fullscreenElement === canvas) {
                if (!popup.document.exitFullscreen) throw new Error('Salida no disponible');
                await popup.document.exitFullscreen();
            } else {
                if (!canvas.requestFullscreen) throw new Error('Solicitud no disponible');
                await canvas.requestFullscreen();
            }
            return available();
        } catch (error) {
            if (!available()) return false;
            throw new Error(`No se pudo cambiar la pantalla completa. ${fullscreenHint} en la ventana de audiencia.`, { cause: error });
        }
    }
    const gesture = event => {
        if (!available() || event.isTrusted !== true) return;
        event.preventDefault?.(); event.stopPropagation?.();
        void fullscreen().catch(error => { if (available()) onError(error); });
    };
    const key = event => {
        if (!available()) return;
        if (event.key?.toLowerCase() === 'f') {
            if (!event.repeat && !event.ctrlKey && !event.altKey && !event.metaKey) gesture(event);
            return;
        }
        onKey(event);
    };
    const cleanup = () => {
        popup.removeEventListener('keydown', key); popup.removeEventListener('pagehide', pagehide);
        canvas?.removeEventListener('dblclick', gesture);
        if (onPointer) for (const event of pointerEvents) canvas?.removeEventListener(event, pointer);
        for (const pointerId of pointerIds) {
            if (canvas?.hasPointerCapture?.(pointerId)) canvas.releasePointerCapture(pointerId);
            onPointer?.({ type: 'pointercancel', pointerId, clientX: NaN, clientY: NaN }, canvas);
        }
        pointerIds.clear();
    };
    const pagehide = () => { closed = true; cleanup(); };
    try {
        popup.opener = null;
        popup.document.title = 'Audiencia · Studio';
        canvas = popup.document.createElement('canvas');
        canvas.setAttribute('aria-label', `Diapositiva de audiencia. ${fullscreenHint}`);
        canvas.setAttribute('title', fullscreenHint);
        canvas.setAttribute('tabindex', '0');
        canvas.setAttribute('role', 'img');
        Object.assign(canvas.style, { display: 'block', width: '100%', height: '100%', objectFit: 'contain', touchAction: 'none' });
        Object.assign(popup.document.body.style, { margin: '0', background: '#000', width: '100vw', height: '100vh', overflow: 'hidden' });
        popup.document.body.replaceChildren(canvas);
        popup.addEventListener('keydown', key); popup.addEventListener('pagehide', pagehide);
        canvas.addEventListener('dblclick', gesture);
        if (onPointer) for (const event of pointerEvents) canvas.addEventListener(event, pointer);
        return Object.freeze({
            setCursor(value) { if (!closed && !popup.closed && ['default', 'crosshair', 'none'].includes(value)) canvas.style.cursor = value; },
            paint(source, { blackout = false } = {}) {
                if (closed || popup.closed) return false;
                canvas.width = source.width; canvas.height = source.height;
                const context = canvas.getContext('2d');
                if (!context) throw new Error('No se pudo dibujar la audiencia.');
                if (blackout) { context.fillStyle = '#000'; context.fillRect(0, 0, canvas.width, canvas.height); }
                else context.drawImage(source, 0, 0);
                return true;
            },
            fullscreen,
            close() { if (closed) return; closed = true; cleanup(); if (!popup.closed) popup.close(); },
        });
    } catch (error) { cleanup(); if (!popup.closed) popup.close(); throw error; }
}
