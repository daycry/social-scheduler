/**
 * Longitud de un texto segun la regla de cada red (`text.count` de `config/media_specs.php`). Espejo de `App\Support\TextLength`:
 * si cambia una, cambia la otra. Se cuenta sobre el texto recortado, como hace el servidor.
 *
 *  - chars:     puntos de codigo Unicode (`[...text]`; NO `text.length`, que cuenta unidades UTF-16).
 *  - graphemes: grafemas con `Intl.Segmenter` (un emoji compuesto cuenta 1).
 *  - threads:   1 por grafema, salvo los que contienen un emoji, que cuentan los bytes UTF-8 del grafema entero.
 */
const segmenter = typeof Intl !== 'undefined' && Intl.Segmenter ? new Intl.Segmenter(undefined, { granularity: 'grapheme' }) : null;
const encoder = new TextEncoder();

export const countGraphemes = (text) => {
    const t = String(text ?? '').trim();
    if (t === '') return 0;

    return segmenter ? [...segmenter.segment(t)].length : (t.match(/\P{M}\p{M}*/gu) ?? []).length;
};

const EMOJI = /[\p{Extended_Pictographic}\u{1F1E6}-\u{1F1FF}\u{200D}\u{FE0F}\u{1F3FB}-\u{1F3FF}\u{20E3}]/u;
const clusters = (t) => (segmenter ? [...segmenter.segment(t)].map((s) => s.segment) : (t.match(/\P{M}\p{M}*/gu) ?? []));

// Conservador (espejo de PHP): el grafema que contiene un emoji cuenta sus bytes UTF-8 completos; el resto, 1.
export const countThreads = (text) => {
    const t = String(text ?? '').trim();

    return t === '' ? 0 : clusters(t).reduce((n, g) => n + (EMOJI.test(g) ? encoder.encode(g).length : 1), 0);
};

export const countChars = (text) => [...String(text ?? '').trim()].length;

/** Longitud segun el modo del tipo de contenido (`limits[tipo].count`; sin valor = chars). */
export const countText = (text, mode = 'chars') => {
    if (mode === 'graphemes') return countGraphemes(text);
    if (mode === 'threads') return countThreads(text);

    return countChars(text);
};
