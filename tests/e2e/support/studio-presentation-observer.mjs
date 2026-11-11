/** Browser-evaluated: capture the trusted gesture before the existing Vue click handler. */
export function armPresentationCloseObserver(button) {
    const probe = window.__presentationProbe;
    delete probe.closeObservation;
    function capture(event) {
        if (!event.isTrusted) return;
        probe.closeObservation = { at: performance.now(), trusted: true, eventIndex: probe.events.length };
        probe.closed = true;
        button.removeEventListener('click', capture, true);
    }
    button.addEventListener('click', capture, true);
}

export function readPresentationCloseObservation() {
    return window.__presentationProbe.closeObservation ?? null;
}
