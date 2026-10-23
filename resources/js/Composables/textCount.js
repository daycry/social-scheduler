/**
 * Recuento de texto idéntico al del servidor (`MediaValidator`): puntos de código Unicode, como `mb_strlen`.
 * `[...text]` itera por puntos de código, así que un emoji astral cuenta 1 y una secuencia con ZWJ (p. ej. una familia)
 * cuenta cada componente y cada unión, igual que PHP. NO usar `text.length` (cuenta unidades UTF-16).
 */
export const countChars = (text) => [...(text ?? '')].length;

/** Hashtags: `#` seguido de letras, números o guion bajo (Unicode). Misma regla que `MediaValidator::countHashtags()`. */
export const countHashtags = (text) => (String(text ?? '').match(/#[\p{L}\p{N}_]+/gu) ?? []).length;

/** Miles con punto, como en la especificación («2.200», «63.206»), sin depender del locale del navegador. */
export const formatThousands = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
