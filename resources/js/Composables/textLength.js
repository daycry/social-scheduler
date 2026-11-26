/**
 * Longitud de un texto segun la regla de cada red (`text.count` de `config/media_specs.php`). Espejo de `App\Support\TextLength`:
 * si cambia una, cambia la otra. Se cuenta sobre el texto recortado, como hace el servidor.
 *
 *  - chars:     puntos de codigo Unicode (`[...text]`; NO `text.length`, que cuenta unidades UTF-16).
 *  - graphemes: grafemas con `Intl.Segmenter` (un emoji compuesto cuenta 1).
 *  - threads:   cada secuencia de emoji cuenta los bytes UTF-8 enteros; el resto, 1 por code point (no por grafema).
 *  - mastodon:  grafemas, con cada URL contada como la reserva de la instancia y una mencion remota `@usuario@dominio` como `@usuario`.
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

// Mismos patrones que `TextLength::mastodon` (ASCII explicito en lugar de `\w`: igual en PHP y en JS).
// La puntuacion final (punto, coma, cierre de parentesis o comillas) no forma parte de la URL, como en twitter-text.
const MASTODON_URL = /https?:\/\/\S+?(?=[.,:;!?)\]"'»]*(?:\s|$))/giu;
const MASTODON_REMOTE_MENTION = /(?<![=/A-Za-z0-9_])(@[A-Za-z0-9_]+(?:[A-Za-z0-9_.-]+[A-Za-z0-9_]+)?)@[A-Za-z0-9_.-]+[A-Za-z0-9_]/gu;

/** Reserva por URL de Mastodon cuando la instancia no dice otra (`characters_reserved_per_url`). */
export const MASTODON_URL_LENGTH = 23;

export const countMastodon = (text, urlLength = MASTODON_URL_LENGTH) => {
    const t = String(text ?? '').trim();
    if (t === '') return 0;

    return countGraphemes(t.replace(MASTODON_URL, () => 'x'.repeat(Math.max(0, urlLength))).replace(MASTODON_REMOTE_MENTION, '$1'));
};

export const countChars = (text) => [...String(text ?? '').trim()].length;

/** Longitud segun el modo del tipo de contenido (`limits[tipo].count`; sin valor = chars). `urlLength`: solo Mastodon (`account.limits.url_length`). */
export const countText = (text, mode = 'chars', urlLength = undefined) => {
    if (mode === 'graphemes') return countGraphemes(text);
    if (mode === 'mastodon') return countMastodon(text, urlLength);
    if (mode === 'threads') return countThreads(text);

    return countChars(text);
};
