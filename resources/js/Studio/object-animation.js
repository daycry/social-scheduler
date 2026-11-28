/**
 * Animaciones de entrada y salida por objeto. Es la ÚNICA implementación del cálculo: la previsualización,
 * el render MP4/GIF de servidor (Chromium) y la exportación por fotograma llaman a `animationState`.
 * El servidor valida el mismo esquema en StudioTimeline.php (tipos, rangos y ajuste a la vida del objeto).
 */
export const ANIMATION_TYPES = Object.freeze(['fade', 'slide_left', 'slide_right', 'slide_up', 'slide_down', 'zoom']);
export const ANIMATION_EASINGS = Object.freeze(['linear', 'ease_in', 'ease_out', 'ease_in_out']);
export const ANIMATION_LIMITS = Object.freeze({
    minDuration: 0.1,
    maxDuration: 10,
    maxDelay: 3600,
    slideDistance: 0.25,
    zoomFrom: 0.5,
});
export const ANIMATION_DEFAULT_EASING = 'ease_in_out';
const PHASES = ['in', 'out'];
const EPS = 1e-9;
const IDENTITY = Object.freeze({ opacity: 1, offsetX: 0, offsetY: 0, scale: 1 });

const plain = (value) =>
    value !== null &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    [Object.prototype, null].includes(Object.getPrototypeOf(value));
const fail = (path, message) => {
    throw new Error(`${path}: ${message}`);
};
const span = (phase) => (phase.delay ?? 0) + phase.duration;

// A cropped phase can extend past either edge. Only its occupied time counts against the window.
export const animationPhaseSpan = (phase, length) =>
    phase.clipped ? Math.min(length, Math.max(0, span(phase))) : span(phase);

/** Valida la animación de un objeto cuya vida dura `length` segundos y devuelve una copia. */
export function normalizeAnimation(animation, length, path = 'animation') {
    if (!plain(animation)) fail(path, 'la animación debe ser un objeto.');
    for (const key of Object.keys(animation))
        if (!PHASES.includes(key)) fail(`${path}.${key}`, 'propiedad de animación no admitida.');
    if (!PHASES.some((key) => Object.hasOwn(animation, key))) fail(path, 'indica una entrada o una salida.');
    const result = {};
    let total = 0;
    for (const key of PHASES) {
        if (!Object.hasOwn(animation, key)) continue;
        const phase = animation[key],
            where = `${path}.${key}`;
        if (!plain(phase)) fail(where, 'la fase debe ser un objeto.');
        for (const name of Object.keys(phase))
            if (!['type', 'duration', 'delay', 'easing', 'clipped'].includes(name))
                fail(`${where}.${name}`, 'propiedad de animación no admitida.');
        if (!ANIMATION_TYPES.includes(phase.type)) fail(`${where}.type`, 'tipo de animación no admitido.');
        const { minDuration, maxDuration, maxDelay } = ANIMATION_LIMITS;
        if (!Number.isFinite(phase.duration) || phase.duration < minDuration || phase.duration > maxDuration)
            fail(`${where}.duration`, `la duración debe estar entre ${minDuration} y ${maxDuration} s.`);
        if (Object.hasOwn(phase, 'clipped') && phase.clipped !== true)
            fail(`${where}.clipped`, 'el indicador de recorte debe ser true.');
        const minDelay = phase.clipped ? -maxDuration : 0;
        if (
            Object.hasOwn(phase, 'delay') &&
            (!Number.isFinite(phase.delay) || phase.delay < minDelay || phase.delay > maxDelay)
        )
            fail(`${where}.delay`, `el retardo debe estar entre ${minDelay} y ${maxDelay} s.`);
        // Una fase recortada cuya curva acaba antes de que empiece el clip no haría nada: se rechaza (mismo criterio en `StudioTimeline.php`).
        if (phase.clipped && (phase.delay ?? 0) <= -phase.duration)
            fail(`${where}.delay`, 'el retardo deja la fase recortada entera antes del clip.');
        if (Object.hasOwn(phase, 'easing') && !ANIMATION_EASINGS.includes(phase.easing))
            fail(`${where}.easing`, 'curva de suavizado no admitida.');
        result[key] = { ...phase };
        total += animationPhaseSpan(phase, length);
    }
    if (total > length + EPS) fail(path, 'la entrada y la salida no caben en la vida del objeto.');
    return result;
}

/** Descarta las fases que ya no caben tras acortar la vida del objeto; devuelve null si no queda ninguna. */
export function fitAnimation(animation, length) {
    if (!animation) return null;
    const result = {};
    let total = 0;
    for (const key of PHASES) {
        const phase = animation[key];
        if (!phase) continue;
        const occupied = animationPhaseSpan(phase, length);
        if (occupied > 0 && total + occupied <= length + EPS) {
            result[key] = { ...phase };
            total += occupied;
        }
    }
    return Object.keys(result).length ? result : null;
}

/** Ventana efectiva de un objeto: su `timing` (o la página) cortada por la del grupo que lo contiene. */
export function effectiveWindow(own, parent) {
    if (!parent) return own;
    const start = Math.max(own.start, parent.start);
    return { start, duration: Math.max(0, Math.min(own.start + own.duration, parent.start + parent.duration) - start) };
}

/** Reancla cada fase sin alterar la duración de su curva ni los tramos transparentes. */
function sliceAnimation(animation, trimStart, trimEnd, length) {
    const result = {};
    if (length <= 0) return null;
    for (const key of PHASES) {
        const phase = animation?.[key];
        if (!phase) continue;
        const trim = key === 'in' ? trimStart : trimEnd;
        const delay = (phase.delay ?? 0) - trim;
        if (delay + phase.duration <= 0) continue;
        const rebased = { ...phase };
        if (trim > 0) rebased.delay = delay;
        if (delay < 0 || delay + phase.duration > length + EPS) rebased.clipped = true;
        else delete rebased.clipped;
        result[key] = rebased;
    }
    return Object.keys(result).length ? result : null;
}

export function splitAnimation(animation, before, after) {
    return {
        left: sliceAnimation(animation, 0, after, before),
        right: sliceAnimation(animation, before, 0, after),
    };
}

/** Se conserva el efecto del grupo en la ventana común cuando cabe junto con la animación del hijo. */
export function ungroupAnimation(
    childAnimation,
    parentAnimation,
    parentWindow,
    window,
    { centered, sharedOpacity } = {},
) {
    const { duration } = window;
    const animation = fitAnimation(childAnimation, duration) ?? {};
    const inherited = sliceAnimation(
        fitAnimation(parentAnimation, parentWindow.duration),
        window.start - parentWindow.start,
        Math.max(0, parentWindow.start + parentWindow.duration - window.start - duration),
        duration,
    );
    let loss = false;
    for (const key of PHASES) {
        const phase = inherited?.[key];
        if (!phase) continue;
        const phaseLength = animationPhaseSpan(phase, duration);
        const own = animation[key];
        if (own && phaseLength <= (own.delay ?? 0)) continue;
        const other = animation[key === 'in' ? 'out' : 'in'];
        const occupied = phaseLength + (other ? animationPhaseSpan(other, duration) : 0);
        if (own || occupied > duration + EPS) {
            loss = true;
            continue;
        }
        const movesCenter = phase.type === 'zoom' && !centered;
        if (movesCenter || sharedOpacity) loss = true;
        animation[key] = movesCenter ? { ...phase, type: 'fade' } : phase;
    }
    return { animation, loss };
}

const curves = {
    linear: (p) => p,
    ease_in: (p) => p * p,
    ease_out: (p) => 1 - (1 - p) * (1 - p),
    ease_in_out: (p) => (p < 0.5 ? 2 * p * p : 1 - (-2 * p + 2) ** 2 / 2),
};
const direction = { slide_left: [-1, 0], slide_right: [1, 0], slide_up: [0, -1], slide_down: [0, 1] };

/**
 * Estado animado en el instante local `time` de la página para un objeto con ventana `window` ({start, duration}).
 * Sin animación devuelve la identidad: el resultado no cambia respecto a un documento sin esta función.
 * Con `settled` (salida estática sin instante explícito) el objeto se ve como se diseñó: entradas completadas y
 * salidas sin empezar. Los desplazamientos son fracciones del ancho/alto de la página.
 */
export function animationState(animation, time, window, { settled = false } = {}) {
    if (!animation || settled) return IDENTITY;
    let opacity = 1,
        offsetX = 0,
        offsetY = 0,
        scale = 1;
    const apply = (phase, away) => {
        const [dx, dy] = direction[phase.type] ?? [0, 0];
        opacity *= 1 - away;
        offsetX += dx * away * ANIMATION_LIMITS.slideDistance;
        offsetY += dy * away * ANIMATION_LIMITS.slideDistance;
        if (phase.type === 'zoom') scale *= 1 - (1 - ANIMATION_LIMITS.zoomFrom) * away;
    };
    const progress = (phase, from) => Math.min(1, Math.max(0, (time - from) / phase.duration));
    if (animation.in) {
        const phase = animation.in;
        apply(
            phase,
            1 - curves[phase.easing ?? ANIMATION_DEFAULT_EASING](progress(phase, window.start + (phase.delay ?? 0))),
        );
    }
    if (animation.out) {
        const phase = animation.out;
        apply(
            phase,
            curves[phase.easing ?? ANIMATION_DEFAULT_EASING](
                progress(phase, window.start + window.duration - span(phase)),
            ),
        );
    }
    return { opacity, offsetX, offsetY, scale };
}
