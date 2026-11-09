import {
    Canvas, StaticCanvas, Rect, Ellipse, Triangle, Line, Path, Textbox, Group, ActiveSelection,
    FabricImage, Gradient, Shadow, PencilBrush, Point, LayoutManager, FixedLayout, util, filters,
} from 'fabric';
import { blankDocument, cloneDocument, findPage, newElement, newId, normalizeFill, renameElements, validateDocument, StudioDocumentError } from './document.js';
import { createStudioFontRegistry, ensureStudioFonts, studioFontFamily } from './fonts.js';
import { clipMediaState, restrictElementWindow } from './timeline.js';
import { createChartFabric } from './chart-renderer.js';
import { assertChartDocumentBudget } from './chart-scene.js';
import { createConnectorFabric, createTableFabric } from './board-objects.js';
import { anchorPoints, normalizeConnector, routePoints } from './board.js';

const clone = value => JSON.parse(JSON.stringify(value));
const round = value => Math.round((Number(value) || 0) * 1e6) / 1e6;
const activeGroup = object => object.group instanceof ActiveSelection;
const assetMap = assets => assets instanceof Map ? assets : new Map((Array.isArray(assets) ? assets : Object.values(assets ?? {})).map(asset => [Number(asset.id), asset]));

export function videoPlaybackRange(video, options = {}) {
    const duration = Number.isFinite(video.duration) ? Math.max(0, video.duration) : 86400;
    const start = Math.min(Math.max(0, options.trimStart ?? 0), Math.max(0, duration - 0.01));
    const end = Math.max(start, Math.min(duration, options.trimEnd ?? duration));
    return { start, end };
}

export async function seekVideoFrame(video, seconds, { timeout = 10000, signal, positionTolerance = 0.01, endTolerance = 0.01 } = {}) {
    if (signal?.aborted) throw new DOMException('Carga cancelada', 'AbortError');
    const value = Number(seconds);
    if (!Number.isFinite(value)) throw new StudioDocumentError('Selecciona un tiempo de vídeo válido.');
    const seek = Math.min(Math.max(0, value), Number.isFinite(video.duration) ? Math.max(0, video.duration - endTolerance) : value);
    if (Math.abs(video.currentTime - seek) < positionTolerance && !video.seeking) return;
    await new Promise((resolve, reject) => {
        const cleanup = () => { clearTimeout(timer); video.removeEventListener('seeked', ready); video.removeEventListener('error', failed); signal?.removeEventListener('abort', cancelled); };
        const ready = () => { cleanup(); resolve(); };
        const failed = () => { cleanup(); reject(new StudioDocumentError('No se pudo preparar el fotograma del vídeo.')); };
        const cancelled = () => { cleanup(); reject(new DOMException('Carga cancelada', 'AbortError')); };
        const timer = setTimeout(failed, timeout);
        video.addEventListener('seeked', ready, { once: true });
        video.addEventListener('error', failed, { once: true });
        signal?.addEventListener('abort', cancelled, { once: true });
        try { video.currentTime = seek; } catch { failed(); }
    });
}

function toFabricFill(fill, width, height) {
    if (typeof fill === 'string') return fill;
    const value = normalizeFill(fill);
    const radians = value.angle * Math.PI / 180;
    const extent = (Math.abs(Math.cos(radians)) * width + Math.abs(Math.sin(radians)) * height) / 2;
    const dx = Math.cos(radians) * extent;
    const dy = Math.sin(radians) * extent;
    return new Gradient({ type: 'linear', gradientUnits: 'pixels', coords: { x1: width / 2 - dx, y1: height / 2 - dy, x2: width / 2 + dx, y2: height / 2 + dy }, colorStops: value.stops.map(stop => ({ offset: stop.offset, color: stop.color })) });
}

function objectFill(object) {
    if (typeof object.fill === 'string' && object.fill.startsWith('#')) return object.fill;
    if (object.fill instanceof Gradient) {
        const c = object.fill.coords;
        return { type: 'linear', angle: round(Math.atan2(c.y2 - c.y1, c.x2 - c.x1) * 180 / Math.PI), stops: object.fill.colorStops.map(stop => ({ offset: stop.offset, color: stop.color })) };
    }
    return '#00000000';
}

function commonOptions(element) {
    return {
        originX: 'left', originY: 'top', left: element.x, top: element.y, width: element.width, height: element.height,
        scaleX: element.scaleX ?? 1, scaleY: element.scaleY ?? 1, angle: element.rotation ?? 0,
        skewX: element.skewX ?? 0, skewY: element.skewY ?? 0, flipX: element.flipX ?? false, flipY: element.flipY ?? false,
        opacity: element.opacity ?? 1, visible: element.visible !== false, fill: toFabricFill(element.fill ?? '#111827', element.width, element.height),
        stroke: element.stroke || null, strokeWidth: element.strokeWidth ?? 0,
        shadow: element.shadow ? new Shadow(element.shadow) : null,
        cornerColor: '#ffffff', cornerStrokeColor: '#7c3aed', borderColor: '#7c3aed', cornerStyle: 'circle', transparentCorners: false, cornerSize: 10,
        objectCaching: element.type !== 'video',
    };
}

function setIdentity(object, element) {
    object.studioId = element.id;
    object.studioType = element.type;
    object.studioName = element.name ?? '';
    object.studioTiming = element.timing == null ? null : clone(element.timing);
    object.studioAssetId = element.asset_id;
    object.studioFontId = element.font_id;
    object.studioFontFamily = element.fontFamily;
    object.studioMask = element.mask == null ? null : clone(element.mask);
    object.studioFilters = clone(element.filters ?? {});
    object.studioLocked = Boolean(element.locked);
    object.studioVideoOptions = element.type === 'video' ? { trimStart: element.trimStart ?? 0, trimEnd: element.trimEnd ?? null, volume: element.volume ?? 1, muted: Boolean(element.muted) } : null;
    if (object.studioVideoOptions) for (const key of ['playbackRate', 'sourceOffset', 'loop', 'gainPoints']) {
        if (Object.hasOwn(element, key)) object.studioVideoOptions[key] = clone(element[key]);
    }
    if (object.studioVideo) {
        object.studioVideo.studioVideoOptions = object.studioVideoOptions;
        object.studioVideo.volume = object.studioVideoOptions.volume;
        object.studioVideo.muted = object.studioVideoOptions.muted;
        object.studioVideo.playbackRate = object.studioVideoOptions.playbackRate ?? 1;
    }
    setLock(object, element.locked);
    return object;
}

function setLock(object, locked) {
    object.studioLocked = Boolean(locked);
    object.set({ lockMovementX: Boolean(locked), lockMovementY: Boolean(locked), lockScalingX: Boolean(locked), lockScalingY: Boolean(locked), lockRotation: Boolean(locked), lockSkewingX: Boolean(locked), lockSkewingY: Boolean(locked), hasControls: !locked, selectable: !locked, evented: !locked });
}

function absoluteTransform(object) {
    const matrix = object.calcTransformMatrix();
    const decomposition = util.qrDecompose(matrix);
    const sample = new Rect({ width: object.width, height: object.height, strokeWidth: object.strokeWidth, originX: 'left', originY: 'top', angle: decomposition.angle, scaleX: decomposition.scaleX, scaleY: decomposition.scaleY, skewX: decomposition.skewX, skewY: 0 });
    sample.setPositionByOrigin(new Point(decomposition.translateX, decomposition.translateY), 'center', 'center');
    return { x: sample.left, y: sample.top, rotation: sample.angle, scaleX: sample.scaleX, scaleY: sample.scaleY, skewX: sample.skewX, skewY: sample.skewY, flipX: sample.flipX, flipY: sample.flipY };
}

/** Serialize only our known types. A temporary ActiveSelection is not a persisted group. */
export function studioObjectToElement(object, { absolute = false } = {}) {
    const type = object.studioType ?? (object instanceof Textbox ? 'text' : object instanceof Group ? 'group' : object instanceof Ellipse ? 'ellipse' : object instanceof Triangle ? 'triangle' : object instanceof Line ? 'line' : object instanceof Path ? 'path' : object instanceof FabricImage ? 'image' : 'rect');
    const transform = absolute && object.group ? absoluteTransform(object) : { x: object.left, y: object.top, rotation: object.angle, scaleX: object.scaleX, scaleY: object.scaleY, skewX: object.skewX, skewY: object.skewY, flipX: object.flipX, flipY: object.flipY };
    const element = newElement(type, {
        id: object.studioId ?? newId(type), name: object.studioName || (type === 'text' ? object.text.slice(0, 40) || 'Texto' : 'Elemento'),
        ...Object.fromEntries(Object.entries(transform).map(([key, value]) => [key, typeof value === 'number' ? round(value) : value])),
        width: round(object.width), height: round(object.height), opacity: object.opacity ?? 1, visible: object.visible !== false,
        fill: objectFill(object), stroke: object.stroke || '', strokeWidth: round(object.strokeWidth), locked: Boolean(object.studioLocked),
    });
    if (object.shadow) element.shadow = { color: object.shadow.color, blur: object.shadow.blur, offsetX: object.shadow.offsetX, offsetY: object.shadow.offsetY };
    if (object.studioTiming != null) element.timing = clone(object.studioTiming);
    if (type === 'text') Object.assign(element, { text: object.text, fontFamily: object.fontFamily, fontSize: object.fontSize, fontWeight: String(object.fontWeight) === 'bold' || Number(object.fontWeight) >= 600 ? 'bold' : 'normal', fontStyle: object.fontStyle === 'italic' ? 'italic' : 'normal', textAlign: object.textAlign, underline: Boolean(object.underline), lineHeight: object.lineHeight, charSpacing: object.charSpacing });
    if (type === 'text' && object.studioFontId != null) { element.font_id = object.studioFontId; element.fontFamily = object.studioFontFamily; }
    if (type === 'rect') element.radius = object.rx ?? 0;
    if (type === 'line') Object.assign(element, { x1: object.x1, y1: object.y1, x2: object.x2, y2: object.y2 });
    if (type === 'path') element.path = clone(object.path);
    if (type === 'image' || type === 'video') Object.assign(element, { asset_id: object.studioAssetId, cropX: object.cropX ?? 0, cropY: object.cropY ?? 0, filters: clone(object.studioFilters ?? {}) });
    if (type === 'image' && object.studioMask != null) element.mask = clone(object.studioMask);
    if (type === 'video') Object.assign(element, object.studioVideoOptions);
    if (type === 'chart') element.chart = clone(object.studioChart);
    if (type === 'table') element.table = clone(object.studioTable);
    if (type === 'connector') {
        const { from, to, route, arrow, points } = object.studioConnector;
        Object.assign(element, { from, to, route, arrow, points: clone(points) });
    }
    if (type === 'group') element.elements = object.getObjects().map(child => studioObjectToElement(child));
    return element;
}

function sourceForAsset(asset) {
    const source = asset?.studio_url ?? asset?.preview_url;
    if (!source) throw new StudioDocumentError('El recurso no tiene una vista previa disponible. Vuelve a subirlo o selecciónalo desde la marca.');
    const base = globalThis.location?.origin ?? 'http://localhost';
    const url = new URL(source, base);
    if (!['http:', 'https:'].includes(url.protocol) || url.origin !== base) throw new StudioDocumentError('El recurso necesita una URL privada de la aplicación para editarse y exportarse.');
    return url.href;
}

function filterObjects(values = {}) {
    const output = [];
    if (values.brightness) output.push(new filters.Brightness({ brightness: values.brightness }));
    if (values.contrast) output.push(new filters.Contrast({ contrast: values.contrast }));
    if (values.saturation) output.push(new filters.Saturation({ saturation: values.saturation }));
    if (values.blur) output.push(new filters.Blur({ blur: values.blur }));
    if (values.grayscale) output.push(new filters.Grayscale());
    if (values.sepia) output.push(new filters.Sepia());
    if (values.invert) output.push(new filters.Invert());
    return output;
}

function imageMask(element) {
    if (!element.mask) return undefined;
    const options = { originX: 'center', originY: 'center', left: 0, top: 0, width: element.width, height: element.height, fill: '#000000', strokeWidth: 0 };
    if (element.mask.type === 'ellipse') return new Ellipse({ ...options, rx: element.width / 2, ry: element.height / 2 });
    const radius = Math.min(element.mask.radius ?? 0, element.width / 2, element.height / 2);
    return new Rect({ ...options, rx: radius, ry: radius });
}

function checkMediaCrop(element, source) {
    const width = source?.naturalWidth || source?.videoWidth || source?.width;
    const height = source?.naturalHeight || source?.videoHeight || source?.height;
    if (((element.cropX ?? 0) > 0 && element.cropX >= width) || ((element.cropY ?? 0) > 0 && element.cropY >= height)) throw new StudioDocumentError('El origen del recorte debe estar dentro del recurso original.');
}

async function videoElement(asset, element, signal) {
    const video = document.createElement('video');
    video.preload = 'auto';
    video.playsInline = true;
    video.muted = true;
    video.src = sourceForAsset(asset);
    await new Promise((resolve, reject) => {
        const cleanup = () => { clearTimeout(timer); video.removeEventListener('loadeddata', ready); video.removeEventListener('error', failed); signal?.removeEventListener('abort', cancelled); };
        const ready = () => { cleanup(); resolve(); };
        const failed = () => { cleanup(); reject(new StudioDocumentError(`No se pudo cargar el vídeo ${asset.name ?? asset.id}.`)); };
        const cancelled = () => { cleanup(); video.pause(); video.removeAttribute('src'); reject(new DOMException('Carga cancelada', 'AbortError')); };
        const timer = setTimeout(failed, 20000);
        video.addEventListener('loadeddata', ready, { once: true });
        video.addEventListener('error', failed, { once: true });
        signal?.addEventListener('abort', cancelled, { once: true });
        if (signal?.aborted) cancelled();
        else video.load();
    });
    try {
        let poster = element.trimStart ?? 0;
        if (Object.hasOwn(element, 'playbackRate') || Object.hasOwn(element, 'sourceOffset')) {
            const source = clipMediaState(element, element.timing?.start ?? 0, video.duration, element.timing?.duration ?? video.duration);
            const end = element.trimEnd ?? video.duration;
            poster = Math.min(source.sourceTime, Math.max(element.trimStart ?? 0, end - 0.01));
        }
        if (poster > 0) await seekVideoFrame(video, poster, { signal });
    }
    catch (error) { video.pause(); video.removeAttribute('src'); video.load(); throw error; }
    video.volume = element.volume ?? 1;
    video.muted = Boolean(element.muted);
    video.width = video.videoWidth;
    video.height = video.videoHeight;
    return video;
}

/** No enlivenObjects/fromJSON: callers cannot select a Fabric class or execute serialized options. */
export async function elementToFabric(element, { assets = new Map(), signal, videos = [], fontRegistry, chartResources } = {}) {
    const options = commonOptions(element);
    let object;
    switch (element.type) {
        case 'chart': object = await createChartFabric(element, { chartResources, fontRegistry, signal, options }); break;
        case 'table': object = createTableFabric(element, options); break;
        case 'connector': object = createConnectorFabric(element, options); break;
        case 'rect': object = new Rect({ ...options, rx: element.radius ?? 0, ry: element.radius ?? 0 }); break;
        case 'ellipse': object = new Ellipse({ ...options, rx: element.width / 2, ry: element.height / 2 }); break;
        case 'triangle': object = new Triangle(options); break;
        case 'line': object = new Line([element.x1 ?? 0, element.y1 ?? 0, element.x2 ?? element.width, element.y2 ?? element.height], options); break;
        case 'path': object = new Path(element.path, { ...options, strokeLineCap: 'round', strokeLineJoin: 'round' }); break;
        case 'text': {
            if (element.font_id != null && !fontRegistry) throw new StudioDocumentError(`Prepara la fuente privada ${element.font_id} antes de dibujar el texto.`);
            object = new Textbox(element.text ?? '', { ...options, fontFamily: fontRegistry ? fontRegistry.family(element) : element.fontFamily ?? 'Arial', fontSize: element.fontSize ?? 64, fontWeight: element.fontWeight ?? 'normal', fontStyle: element.fontStyle ?? 'normal', textAlign: element.textAlign ?? 'left', underline: Boolean(element.underline), lineHeight: element.lineHeight ?? 1.2, charSpacing: element.charSpacing ?? 0, splitByGrapheme: false }); break;
        }
        case 'image':
        case 'video': {
            const asset = assetMap(assets).get(Number(element.asset_id));
            if (!asset || asset.status === 'invalido' || asset.status === 'pendiente') throw new StudioDocumentError(`El recurso ${element.asset_id} ya no está disponible en esta marca.`);
            if (element.type === 'video') {
                const video = await videoElement(asset, element, signal);
                videos.push(video);
                object = new FabricImage(video, options);
                object.studioVideo = video;
            } else {
                const timeout = new AbortController();
                const timer = setTimeout(() => timeout.abort(), 20000);
                const cancel = () => timeout.abort();
                signal?.addEventListener('abort', cancel, { once: true });
                try { object = await FabricImage.fromURL(sourceForAsset(asset), { signal: timeout.signal }, options); }
                catch (error) { if (signal?.aborted) throw error; throw new StudioDocumentError(`No se pudo cargar la imagen ${asset.name ?? asset.id}.`); }
                finally { clearTimeout(timer); signal?.removeEventListener('abort', cancel); }
            }
            try { checkMediaCrop(element, object.getElement()); }
            catch (error) { object.dispose(); throw error; }
            object.set({ width: element.width, height: element.height, cropX: element.cropX ?? 0, cropY: element.cropY ?? 0 });
            if (element.type === 'image') object.set({ clipPath: imageMask(element) });
            object.filters = filterObjects(element.filters);
            if (object.filters.length) object.applyFilters();
            break;
        }
        case 'group': {
            const children = [];
            for (const child of element.elements) children.push(await elementToFabric(child, { assets, signal, videos, fontRegistry, chartResources }));
            const positions = children.map(child => ({ left: child.left, top: child.top }));
            object = new Group(children, { ...options, layoutManager: new LayoutManager(new FixedLayout()), subTargetCheck: false, interactive: false });
            // Persisted children are already in the group's local coordinate plane. Initial Fabric
            // layout recenters a partial temporal set; restore its declared positions after grouping.
            children.forEach((child, index) => { child.set(positions[index]); child.setCoords(); });
            break;
        }
        default: throw new StudioDocumentError(`El tipo ${element.type} no está admitido.`);
    }
    object.setCoords();
    return setIdentity(object, element);
}

export async function waitForStudioFonts(elements, { timeout = 20000, signal, fonts = [], registry } = {}) {
    await (registry ? registry.ensure(elements, { signal, timeout }) : ensureStudioFonts(elements, fonts, { signal, timeout }));
    if (!globalThis.document?.fonts) return;
    const fontDeclarations = new Set();
    const collect = items => items.forEach(element => {
        if (element.type === 'text' || element.type === 'chart') {
            const ownStyle = element.type === 'chart' ? element.chart.style ?? {} : element;
            const resolved = registry?.style(element) ?? ownStyle;
            const family = registry ? registry.family(element) : ownStyle.font_id == null ? ownStyle.fontFamily ?? 'Arial' : studioFontFamily(assetMap(fonts).get(Number(ownStyle.font_id)));
            fontDeclarations.add(`${resolved.fontStyle ?? 'normal'} ${resolved.fontWeight ?? 'normal'} ${ownStyle.fontSize ?? (element.type === 'chart' ? 16 : 64)}px "${family}"`);
        }
        if (element.type === 'group') collect(element.elements);
    });
    collect(elements);
    if (signal?.aborted) throw new DOMException('Carga cancelada', 'AbortError');
    await new Promise((resolve, reject) => {
        const cleanup = () => { clearTimeout(timer); signal?.removeEventListener('abort', cancelled); };
        const cancelled = () => { cleanup(); reject(new DOMException('Carga cancelada', 'AbortError')); };
        const timer = setTimeout(() => { cleanup(); reject(new StudioDocumentError('No se pudieron preparar las fuentes del diseño. Vuelve a intentarlo.')); }, timeout);
        signal?.addEventListener('abort', cancelled, { once: true });
        Promise.all([...fontDeclarations].map(declaration => document.fonts.load(declaration))).then(() => document.fonts.ready).then(() => { cleanup(); resolve(); }, error => { cleanup(); reject(error); });
    });
}

const cleanVideos = videos => videos.splice(0).forEach(video => { video.pause(); video.removeAttribute('src'); video.load(); });

export async function renderStudioPage(documentData, pageId, assets = [], fonts = [], { renderScale = 1, chartResources, signal } = {}) {
    if (signal?.aborted) throw new DOMException('Carga cancelada', 'AbortError');
    const doc = validateDocument(documentData);
    const page = findPage(doc, pageId);
    if (!page) throw new StudioDocumentError('La página no está disponible.');
    // Export contexts materialize only selected pages. Count only this drawn page;
    // exportDesign owns the one-time aggregate preflight of the selected work.
    await assertChartDocumentBudget({ ...doc, pages: [page] }, chartResources, { signal });
    const width = Math.round(doc.width * renderScale), height = Math.round(doc.height * renderScale);
    if (!Number.isFinite(renderScale) || renderScale < 0.1 || renderScale > 4 || width < 1 || height < 1 || width > 16000 || height > 16000
        || width * height > 64000000) throw new StudioDocumentError('La escala o resolución de render no está admitida.');
    const fontRegistry = createStudioFontRegistry(fonts);
    try { await waitForStudioFonts(page.elements, { registry: fontRegistry, signal }); }
    catch (error) { fontRegistry.dispose(); throw error; }
    const element = document.createElement('canvas');
    const canvas = new StaticCanvas(element, { width, height, enableRetinaScaling: false, renderOnAddRemove: false, backgroundColor: toFabricFill(page.background, doc.width, doc.height) });
    canvas.setViewportTransform([renderScale, 0, 0, renderScale, 0, 0]);
    const videos = [];
    try {
        for (const layer of page.elements) canvas.add(await elementToFabric(layer, { assets, videos, fontRegistry, chartResources, signal }));
        if (signal?.aborted) throw new DOMException('Carga cancelada', 'AbortError');
        canvas.renderAll();
        return { canvas, fontFaces: () => fontRegistry.svgFaces(), dispose: async () => { cleanVideos(videos); await canvas.dispose(); fontRegistry.dispose(); } };
    } catch (error) {
        cleanVideos(videos);
        await canvas.dispose();
        fontRegistry.dispose();
        throw error;
    }
}

export async function createStudioCanvas(canvasElement, { document: initialDocument, pageId, assets = [], fonts = [], chartResources, onChange = () => {}, onSelection = () => {} } = {}) {
    let documentData = validateDocument(initialDocument ?? blankDocument());
    let currentPage = findPage(documentData, pageId) ?? documentData.pages[0];
    let resources = assetMap(assets);
    const fontRegistry = createStudioFontRegistry(fonts);
    let scale = 1;
    let loading = false;
    let destroyed = false;
    let generation = 0;
    let mutationVersion = 0;
    const editEpochs = new WeakMap();
    let controller = null;
    let clipboard = [];
    let playback = null;
    const videos = [];
    const canvas = new Canvas(canvasElement, { width: documentData.width, height: documentData.height, preserveObjectStacking: true, enableRetinaScaling: true, selectionColor: '#7c3aed18', selectionBorderColor: '#7c3aed', uniformScaling: true, stopContextMenu: true });
    canvas.upperCanvasEl.setAttribute('tabindex', '0');
    canvas.upperCanvasEl.setAttribute('aria-label', 'Lienzo de diseño. Usa el panel Capas para seleccionar y editar con teclado.');

    const objects = () => canvas.getObjects();
    const pruneVideos = () => {
        const retained = new Set();
        const collect = items => items.forEach(object => { if (object.studioVideo) retained.add(object.studioVideo); if (object instanceof Group) collect(object.getObjects()); });
        collect(objects());
        const removed = videos.filter(video => !retained.has(video));
        for (let i = videos.length - 1; i >= 0; i--) if (!retained.has(videos[i])) videos.splice(i, 1);
        cleanVideos(removed);
    };
    const selectedObjects = () => canvas.getActiveObjects();
    const serialize = () => objects().map(object => studioObjectToElement(object, { absolute: true }));
    const selection = () => selectedObjects().map(object => studioObjectToElement(object, { absolute: true }));
    const notifySelection = () => { if (!loading && !destroyed) onSelection(selection()); };
    const isConnector = object => object.studioType === 'connector' && object.studioConnector;
    const rectOf = object => { const box = object.getBoundingRect(); return { left: box.left, top: box.top, width: box.width, height: box.height }; };
    /** Keep bound connectors glued to their shapes (and detach the ones whose shape was deleted). */
    function reroute() {
        const byId = new Map(objects().filter(object => !isConnector(object)).map(object => [object.studioId, object]));
        const active = new Set(selectedObjects());
        for (const object of objects().filter(isConnector)) {
            if (active.has(object) || object.studioLocked) continue;
            const current = studioObjectToElement(object);
            const from = byId.get(current.from);
            const to = byId.get(current.to);
            const next = { ...current, from: from ? current.from : null, to: to ? current.to : null };
            const absolute = current.points.map(([x, y]) => ({ x: x + current.x, y: y + current.y }));
            let start = absolute[0];
            let end = absolute.at(-1);
            let horizontal = Math.abs(absolute[1].x - absolute[0].x) >= Math.abs(absolute[1].y - absolute[0].y);
            if (from && to) ({ start, end, horizontal } = anchorPoints(rectOf(from), rectOf(to)));
            else if (from || to) {
                const bound = rectOf(from ?? to);
                const free = from ? end : start;
                const placed = anchorPoints(from ? bound : { left: free.x, top: free.y, width: 0, height: 0 }, from ? { left: free.x, top: free.y, width: 0, height: 0 } : bound);
                if (from) ({ start, horizontal } = placed); else ({ end, horizontal } = placed);
            }
            const points = routePoints(start, end, current.route, horizontal);
            const same = next.from === current.from && next.to === current.to && points.length === absolute.length
                && points.every(([x, y], i) => Math.abs(x - absolute[i].x) < 0.5 && Math.abs(y - absolute[i].y) < 0.5);
            if (same) continue;
            const frame = normalizeConnector({ ...next, x: 0, y: 0, points });
            const replacement = createConnectorFabric({ ...next, ...frame, points: frame.points }, commonOptions({ ...next, ...frame }));
            setIdentity(replacement, { ...next, ...frame });
            const position = objects().indexOf(object);
            canvas.remove(object); canvas.insertAt(position, replacement); object.dispose();
        }
    }
    const changed = reason => {
        if (loading || destroyed) return;
        reroute();
        currentPage.elements = serialize();
        documentData.pages = documentData.pages.map(page => page.id === currentPage.id ? clone(currentPage) : page);
        mutationVersion++;
        fontRegistry.releaseUnused(currentPage.elements);
        onChange(clone(currentPage.elements), { pageId: currentPage.id, background: clone(currentPage.background), width: documentData.width, height: documentData.height, reason });
        notifySelection();
    };
    const ready = () => { if (loading || destroyed) throw new StudioDocumentError('Espera a que termine de cargar el lienzo.'); };
    const choose = items => {
        canvas.discardActiveObject();
        if (items.length === 1) canvas.setActiveObject(items[0]);
        else if (items.length > 1) canvas.setActiveObject(new ActiveSelection(items, { canvas, originX: 'left', originY: 'top' }));
        canvas.requestRenderAll();
        notifySelection();
    };
    const checked = element => validateDocument({ schema_version: documentData.schema_version, width: documentData.width, height: documentData.height,
        ...(documentData.schema_version === 2 ? { timeline: { fps: documentData.timeline.fps, audio_tracks: [] } } : {}),
        pages: [{ id: newId('validation_page'), background: '#ffffff', duration: currentPage.duration ?? 5, elements: [element] }] }).pages[0].elements[0];

    async function commitNextBudget(nextElements, request, commit, live = () => true) {
        // The callback publishes in THIS continuation after the last version check.
        // Returning a permit to an awaiting caller would reopen a microtask race.
        let version;
        do {
            if (destroyed || request !== generation || !live()) return false;
            version = mutationVersion;
            const snapshot = { ...documentData, pages: documentData.pages.map(page => page.id === currentPage.id ? { ...currentPage, elements: nextElements() } : page) };
            await assertChartDocumentBudget(validateDocument(snapshot), chartResources, { signal: controller?.signal });
        } while (version !== mutationVersion);
        if (destroyed || request !== generation || !live()) return false;
        commit();
        return true;
    }

    function applyObjectPatch(object, candidate) {
        const options = commonOptions(candidate);
        // Tables and connectors lay themselves out (row heights, arrow heads), so their frame is not patched.
        if (candidate.type === 'table' || candidate.type === 'connector') for (const key of ['left', 'top', 'width', 'height']) delete options[key];
        if (object instanceof Textbox) Object.assign(options, { text: candidate.text, fontFamily: fontRegistry.family(candidate), fontSize: candidate.fontSize, fontWeight: candidate.fontWeight, fontStyle: candidate.fontStyle, textAlign: candidate.textAlign, underline: candidate.underline, lineHeight: candidate.lineHeight, charSpacing: candidate.charSpacing });
        if (object instanceof Ellipse) Object.assign(options, { rx: candidate.width / 2, ry: candidate.height / 2 });
        if (candidate.type === 'rect') Object.assign(options, { rx: candidate.radius ?? 0, ry: candidate.radius ?? 0 });
        if (object instanceof FabricImage) {
            Object.assign(options, { cropX: candidate.cropX, cropY: candidate.cropY });
            if (candidate.type === 'image') options.clipPath = imageMask(candidate);
            object.filters = filterObjects(candidate.filters); object.applyFilters();
        }
        object.set(options); setIdentity(object, candidate); object.setCoords();
    }

    async function loadPage(pageOrId) {
        if (destroyed) return;
        const page = typeof pageOrId === 'string' ? findPage(documentData, pageOrId) : pageOrId;
        if (!page) throw new StudioDocumentError('La página seleccionada no está disponible.');
        controller?.abort();
        controller = new AbortController();
        const signal = controller.signal;
        const request = ++generation;
        loading = true;
        adapter.pause();
        const nextVideos = [];
        const nextObjects = [];
        try {
            await assertChartDocumentBudget(documentData, chartResources, { signal });
            await waitForStudioFonts(page.elements, { signal, registry: fontRegistry });
            for (const element of page.elements) nextObjects.push(await elementToFabric(element, { assets: resources, signal, videos: nextVideos, fontRegistry, chartResources }));
            if (request !== generation || destroyed) { cleanVideos(nextVideos); nextObjects.forEach(object => object.dispose()); return; }
            canvas.discardActiveObject();
            cleanVideos(videos);
            videos.push(...nextVideos);
            const previousObjects = objects();
            canvas.remove(...previousObjects);
            previousObjects.forEach(object => object.dispose());
            currentPage = clone(page);
            canvas.backgroundColor = toFabricFill(page.background, documentData.width, documentData.height);
            canvas.add(...nextObjects);
            fontRegistry.releaseUnused(page.elements);
            adapter.zoom(scale);
            canvas.renderAll();
        } catch (error) {
            cleanVideos(nextVideos);
            nextObjects.forEach(object => object.dispose());
            if (request === generation && !destroyed) throw error;
        } finally {
            if (request === generation) { loading = false; notifySelection(); }
        }
    }

    const adapter = {
        canvas,
        get loading() { return loading; },
        loadPage,
        async loadDocument(next, nextPageId) {
            documentData = validateDocument(next);
            return loadPage(findPage(documentData, nextPageId ?? currentPage.id) ?? documentData.pages[0]);
        },
        setAssets(next) { resources = assetMap(next); },
        setFonts(next) { fontRegistry.setFonts(next); },
        serializePage: serialize,
        selection,
        layers: serialize,
        select(ids) { ready(); const wanted = new Set(Array.isArray(ids) ? ids : [ids]); choose(objects().filter(object => wanted.has(object.studioId))); },
        deselect() { choose([]); },
        async addChart(chart, patch = {}) {
            ready();
            const request = generation;
            const element = checked(newElement('chart', { x: 40, y: 40, ...patch, chart }));
            await waitForStudioFonts([element], { registry: fontRegistry, signal: controller?.signal });
            if (destroyed || request !== generation) return;
            const object = await elementToFabric(element, { fontRegistry, chartResources, signal: controller?.signal });
            try { if (!await commitNextBudget(() => [...serialize(), element], request, () => { canvas.add(object); choose([object]); changed('add'); })) { object.dispose(); return; } }
            catch (error) { object.dispose(); throw error; }
            return element.id;
        },
        async addTable(patch = {}) {
            ready();
            const request = generation;
            const element = checked(newElement('table', { x: 80, y: 80, ...patch }));
            const object = await elementToFabric(element);
            try { if (!await commitNextBudget(() => [...serialize(), element], request, () => { canvas.add(object); choose([object]); changed('add'); })) { object.dispose(); return; } }
            catch (error) { object.dispose(); throw error; }
            return element.id;
        },
        /** With two shapes selected the connector is glued to both; otherwise it is a free arrow. */
        async addConnector(patch = {}) {
            ready();
            const request = generation;
            const pair = selectedObjects().filter(object => !isConnector(object)).slice(0, 2);
            let element = newElement('connector', { x: 120, y: 120, ...patch });
            if (pair.length === 2) {
                const { start, end, horizontal } = anchorPoints(rectOf(pair[0]), rectOf(pair[1]));
                const frame = normalizeConnector({ ...element, x: 0, y: 0, route: 'elbow', points: routePoints(start, end, 'elbow', horizontal) });
                element = { ...element, ...patch, from: pair[0].studioId, to: pair[1].studioId, route: 'elbow', ...frame, points: frame.points };
                delete element.commands;
            }
            element = checked(element);
            const object = await elementToFabric(element);
            try { if (!await commitNextBudget(() => [...serialize(), element], request, () => { canvas.add(object); choose([object]); changed('add'); })) { object.dispose(); return; } }
            catch (error) { object.dispose(); throw error; }
            return element.id;
        },
        async addText(value = 'Escribe tu texto', patch = {}) {
            ready();
            const element = checked(newElement('text', { x: 80, y: 80, width: Math.max(120, documentData.width - 160), text: value, ...patch }));
            const request = generation;
            await waitForStudioFonts([element], { registry: fontRegistry, signal: controller?.signal });
            if (destroyed || request !== generation) return;
            const object = await elementToFabric(element, { fontRegistry });
            try { if (!await commitNextBudget(() => [...serialize(), element], request, () => { canvas.add(object); choose([object]); changed('add'); })) { object.dispose(); return; } }
            catch (error) { object.dispose(); throw error; }
            return element.id;
        },
        async addShape(type = 'rect', patch = {}) {
            ready();
            const request = generation;
            if (!['rect', 'ellipse', 'triangle', 'line', 'path'].includes(type)) throw new StudioDocumentError('Selecciona una forma admitida.');
            const element = checked(newElement(type, patch));
            const object = await elementToFabric(element);
            try { if (!await commitNextBudget(() => [...serialize(), element], request, () => { canvas.add(object); choose([object]); changed('add'); })) { object.dispose(); return; } }
            catch (error) { object.dispose(); throw error; }
            return element.id;
        },
        async addAsset(asset, patch = {}) {
            ready(); resources.set(Number(asset.id), asset);
            const request = generation;
            const nextVideos = [];
            const naturalWidth = asset.width || 640;
            const naturalHeight = asset.height || 480;
            const fit = Math.min(1, (documentData.width * 0.8) / naturalWidth, (documentData.height * 0.8) / naturalHeight);
            const element = checked(newElement(asset.kind === 'video' ? 'video' : 'image', { asset_id: Number(asset.id), name: asset.name ?? 'Imagen', width: naturalWidth, height: naturalHeight, x: (documentData.width - naturalWidth * fit) / 2, y: (documentData.height - naturalHeight * fit) / 2, scaleX: fit, scaleY: fit, ...patch }));
            let object;
            try { object = await elementToFabric(element, { assets: resources, videos: nextVideos, signal: controller?.signal }); }
            catch (error) { cleanVideos(nextVideos); if (destroyed || request !== generation) return; throw error; }
            if (destroyed || request !== generation) { cleanVideos(nextVideos); object.dispose(); return; }
            try { if (!await commitNextBudget(() => [...serialize(), element], request, () => { videos.push(...nextVideos); canvas.add(object); choose([object]); changed('add'); })) { cleanVideos(nextVideos); object.dispose(); return; } }
            catch (error) { cleanVideos(nextVideos); object.dispose(); throw error; }
            return element.id;
        },
        async updateSelection(patch) {
            ready();
            const selected = selectedObjects();
            const request = generation;
            const epochs = selected.map(object => { const epoch = (editEpochs.get(object) ?? 0) + 1; editEpochs.set(object, epoch); return epoch; });
            const editable = object => selected.includes(object) && (!object.studioLocked || patch.locked === false);
            const live = () => selected.every((object, index) => objects().includes(object) && editEpochs.get(object) === epochs[index]);
            const candidateFor = object => checked({ ...studioObjectToElement(object, { absolute: true }), ...patch });
            const candidates = selected.map(candidateFor);
            selected.forEach((object, index) => { if (object instanceof FabricImage) checkMediaCrop(candidates[index], object.getElement()); });
            const replacements = new Map();
            try {
                await waitForStudioFonts(candidates, { registry: fontRegistry, signal: controller?.signal });
                for (let i = 0; i < selected.length; i++) {
                    const object = selected[i];
                    // Common edits retain non-chart objects and their media lifetimes.
                    if (editable(object) && ['chart', 'table', 'connector'].includes(object.studioType)) replacements.set(object, await elementToFabric(candidates[i], { assets: resources, fontRegistry, chartResources, signal: controller?.signal }));
                }
                const committed = await commitNextBudget(() => objects().map(object => editable(object) ? candidateFor(object) : studioObjectToElement(object, { absolute: true })), request, () => {
                    // Read latest transforms before publication; no awaits can split this commit.
                    const latest = new Map(selected.filter(editable).map(object => [object, candidateFor(object)]));
                    const keepSelection = selectedObjects().length === selected.length && selected.every(object => selectedObjects().includes(object));
                    const previousSelection = selectedObjects();
                    canvas.discardActiveObject();
                    const next = selected.map(object => {
                        if (!latest.has(object)) return object;
                        const replacement = replacements.get(object);
                        if (!replacement) { applyObjectPatch(object, latest.get(object)); return object; }
                        applyObjectPatch(replacement, latest.get(object));
                        const position = objects().indexOf(object);
                        canvas.remove(object); canvas.insertAt(position, replacement); object.dispose(); return replacement;
                    });
                    choose(keepSelection ? next : previousSelection.map(object => replacements.get(object) ?? object));
                    canvas.requestRenderAll(); changed('update');
                }, live);
                if (!committed) replacements.forEach(object => object.dispose());
            } catch (error) {
                replacements.forEach(object => object.dispose());
                if (destroyed || request !== generation) return;
                throw error;
            }
        },
        delete() {
            ready(); const selected = selectedObjects().filter(object => !object.studioLocked);
            if (!selected.length) return false;
            canvas.discardActiveObject(); canvas.remove(...selected); pruneVideos(); changed('delete'); canvas.requestRenderAll(); return true;
        },
        copy() { clipboard = selection(); return clone(clipboard); },
        async paste(elements = clipboard) {
            ready();
            const request = generation;
            const nextVideos = [];
            const clones = clone(elements);
            await waitForStudioFonts(clones, { registry: fontRegistry, signal: controller?.signal });
            renameElements(clones);
            for (const element of clones) { element.x += 24; element.y += 24; }
            const additions = [];
            try {
                for (const element of clones) additions.push(await elementToFabric(checked(element), { assets: resources, videos: nextVideos, signal: controller?.signal, fontRegistry, chartResources }));
            } catch (error) {
                cleanVideos(nextVideos); additions.forEach(object => object.dispose());
                if (destroyed || request !== generation) return;
                throw error;
            }
            if (destroyed || request !== generation) { cleanVideos(nextVideos); additions.forEach(object => object.dispose()); return; }
            try { if (!await commitNextBudget(() => [...serialize(), ...clones], request, () => { videos.push(...nextVideos); canvas.add(...additions); choose(additions); changed('paste'); })) { cleanVideos(nextVideos); additions.forEach(object => object.dispose()); return; } }
            catch (error) { cleanVideos(nextVideos); additions.forEach(object => object.dispose()); throw error; }
            return clones.map(element => element.id);
        },
        async duplicate() { return adapter.paste(selection()); },
        group() {
            ready(); const selected = selectedObjects();
            if (selected.length < 2 || selected.some(object => object.studioLocked)) return false;
            const ordered = objects().filter(object => selected.includes(object));
            const position = objects().indexOf(ordered[0]);
            canvas.discardActiveObject(); canvas.remove(...ordered);
            const group = new Group(ordered, { originX: 'left', originY: 'top', subTargetCheck: false, interactive: false });
            setIdentity(group, newElement('group', { name: 'Grupo' }));
            canvas.add(group); canvas.moveObjectTo(group, position); choose([group]); changed('group'); return group.studioId;
        },
        ungroup() {
            ready(); const groups = selectedObjects().filter(object => object.studioType === 'group' && object instanceof Group && !(object instanceof ActiveSelection) && !object.studioLocked);
            if (!groups.length) return false;
            const restricted = new Map();
            for (const group of groups) if (documentData.schema_version === 2 && group.studioTiming) {
                for (const child of group.getObjects()) restricted.set(child, checked(restrictElementWindow(studioObjectToElement(child), group.studioTiming,
                    { pageDuration: currentPage.duration, sourceDuration: child.studioVideo?.duration })));
            }
            canvas.discardActiveObject(); const released = [];
            for (const group of groups) {
                const position = objects().indexOf(group);
                const children = group.removeAll();
                canvas.remove(group); canvas.insertAt(position, ...children); released.push(...children);
                children.forEach(child => {
                    if (restricted.has(child)) {
                        const bounded = restricted.get(child);
                        child.set('visible', bounded.visible !== false);
                        setIdentity(child, bounded);
                        if (child.studioVideo) {
                            try {
                                const source = clipMediaState(bounded, bounded.timing?.start ?? 0, child.studioVideo.duration, currentPage.duration);
                                const end = bounded.trimEnd ?? child.studioVideo.duration;
                                const poster = Math.min(source.sourceTime, Math.max(bounded.trimStart ?? 0, end - 0.01));
                                if (Math.abs(child.studioVideo.currentTime - poster) > 1e-6) {
                                    child.studioVideo.addEventListener('seeked', () => child.canvas?.requestRenderAll(), { once: true });
                                    child.studioVideo.currentTime = poster;
                                }
                            } catch (error) { canvas.fire('studio:error', { error }); }
                        }
                    } else setLock(child, child.studioLocked);
                    child.setCoords();
                });
            }
            choose(released); changed('ungroup'); return true;
        },
        align(mode) {
            ready(); const selected = selectedObjects().filter(object => !object.studioLocked);
            if (!selected.length) return;
            canvas.discardActiveObject();
            for (const object of selected) {
                const bounds = object.getBoundingRect();
                const dx = mode === 'left' ? -bounds.left : mode === 'center' ? (documentData.width - bounds.width) / 2 - bounds.left : mode === 'right' ? documentData.width - bounds.width - bounds.left : 0;
                const dy = mode === 'top' ? -bounds.top : mode === 'middle' ? (documentData.height - bounds.height) / 2 - bounds.top : mode === 'bottom' ? documentData.height - bounds.height - bounds.top : 0;
                object.set({ left: object.left + dx, top: object.top + dy }); object.setCoords();
            }
            choose(selected); changed('align');
        },
        reorder(direction) {
            ready(); const selected = selectedObjects();
            canvas.discardActiveObject();
            const ordered = objects().filter(object => selected.includes(object));
            if (direction === 'back' || direction === 'forward') ordered.reverse();
            for (const object of ordered) {
                if (object.studioLocked) continue;
                if (direction === 'front') canvas.bringObjectToFront(object);
                else if (direction === 'forward') canvas.bringObjectForward(object);
                else if (direction === 'backward') canvas.sendObjectBackwards(object);
                else if (direction === 'back') canvas.sendObjectToBack(object);
            }
            choose(selected); changed('reorder');
        },
        lock(value) {
            ready();
            const selected = selectedObjects();
            const next = value ?? !selected.every(object => object.studioLocked);
            selected.forEach(object => { setLock(object, next); object.setCoords(); });
            canvas.requestRenderAll(); changed('lock');
        },
        flip(axis = 'x') {
            ready(); selectedObjects().filter(object => !object.studioLocked).forEach(object => { object.set(axis === 'y' ? 'flipY' : 'flipX', !(axis === 'y' ? object.flipY : object.flipX)); object.setCoords(); });
            canvas.requestRenderAll(); changed('flip');
        },
        zoom(value = 1) {
            scale = Math.max(0.05, Math.min(4, Number(value) || 1));
            canvas.setDimensions({ width: Math.round(documentData.width * scale), height: Math.round(documentData.height * scale) });
            canvas.setViewportTransform([scale, 0, 0, scale, 0, 0]); canvas.requestRenderAll(); return scale;
        },
        setBackground(fill) { ready(); currentPage.background = normalizeFill(fill); canvas.backgroundColor = toFabricFill(currentPage.background, documentData.width, documentData.height); canvas.requestRenderAll(); changed('background'); },
        setDrawing(enabled, color = '#111827', width = 8) { canvas.isDrawingMode = Boolean(enabled); if (enabled) { canvas.discardActiveObject(); canvas.freeDrawingBrush = new PencilBrush(canvas); canvas.freeDrawingBrush.color = color; canvas.freeDrawingBrush.width = Math.max(1, Math.min(200, width)); } },
        async play() {
            ready();
            const request = generation;
            const loop = () => {
                if (destroyed || request !== generation) return;
                for (const video of videos) {
                    const { end } = videoPlaybackRange(video, video.studioVideoOptions);
                    if (video.currentTime >= end) video.pause();
                }
                canvas.renderAll();
                playback = videos.some(video => !video.paused && !video.ended) ? requestAnimationFrame(loop) : null;
            };
            adapter.pause();
            try {
                await Promise.all(videos.map(async video => {
                    const { start, end } = videoPlaybackRange(video, video.studioVideoOptions);
                    if (video.currentTime < start || video.currentTime >= end - 0.01) await seekVideoFrame(video, start, { signal: controller?.signal });
                    if (!destroyed && request === generation) await video.play();
                }));
            } catch (error) { adapter.pause(); throw error; }
            loop();
        },
        pause() { if (playback != null) cancelAnimationFrame(playback); playback = null; videos.forEach(video => video.pause()); },
        async seek(seconds) {
            ready();
            await Promise.all(videos.map(video => seekVideoFrame(video, seconds, { signal: controller?.signal })));
            canvas.renderAll();
        },
        exportCanvas() { return canvas; },
        async destroy() { destroyed = true; ++generation; controller?.abort(); adapter.pause(); cleanVideos(videos); canvas.upperCanvasEl.removeEventListener('keydown', onKey); canvas.off(); await canvas.dispose(); fontRegistry.dispose(); },
    };

    const onKey = event => {
        if (canvas.getActiveObject()?.isEditing || /INPUT|TEXTAREA|SELECT/.test(event.target?.tagName) || event.target?.isContentEditable) return;
        if (event.key === 'Delete' || event.key === 'Backspace') { event.preventDefault(); adapter.delete(); }
        else if (event.key === 'Escape') { canvas.discardActiveObject(); notifySelection(); canvas.requestRenderAll(); }
        else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'a') { event.preventDefault(); choose(objects().filter(object => !object.studioLocked && object.visible)); }
        else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'c') { event.preventDefault(); adapter.copy(); }
        else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'v') { event.preventDefault(); adapter.paste().catch(error => canvas.fire('studio:error', { error })); }
        else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'd') { event.preventDefault(); adapter.duplicate().catch(error => canvas.fire('studio:error', { error })); }
        else if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
            event.preventDefault();
            const selected = selectedObjects(); canvas.discardActiveObject();
            const delta = event.shiftKey ? 10 : 1;
            selected.filter(object => !object.studioLocked).forEach(object => { object.set({ left: object.left + (event.key === 'ArrowLeft' ? -delta : event.key === 'ArrowRight' ? delta : 0), top: object.top + (event.key === 'ArrowUp' ? -delta : event.key === 'ArrowDown' ? delta : 0) }); object.setCoords(); });
            choose(selected); changed('nudge');
        }
    };
    canvas.upperCanvasEl.addEventListener('keydown', onKey);
    canvas.on('selection:created', notifySelection);
    canvas.on('selection:updated', notifySelection);
    canvas.on('selection:cleared', notifySelection);
    canvas.on('object:modified', () => changed('transform'));
    canvas.on('text:changed', () => changed('text'));
    canvas.on('path:created', ({ path }) => { setIdentity(path, newElement('path', { name: 'Dibujo', fill: '#00000000' })); changed('draw'); });
    canvas.on('mouse:down', ({ target }) => { if (!target?.isEditing) canvas.upperCanvasEl.focus({ preventScroll: true }); });
    canvas.on('object:moving', ({ target }) => {
        if (!target || target.studioLocked) return;
        const bounds = target.getBoundingRect();
        const threshold = 6 / scale;
        const xs = [0, documentData.width / 2, documentData.width];
        const ys = [0, documentData.height / 2, documentData.height];
        const objectXs = [bounds.left, bounds.left + bounds.width / 2, bounds.left + bounds.width];
        const objectYs = [bounds.top, bounds.top + bounds.height / 2, bounds.top + bounds.height];
        const dx = xs.flatMap(x => objectXs.map(v => x - v)).find(delta => Math.abs(delta) < threshold);
        const dy = ys.flatMap(y => objectYs.map(v => y - v)).find(delta => Math.abs(delta) < threshold);
        if (dx != null) target.left += dx;
        if (dy != null) target.top += dy;
    });
    try { await loadPage(currentPage); }
    catch (error) { await adapter.destroy(); throw error; }
    return adapter;
}
