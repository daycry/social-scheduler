// Etiquetas de usuario de Instagram sobre una imagen (`user_tags`): [{ username, x, y }] con x e y entre 0 y 1 desde la esquina superior
// izquierda, como las pide la API de publicación de Instagram. Funciones puras: las usa el editor y las prueba `test:unit`.
export const MAX_USER_TAGS = 20;
export const USERNAME_PATTERN = /^[a-z0-9._]{1,30}$/;

export const normalizeUsername = (value) => String(value ?? '').trim().replace(/^@/, '').toLowerCase();

/** Redondea a 3 decimales y mantiene el valor dentro de 0-1. */
export const clamp01 = (value) => {
    const n = Number(value);
    if (!Number.isFinite(n)) return 0;
    return Math.round(Math.min(1, Math.max(0, n)) * 1000) / 1000;
};

/** Posición relativa (0-1) de un clic dentro del rectángulo de la imagen. */
export const pointToCoordinates = (rect, clientX, clientY) => ({
    x: clamp01(rect.width > 0 ? (clientX - rect.left) / rect.width : 0),
    y: clamp01(rect.height > 0 ? (clientY - rect.top) / rect.height : 0),
});

/**
 * Añade (o mueve, si el usuario ya está) una etiqueta. Devuelve { tags, error }: con error la lista no cambia.
 * @param {Array<{username: string, x: number, y: number}>} tags
 */
export function upsertTag(tags, rawUsername, x, y, max = MAX_USER_TAGS) {
    const username = normalizeUsername(rawUsername);
    if (!USERNAME_PATTERN.test(username)) return { tags, error: 'Indica un usuario de Instagram válido, sin enlaces.' };
    const next = { username, x: clamp01(x), y: clamp01(y) };
    const index = tags.findIndex((t) => t.username === username);
    if (index >= 0) return { tags: tags.map((t, i) => (i === index ? next : t)), error: null };
    if (tags.length >= max) return { tags, error: `Instagram admite hasta ${max} etiquetas por imagen.` };
    return { tags: [...tags, next], error: null };
}

export const removeTag = (tags, username) => tags.filter((t) => t.username !== username);
