import { validateDocument } from './document.js';
/** Plain caption data. No DOM or font fetches are persisted. */
export const CAPTION_LIMITS = Object.freeze({ cues: 5000, overlap: 8, text: 500, importBytes: 512 * 1024 });
const plain = value => value && typeof value === 'object' && !Array.isArray(value) && [Object.prototype, null].includes(Object.getPrototypeOf(value));
const fail = message => { throw new Error(`Subtítulos: ${message}`); };
export function captionKeys(value, allowed, name) {
    if (!plain(value) || Object.keys(value).some(key => !allowed.includes(key))) fail(`${name} contiene propiedades no admitidas.`);
}
export function captionNumber(value, min, max, name) {
    if (typeof value !== 'number' || !Number.isFinite(value) || value < min || value > max) fail(`${name} fuera de rango.`);
    return value;
}
export function validateCaptionStyle(style) {
    captionKeys(style, ['fontFamily', 'font_id', 'fontSize', 'fontWeight', 'fontStyle', 'fill', 'background', 'stroke', 'strokeWidth', 'textAlign', 'lineHeight', 'position', 'margin'], 'estilo');
    if (Object.hasOwn(style, 'fontFamily') && (typeof style.fontFamily !== 'string' || !/^[\p{L}\p{N}][\p{L}\p{N} .,_-]{0,99}$/u.test(style.fontFamily) || /(?:^|,)\s*StudioFont_/i.test(style.fontFamily))) fail('familia tipográfica inválida.');
    if (Object.hasOwn(style, 'font_id') && (!Number.isSafeInteger(style.font_id) || style.font_id < 1)) fail('font_id inválido.');
    for (const [key, range] of Object.entries({ fontSize: [1, 1000], strokeWidth: [0, 20], lineHeight: [0.5, 5], margin: [0, 0.25] })) if (Object.hasOwn(style, key)) captionNumber(style[key], ...range, key);
    for (const [key, values] of Object.entries({ fontWeight: ['normal', 'bold'], fontStyle: ['normal', 'italic'], textAlign: ['left', 'center', 'right'], position: ['top', 'center', 'bottom'] })) if (Object.hasOwn(style, key) && !values.includes(style[key])) fail(`${key} inválido.`);
    for (const key of ['fill', 'background', 'stroke']) if (Object.hasOwn(style, key) && (typeof style[key] !== 'string' || !/^#[\da-f]{6}$/i.test(style[key]))) fail(`${key} debe ser hexadecimal.`);
    return Object.keys(style).length ? { ...style } : { fontFamily: 'Arial' };
}
export function validateCaptions(captions, duration, reservedIds = new Set()) {
    captionKeys(captions, ['style', 'cues'], 'pista');
    const style = validateCaptionStyle(captions.style);
    if (!Array.isArray(captions.cues) || captions.cues.length > CAPTION_LIMITS.cues) fail('se admiten hasta5000cues.');
    const ids = new Set(reservedIds), events = [];
    const cues = Array.from(captions.cues, cue => {
        captionKeys(cue, ['id', 'start', 'end', 'text'], 'cue');
        if (typeof cue.id !== 'string' || !/^[A-Za-z0-9_-]{1,64}$/.test(cue.id) || ids.has(cue.id)) fail('ID inválido o repetido.');
        ids.add(cue.id);
        const start = captionNumber(cue.start, 0, duration, 'inicio'), end = captionNumber(cue.end, 0, duration, 'fin');
        if (start >= end) fail('el fin debe ser posterior al inicio.');
        if (typeof cue.text !== 'string' || !cue.text.trim() || [...cue.text].length > CAPTION_LIMITS.text || /[\u0000-\u0008\u000b-\u001f\u007f-\u009f\ud800-\udfff]/u.test(cue.text)) fail('texto inválido o superior a500caracteres.');
        events.push([start, 1], [end, -1]);
        return { id: cue.id, start, end, text: cue.text };
    });
    events.sort((a, b) => a[0] - b[0] || a[1] - b[1]);
    let active = 0;
    for (const [, change] of events) { active += change; if (active > CAPTION_LIMITS.overlap) fail('más de8cues simultáneos.'); }
    cues.sort((a, b) => a.start - b.start || a.end - b.end || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
    return { style, cues };
}

const markup = /<[^\n]*>|&(?:[A-Za-z][A-Za-z0-9]*|#\d+|#x[0-9a-f]+);/iu;
function formatChecked(format) { if (!['srt', 'vtt'].includes(format)) fail('formato admitido: srt o vtt.'); }
function fileTime(value, format, cue) {
    const pattern = format === 'srt' ? /^(\d{2,}):([0-5]\d):([0-5]\d),(\d{3})$/ : /^(?:(\d{2,}):)?([0-5]\d):([0-5]\d)\.(\d{3})$/;
    const match = pattern.exec(value);
    if (!match) fail(`cue${cue}: tiempo inválido.`);
    const result = Number(match[1] || 0) * 3600 + Number(match[2]) * 60 + Number(match[3]) + Number(match[4]) / 1000;
    if (!Number.isFinite(result)) fail(`cue${cue}: tiempo no finito.`);
    return result;
}
export function parseCaptionFile(input, format) {
    formatChecked(format);
    if (typeof input !== 'string' || new TextEncoder().encode(input).byteLength > CAPTION_LIMITS.importBytes || /[\ud800-\udfff]/u.test(input)) fail('el archivo supera512KiB o no es Unicode válido.');
    let text = input.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n');
    if (format === 'vtt') {
        const header = /^WEBVTT(?:[^\S\n][^\n]*)?\n(?:\n|$)/u.exec(text);
        if (!header) fail('falta cabecera WEBVTT seguida de línea vacía.');
        text = text.slice(header[0].length);
    }
    const cues = [], warnings = new Set();
    for (const block of text.replace(/^\n+|\n+$/g, '').split(/\n(?:[\t ]*\n)+/)) {
        if (!block) continue;
        const lines = block.split('\n'), number = cues.length + 1;
        if (format === 'vtt' && /^NOTE(?:[\t ]|$)/.test(lines[0])) { warnings.add('Se omitió un bloque NOTE de WebVTT.'); continue; }
        let timing = lines.shift();
        if (!timing.includes('-->')) {
            if ((format === 'srt' && !/^\d+$/.test(timing)) || /^(?:STYLE|REGION)(?:\s|$)/.test(timing)) fail(`cue${number}: índice o bloque no admitido.`);
            timing = lines.shift() ?? '';
        }
        const match = /^(\S+)[\t ]+-->[\t ]+(\S+)$/.exec(timing);
        if (!match) fail(`cue${number}: intervalo inválido o ajustes WebVTT no admitidos.`);
        const body = lines.join('\n');
        if (markup.test(body)) fail(`cue${number}: etiquetas o entidades no admitidas; usa texto plano.`);
        cues.push({ id: `cue_${number}`, start: fileTime(match[1], format, number), end: fileTime(match[2], format, number), text: body });
        if (cues.length > CAPTION_LIMITS.cues) fail('se admiten hasta5000cues.');
    }
    return { cues: validateCaptions({ style: {}, cues }, Number.MAX_VALUE).cues, warnings: [...warnings] };
}
export function encodeCaptionFile(input, format) {
    formatChecked(format);
    const cues = validateCaptions({ style: {}, cues: input }, Number.MAX_VALUE).cues;
    const stamp = time => {
        const ms = Math.round(time * 1000);
        if (!Number.isSafeInteger(ms)) fail('tiempo demasiado grande para exportar.');
        return `${String(Math.floor(ms / 3600000)).padStart(2, '0')}:${String(Math.floor(ms / 60000) % 60).padStart(2, '0')}:${String(Math.floor(ms / 1000) % 60).padStart(2, '0')}${format === 'srt' ? ',' : '.'}${String(ms % 1000).padStart(3, '0')}`;
    };
    return (format === 'vtt' ? 'WEBVTT\n\n' : '') + cues.map((cue, i) => {
        if (Math.round(cue.start * 1000) >= Math.round(cue.end * 1000)) fail(`el cue${cue.id} colapsa al exportar milisegundos.`);
        if (/(?:^[\t ]*\n|\n[\t ]*$|\n[\t ]*\n)/.test(cue.text)) fail(`el cue${cue.id} contiene líneas vacías que SRT/WebVTT no conserva. Edita esas líneas para descargar; el texto guardado no cambia.`);
        if (markup.test(cue.text)) fail(`el cue${cue.id} contiene marcado que el formato no conserva.`);
        return `${i + 1}\n${stamp(cue.start)} --> ${stamp(cue.end)}\n${cue.text}\n\n`;
    }).join('');
}

/** Compile once: O(NlogN), at most8 references per interval. Query O(logN+8), including backward seeks. */
export function compileCaptions(input = []) {
    const cues = validateCaptions({ style: {}, cues: input }, Number.MAX_VALUE).cues.map(cue => Object.freeze(cue));
    const events = cues.flatMap(cue => [{ time: cue.start, cue, add: true }, { time: cue.end, cue, add: false }]);
    events.sort((a, b) => a.time - b.time || Number(a.add) - Number(b.add));
    const active = new Map(), intervals = [];
    for (let i = 0; i < events.length;) {
        const time = events[i].time;
        do { const event = events[i++]; if (event.add) active.set(event.cue.id, event.cue); else active.delete(event.cue.id); } while (i < events.length && events[i].time === time);
        intervals.push({ time, cues: [...active.values()].sort((a, b) => a.start - b.start || a.end - b.end || (a.id < b.id ? -1 : 1)) });
    }
    return {
        comparisons: 0,
        get intervalCount() { return intervals.length; },
        at(time) {
            captionNumber(time, 0, Number.MAX_VALUE, 'tiempo de consulta');
            let low = 0, high = intervals.length; this.comparisons = 0;
            while (low < high) { this.comparisons++; const middle = (low + high) >>> 1; if (intervals[middle].time <= time) low = middle + 1; else high = middle; }
            return low ? [...intervals[low - 1].cues] : [];
        },
    };
}

/** Local editor operations use the same complete-document validation as remote updates. */
export function captionOperation(input, operation) {
    captionKeys(operation, ['action', 'activate_temporal', 'format', 'text', 'mode', 'captions', 'cue', 'ids', 'seconds', 'style'], 'operación');
    const document = validateDocument(input);
    if (document.schema_version !== 2) {
        if (operation.activate_temporal !== true) fail('activa explícitamente el modo temporal.');
        document.schema_version = 2; document.timeline = { fps: 30, audio_tracks: [] };
    }
    let captions = document.timeline.captions ?? { style: { fontFamily: 'Arial' }, cues: [] };
    switch (operation.action) {
    case 'clear': delete document.timeline.captions; return validateDocument(document);
    case 'replace': captions = validateCaptions(operation.captions, document.pages.reduce((total, page) => total + page.duration - (page.transition?.duration ?? 0), 0)); break;
    case 'style': captions.style = validateCaptionStyle(operation.style); break;
    case 'upsert': captionKeys(operation.cue, ['id', 'start', 'end', 'text'], 'cue'); captions.cues = [...captions.cues.filter(cue => cue.id !== operation.cue.id), { ...operation.cue }]; break;
    case 'import': {
        if (operation.mode !== undefined && !['append', 'replace'].includes(operation.mode)) fail('modo de importación inválido.');
        const { cues } = parseCaptionFile(operation.text, operation.format);
        const append = (operation.mode ?? 'append') === 'append';
        const ids = new Set(), collect = elements => elements.forEach(element => { ids.add(element.id); if (element.type === 'group') collect(element.elements); });
        for (const page of document.pages) { ids.add(page.id); collect(page.elements); }
        for (const track of document.timeline.audio_tracks) ids.add(track.id);
        if (append) for (const cue of captions.cues) ids.add(cue.id);
        let next = 1;
        for (const cue of cues) { while (ids.has(cue.id)) cue.id = `import_${next++}`; ids.add(cue.id); }
        if (append) {
            captions.cues.push(...cues);
        } else captions.cues = cues;
        break;
    }
    case 'offset':
    case 'delete': {
        if (operation.ids !== undefined && (!Array.isArray(operation.ids) || new Set(operation.ids).size !== operation.ids.length || operation.ids.some(id => typeof id !== 'string' || !captions.cues.some(cue => cue.id === id)))) fail('selección de cues inválida.');
        const ids = new Set(operation.ids ?? captions.cues.map(cue => cue.id));
        if (operation.action === 'delete') captions.cues = captions.cues.filter(cue => !ids.has(cue.id));
        else { const seconds = captionNumber(operation.seconds, -86400, 86400, 'desplazamiento'); captions.cues = captions.cues.map(cue => ids.has(cue.id) ? { ...cue, start: cue.start + seconds, end: cue.end + seconds } : cue); }
        break;
    }
    default: fail('operación no admitida.');
    }
    document.timeline.captions = captions;
    return validateDocument(document);
}

/** A selected run owns its WHOLE source window. Only output seams between disjoint runs use dominance. */
export function remapCaptions(source, sourceTimeline, output, outputTimeline) {
    if (!source.timeline.captions) return undefined;
    const sourceIndices = new Map(source.pages.map((page, i) => [page.id, i]));
    const runs = [];
    for (const page of outputTimeline.pages) {
        const sourceIndex = sourceIndices.get(page.id), previous = runs.at(-1);
        if (previous && sourceIndex === previous.lastSourceIndex + 1) { previous.last = page; previous.lastSourceIndex = sourceIndex; }
        else runs.push({ first: page, last: page, sourceStart: sourceTimeline.pages[sourceIndex].start, lastSourceIndex: sourceIndex });
    }
    const reserved = new Set();
    const collect = elements => elements.forEach(element => { reserved.add(element.id); if (element.type === 'group') collect(element.elements); });
    output.pages.forEach(page => { reserved.add(page.id); collect(page.elements); });
    for (const track of output.timeline.audio_tracks) reserved.add(track.id);
    const duration = output.pages.reduce((total, page) => total + page.duration - (page.transition?.duration ?? 0), 0);
    const cues = [];
    for (const [runIndex, run] of runs.entries()) {
        const incoming = runs[runIndex - 1]?.last.transition;
        const outgoing = runIndex < runs.length - 1 ? run.last.transition : null;
        const from = Math.max(run.first.start, incoming?.duration > 0 ? (incoming.start + incoming.end) / 2 : run.first.start);
        const to = Math.min(duration, outgoing?.duration > 0 ? (outgoing.start + outgoing.end) / 2 : run.last.end);
        for (const [cueIndex, cue] of source.timeline.captions.cues.entries()) {
            const start = Math.max(from, run.first.start + cue.start - run.sourceStart);
            const end = Math.min(to, run.first.start + cue.end - run.sourceStart);
            if (start >= end) continue;
            let id = `cap_${runIndex}_${cueIndex}`, suffix = 0;
            while (reserved.has(id)) id = `cap_${runIndex}_${cueIndex}_${++suffix}`;
            reserved.add(id); cues.push({ ...cue, id, start, end });
        }
    }
    return validateCaptions({ style: source.timeline.captions.style, cues }, duration, []);
}
