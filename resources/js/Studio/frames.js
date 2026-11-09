/** Selected-page frame times are local document seconds, never rounded export-frame bounds. */
export function frameTimes(document, pageIds, input = []) {
    if (!Array.isArray(input) || input.length > 100) throw new Error('frame_times debe ser una lista de hasta 100 fotogramas.');
    if (document.schema_version !== 2 && input.length) throw new Error('Los fotogramas temporales requieren un documento v2.');
    const pages = new Map(document.pages.filter(page => pageIds.includes(page.id)).map(page => [page.id, page]));
    if (pageIds.some(id => typeof id !== 'string' || !pages.has(id))) throw new Error('La página seleccionada no está disponible.');
    const times = new Map([...pages.keys()].map(id => [id, 0]));
    const seen = new Set();
    for (const item of input) {
        if (!item || typeof item !== 'object' || Array.isArray(item) || ![Object.prototype, null].includes(Object.getPrototypeOf(item))
            || !Object.hasOwn(item, 'page_id') || !Object.hasOwn(item, 'time') || Object.keys(item).some(key => !['page_id', 'time'].includes(key))
            || typeof item.page_id !== 'string' || !pages.has(item.page_id) || seen.has(item.page_id)
            || !Number.isFinite(item.time) || item.time < 0 || item.time >= pages.get(item.page_id).duration) {
            throw new Error('Indica una página seleccionada sin repetir y un segundo local dentro de su duración.');
        }
        seen.add(item.page_id); times.set(item.page_id, item.time);
    }
    return times;
}

/** Keep geometry unchanged, filtering only the inherited, semi-open temporal window. */
export function frameElements(elements, time, pageDuration) {
    const result = [];
    for (const element of elements) {
        const window = element.timing ?? { start: 0, duration: pageDuration };
        if (element.visible === false || time < window.start || time >= window.start + window.duration) continue;
        if (element.type !== 'group') result.push(element);
        else {
            const children = frameElements(element.elements, time, pageDuration);
            if (children.length) result.push({ ...element, elements: children });
        }
    }
    return result;
}
