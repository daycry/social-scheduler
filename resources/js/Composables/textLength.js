/**
 * Longitud de un texto segun la regla de cada red (`text.count` de `config/media_specs.php`). Espejo de `App\Support\TextLength`:
 * si cambia una, cambia la otra. Se cuenta sobre el texto recortado, como hace el servidor.
 *
 *  - chars:     puntos de codigo Unicode (`[...text]`; NO `text.length`, que cuenta unidades UTF-16).
 *  - graphemes: grafemas con `Intl.Segmenter` (un emoji compuesto cuenta 1).
 *  - threads:   puntos de codigo, con cada emoji contado por sus bytes UTF-8.
 */
const segmenter = typeof Intl !== 'undefined' && Intl.Segmenter ? new Intl.Segmenter(undefined, { granularity: 'grapheme' }) : null;
const encoder = new TextEncoder();

export const countGraphemes = (text) => {
    const t = String(text ?? '').trim();
    if (t === '') return 0;

    return segmenter ? [...segmenter.segment(t)].length : (t.match(/\P{M}\p{M}*/gu) ?? []).length;
};

export const countThreads = (text) =>
    [...String(text ?? '').trim()].reduce((n, ch) => n + (/\p{Extended_Pictographic}/u.test(ch) ? encoder.encode(ch).length : 1), 0);

export const countChars = (text) => [...String(text ?? '').trim()].length;

/** Longitud segun el modo del tipo de contenido (`limits[tipo].count`; sin valor = chars). */
export const countText = (text, mode = 'chars') => {
    if (mode === 'graphemes') return countGraphemes(text);
    if (mode === 'threads') return countThreads(text);

    return countChars(text);
};
