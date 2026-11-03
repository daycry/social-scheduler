import { createFromTemplate } from './templates';
const cache = new Map();
const waiting = [];
let active = 0;
const drain = () => {
    while (active < 2 && waiting.length) {
        const { work, resolve, reject } = waiting.shift();
        active++;
        work().then(resolve, reject).finally(() => { active--; drain(); });
    }
};

/** Real document thumbnails, with two temporary canvases at most. */
export function templatePreview(key, kit = {}) {
    const styles = { palette: kit?.palette ?? [], fonts: kit?.fonts ?? [] };
    const identity = JSON.stringify([key, styles]);
    if (cache.has(identity)) return cache.get(identity);
    const pending = new Promise((resolve, reject) => {
        waiting.push({ resolve, reject, work: async () => {
            const { exportPage } = await import('./exports');
            const document = createFromTemplate(key, styles);
            return exportPage(document, document.pages[0].id, [], { format: 'webp', scale: Math.max(0.1, Math.min(0.3, 360 / Math.max(document.width, document.height))), quality: 0.85 });
        } });
        drain();
    });
    cache.set(identity, pending);
    pending.catch(() => cache.delete(identity));
    if (cache.size > 40) cache.delete(cache.keys().next().value);
    return pending;
}
