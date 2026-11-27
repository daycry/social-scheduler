import { Point } from 'fabric';
import { animationState, effectiveWindow, fitAnimation } from './object-animation.js';

/**
 * Aplica a un objeto de Fabric el estado animado de su elemento en el instante local `time`.
 * Parte siempre de la posición base guardada la primera vez, así que repetir el instante no acumula nada.
 * Sin `animation` no toca el objeto: los diseños antiguos se pintan exactamente igual que antes.
 * Devuelve la ventana efectiva del elemento, para pasarla como `parent` a los hijos de un grupo.
 */
export function applyElementAnimation(object, element, time, { width, height, pageDuration, parent, settled = false }) {
    // La ventana efectiva (la del grupo cortada con la propia) manda; el llamante la pasa a los hijos del grupo.
    const window = effectiveWindow(element.timing ?? { start: 0, duration: pageDuration }, parent);
    // En reposo (salida estática sin instante explícito) el objeto queda como se diseñó.
    if (!element.animation || settled) return window;
    // Las fases enteras deben caber en la ventana efectiva. Las recortadas conservan su curva original.
    const animation = fitAnimation(element.animation, window.duration);
    if (!animation) return window;
    object.studioAnimationBase ??= {
        center: object.getRelativeCenterPoint(),
        scaleX: object.scaleX,
        scaleY: object.scaleY,
        opacity: object.opacity,
    };
    const base = object.studioAnimationBase;
    const state = animationState(animation, time, window);
    object.set({
        opacity: base.opacity * state.opacity,
        scaleX: base.scaleX * state.scale,
        scaleY: base.scaleY * state.scale,
    });
    object.setPositionByOrigin(
        new Point(base.center.x + state.offsetX * width, base.center.y + state.offsetY * height),
        'center',
        'center',
    );
    object.setCoords();
    object.dirty = true;
    return window;
}
