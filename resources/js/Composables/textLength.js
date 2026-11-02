/**
 * Longitud de un texto segun la regla de cada red (`text.count` de `config/media_specs.php`). Espejo de `App\Support\TextLength`:
 * si cambia una, cambia la otra. Se cuenta sobre el texto recortado, como hace el servidor.
 *
 *  - chars:     puntos de codigo Unicode (`[...text]`; NO `text.length`, que cuenta unidades UTF-16).
 *  - graphemes: grafemas con `Intl.Segmenter` (un emoji compuesto cuenta 1).
 *  - threads:   cada secuencia de emoji cuenta los bytes UTF-8 enteros; el resto, 1 por code point (no por grafema).
 */
const segmenter = typeof Intl !== 'undefined' && Intl.Segmenter ? new Intl.Segmenter(undefined, { granularity: 'grapheme' }) : null;
const encoder = new TextEncoder();

export const countGraphemes = (text) => {
    const t = String(text ?? '').trim();
    if (t === '') return 0;

    return segmenter ? [...segmenter.segment(t)].length : (t.match(/\P{M}\p{M}*/gu) ?? []).length;
};

// Secuencia de emoji: MISMO patron que `EMOJI_SEQUENCE` de `App\Support\TextLength` (sin `Intl.Segmenter` ni `\X`).
const EMOJI_SEQUENCE =
    /[\u{1F1E6}-\u{1F1FF}]{1,2}|[0-9#*]\u{FE0F}?\u{20E3}|\p{Extended_Pictographic}[\u{FE0F}\u{1F3FB}-\u{1F3FF}\u{E0020}-\u{E007F}]*(?:\u{200D}\p{Extended_Pictographic}[\u{FE0F}\u{1F3FB}-\u{1F3FF}\u{E0020}-\u{E007F}]*)*|[\u{200D}\u{FE0F}\u{1F3FB}-\u{1F3FF}\u{20E3}]/gu;

// Conservador (espejo de PHP): cada secuencia de emoji cuenta los bytes UTF-8 de la secuencia completa; fuera de los emojis, 1 por code point
// (no por grafema: marcas combinantes cuentan 1, y asi PHP y JS coinciden aunque segmenten distinto el devanagari, NFD o zalgo).
export const countThreads = (text) => {
    const t = String(text ?? '').trim();
    if (t === '') return 0;

    let total = [...t].length;
    for (const [sequence] of t.matchAll(EMOJI_SEQUENCE)) {
        total += encoder.encode(sequence).length - [...sequence].length;
    }

    return total;
};

export const countChars = (text) => [...String(text ?? '').trim()].length;

/** Longitud segun el modo del tipo de contenido (`limits[tipo].count`; sin valor = chars). */
export const countText = (text, mode = 'chars') => {
    if (mode === 'graphemes') return countGraphemes(text);
    if (mode === 'threads') return countThreads(text);

    return countChars(text);
};
