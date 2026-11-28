// Que enviar cuando termina un guardado (A-07).
//  requested: el guardado que se pidio era un autoguardado (true) o explicito (false).
//  inflight:  el guardado que estaba en vuelo era un autoguardado.
//  dirty:     hay cambios sin guardar.
// - Con cambios pendientes se reenvian con el origen del guardado pedido.
// - Un guardado explicito que espero a un autoguardado sin mas cambios envia igualmente su propio punto de control (force).
export function nextSave({ requested, inflight, dirty }) {
    if (dirty) return { send: true, autosave: requested === true, force: false };
    if (requested !== true && inflight === true) return { send: true, autosave: false, force: true };
    return { send: false, autosave: false, force: false };
}
