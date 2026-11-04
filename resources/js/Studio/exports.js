import { PDFDocument } from 'pdf-lib';
import { FabricImage, Group } from 'fabric';
import { selectPages, validateDocument, StudioDocumentError } from './document.js';
import { renderStudioPage } from './canvas.js';

export const EXPORT_FORMATS = Object.freeze([
    { value: 'png', label: 'PNG', extension: 'png', mime: 'image/png' },
    { value: 'jpeg', label: 'JPG', extension: 'jpg', mime: 'image/jpeg' },
    { value: 'webp', label: 'WebP', extension: 'webp', mime: 'image/webp' },
    { value: 'svg', label: 'SVG', extension: 'svg', mime: 'image/svg+xml' },
    { value: 'pdf', label: 'PDF', extension: 'pdf', mime: 'application/pdf' },
]);

const metadata = format => {
    const result = EXPORT_FORMATS.find(item => item.value === format);
    if (!result) throw new StudioDocumentError('Selecciona un formato de exportación admitido.');
    return result;
};

function rasterOptions(document, options) {
    const scale = options.scale ?? 1;
    const quality = options.quality ?? 0.94;
    const width = Math.round(document.width * scale);
    const height = Math.round(document.height * scale);
    if (!Number.isFinite(scale) || scale < 0.1 || scale > 4 || width * height > 64_000_000 || width > 16000 || height > 16000) throw new StudioDocumentError('La resolución de exportación supera el límite de 64 megapíxeles o 16000 píxeles por lado. Reduce el tamaño o la escala.');
    if (!Number.isFinite(quality) || quality < 0.1 || quality > 1) throw new StudioDocumentError('La calidad de exportación debe estar entre 0,1 y 1.');
    return { scale, quality, width, height };
}

function rasterBlob(canvas, mime, quality) {
    return new Promise((resolve, reject) => {
        try {
            canvas.toBlob(blob => {
                if (!blob || blob.size === 0) reject(new StudioDocumentError('El navegador no pudo generar el archivo.'));
                else if (blob.type !== mime) reject(new StudioDocumentError(`Este navegador no admite exportar ${mime}. Elige PNG o JPG.`));
                else resolve(blob);
            }, mime, quality);
        } catch {
            reject(new StudioDocumentError('Un recurso impide exportar el diseño. Vuelve a cargarlo desde la biblioteca de esta marca.'));
        }
    });
}

/** Portable SVG: include pixel bytes, never private/expiring asset URLs or video URLs. */
export function exportSvg(canvas, { width, height } = {}) {
    const logicalWidth = width ?? Math.round(canvas.width / canvas.getZoom());
    const logicalHeight = height ?? Math.round(canvas.height / canvas.getZoom());
    const restorations = [];
    const inlineImages = objects => objects.forEach(object => {
        if (object instanceof Group) inlineImages(object.getObjects());
        if (!(object instanceof FabricImage)) return;
        const source = object.getElement();
        const pixels = document.createElement('canvas');
        pixels.width = source.naturalWidth || source.videoWidth || source.width;
        pixels.height = source.naturalHeight || source.videoHeight || source.height;
        if (!pixels.width || !pixels.height) throw new StudioDocumentError('Una imagen todavía no ha terminado de cargar.');
        const context = pixels.getContext('2d');
        if (!context) throw new StudioDocumentError('No se pudo preparar una imagen para SVG.');
        context.drawImage(source, 0, 0);
        let data;
        try { data = pixels.toDataURL('image/png'); }
        catch { throw new StudioDocumentError('Un recurso no permite incluir sus píxeles en el SVG. Usa la biblioteca de esta marca.'); }
        const old = object.getSrc;
        object.getSrc = () => data;
        restorations.push(() => { object.getSrc = old; });
    });
    try {
        inlineImages(canvas.getObjects());
        return canvas.toSVG({ width: `${logicalWidth}px`, height: `${logicalHeight}px`, viewBox: { x: 0, y: 0, width: logicalWidth, height: logicalHeight } });
    } finally {
        restorations.forEach(restore => restore());
    }
}

/** A temporary unzoomed canvas guarantees that editor zoom/selection never affects output pixels. */
export async function exportPage(documentData, pageId, assets = [], options = {}) {
    const document = validateDocument(documentData);
    const format = options.format ?? 'png';
    const target = metadata(format);
    if (format === 'pdf') return exportPdf(document, [pageId], assets, options);
    const { scale, quality, width, height } = rasterOptions(document, options);
    const rendering = await renderStudioPage(document, pageId, assets);
    try {
        if (options.transparent) {
            rendering.canvas.backgroundColor = '#ffffff00';
            rendering.canvas.renderAll();
        }
        if (format === 'svg') return new Blob([exportSvg(rendering.canvas, document)], { type: target.mime });
        // Canvas attributes truncate fractional sizes. Set the rounded pixel dimensions first,
        // then render the original geometry at the exact scale; never stretch a truncated bitmap.
        rendering.canvas.setDimensions({ width, height });
        rendering.canvas.setViewportTransform([scale, 0, 0, scale, 0, 0]);
        rendering.canvas.renderAll();
        const pixels = rendering.canvas.getElement();
        if (format === 'jpeg') {
            const context = pixels.getContext('2d');
            context.save();
            context.globalCompositeOperation = 'destination-over';
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, pixels.width, pixels.height);
            context.restore();
        }
        return await rasterBlob(pixels, target.mime, quality);
    } finally {
        await rendering.dispose();
    }
}

/** Raster pages embedded in a real PDF, one page per selected ID in the requested order. */
export async function exportPdf(documentData, pageIds, assets = [], options = {}) {
    const document = validateDocument(documentData);
    const pages = selectPages(document, pageIds);
    const pdf = await PDFDocument.create();
    pdf.setTitle(options.title || 'Diseño');
    pdf.setCreator('Creative Social Suite');
    const pageWidth = document.width * 72 / 96;
    const pageHeight = document.height * 72 / 96;
    for (let i = 0; i < pages.length; i++) {
        const image = await exportPage(document, pages[i].id, assets, { ...options, format: 'png' });
        const bitmap = await pdf.embedPng(await image.arrayBuffer());
        const page = pdf.addPage([pageWidth, pageHeight]);
        page.drawImage(bitmap, { x: 0, y: 0, width: pageWidth, height: pageHeight });
        options.onProgress?.({ completed: i + 1, total: pages.length });
    }
    return new Blob([await pdf.save()], { type: 'application/pdf' });
}

export async function exportDesign(documentData, { format = 'png', pageIds, assets = [], ...options } = {}) {
    const document = validateDocument(documentData);
    const pages = selectPages(document, pageIds);
    const target = metadata(format);
    if (format === 'pdf') return [{ blob: await exportPdf(document, pages.map(page => page.id), assets, options), pageId: null, extension: target.extension, mime: target.mime }];
    const output = [];
    for (let i = 0; i < pages.length; i++) {
        output.push({ blob: await exportPage(document, pages[i].id, assets, { ...options, format }), pageId: pages[i].id, extension: target.extension, mime: target.mime });
        options.onProgress?.({ completed: i + 1, total: pages.length });
    }
    return output;
}

export function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.rel = 'noopener';
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30000);
}
