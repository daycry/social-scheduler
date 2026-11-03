import { exportDesign } from './exports.js';

// Executed in an isolated browser with network blocked. Input is our validated document, never code or HTML.
globalThis.renderStudioFiles = async ({ document, assets, ...options }) => {
    const files = await exportDesign(document, { assets, ...options });
    const output = [];
    for (const [index, file] of files.entries()) {
        const bytes = new Uint8Array(await file.blob.arrayBuffer());
        if (bytes.length > 64_000_000) throw new Error('El archivo supera 64 MB.');
        let binary = '';
        for (let offset = 0; offset < bytes.length; offset += 32768) binary += String.fromCharCode(...bytes.subarray(offset, offset + 32768));
        output.push({ name: `page-${String(index + 1).padStart(3, '0')}.${file.extension}`, mime: file.mime, data: btoa(binary) });
    }
    return output;
};
