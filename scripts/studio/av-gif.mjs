import fs from 'node:fs/promises';

/** Small buffered reader; GIF compressed frames remain compressed, never expanded into a project-sized bitmap cache. */
class Reader {
    constructor(file) { this.file = file; this.buffer = Buffer.alloc(0); this.offset = 0; }
    async read(size, optional = false) {
        const chunks = []; let remaining = size;
        while (remaining > 0) {
            if (this.offset >= this.buffer.length) {
                const buffer = Buffer.alloc(65536), { bytesRead } = await this.file.read(buffer, 0, buffer.length, null);
                if (!bytesRead) { if (optional && remaining === size) return null; throw new Error('Tramo GIF truncado.'); }
                this.buffer = buffer.subarray(0, bytesRead); this.offset = 0;
            }
            const count = Math.min(remaining, this.buffer.length - this.offset);
            chunks.push(this.buffer.subarray(this.offset, this.offset + count)); this.offset += count; remaining -= count;
        }
        return chunks.length === 1 ? chunks[0] : Buffer.concat(chunks);
    }
    async subBlocks() {
        const chunks = []; let size = 0;
        for (;;) {
            const length = await this.read(1); chunks.push(length); size++;
            if (!length[0]) break;
            size += length[0]; if (size > 64000000) throw new Error('Un fotograma GIF comprimido supera 64 MB.');
            chunks.push(await this.read(length[0]));
        }
        return Buffer.concat(chunks);
    }
}

/** A single GIF89a, with a local palette on every frame and one accumulated centisecond clock. */
export async function joinGifSegments(files, target, { width, height, fps, frameCount, counts, signal }) {
    const output = await fs.open(target, 'wx'), deadline = Date.now() + 80000;
    let frames = 0, bytes = 0;
    const write = async chunk => {
        signal?.throwIfAborted();
        if (Date.now() > deadline || bytes + chunk.length > 8 * 1024 ** 3) throw new Error('La unión GIF supera su presupuesto de tiempo o disco.');
        let offset = 0;
        while (offset < chunk.length) { const result = await output.write(chunk, offset, chunk.length - offset, null); if (!result.bytesWritten) throw new Error('No se pudo escribir el GIF completo.'); offset += result.bytesWritten; }
        bytes += chunk.length;
    };
    try {
        const header = Buffer.alloc(13); header.write('GIF89a'); header.writeUInt16LE(width, 6); header.writeUInt16LE(height, 8); header[10] = 0x70;
        await write(header); await write(Buffer.from('21ff0b4e45545343415045322e300301000000', 'hex'));
        for (let index = 0; index < files.length; index++) {
            const input = await fs.open(files[index], 'r');
            try {
                const reader = new Reader(input), header = await reader.read(13);
                if (!['GIF87a', 'GIF89a'].includes(header.subarray(0, 6).toString()) || header.readUInt16LE(6) !== width || header.readUInt16LE(8) !== height) throw new Error('Las dimensiones o cabecera del tramo GIF no coinciden.');
                const globalFlags = header[10], globalPalette = globalFlags & 0x80 ? await reader.read(3 * 2 ** ((globalFlags & 7) + 1)) : null;
                let control = null, localFrames = 0;
                for (;;) {
                    const tag = (await reader.read(1))[0];
                    if (tag === 0x3b) { if (await reader.read(1, true)) throw new Error('Un tramo contiene varios GIF o datos sobrantes.'); break; }
                    if (tag === 0x21) {
                        const label = (await reader.read(1))[0];
                        if (label === 0xf9) { const block = await reader.read(6); if (block[0] !== 4 || block[5] !== 0) throw new Error('Control GIF no válido.'); control = block; }
                        else await reader.subBlocks(); // No source comments or duplicate NETSCAPE loop metadata.
                        continue;
                    }
                    if (tag !== 0x2c) throw new Error('Bloque GIF desconocido.');
                    const descriptor = Buffer.from(await reader.read(9));
                    if (descriptor.readUInt16LE(0) !== 0 || descriptor.readUInt16LE(2) !== 0 || descriptor.readUInt16LE(4) !== width || descriptor.readUInt16LE(6) !== height) throw new Error('El tramo GIF necesita fotogramas completos sin offsets.');
                    const flags = descriptor[8], palette = flags & 0x80 ? await reader.read(3 * 2 ** ((flags & 7) + 1)) : globalPalette;
                    if (!palette) throw new Error('El fotograma GIF no tiene paleta.');
                    descriptor[8] = (flags & 0x78) | 0x80 | (flags & 0x80 ? flags & 7 : globalFlags & 7);
                    const codeSize = await reader.read(1);
                    if (codeSize[0] < 2 || codeSize[0] > 8) throw new Error('Código LZW de GIF no válido.');
                    const data = await reader.subBlocks();
                    const delay = Math.round((frames + 1) * 100 / fps) - Math.round(frames * 100 / fps);
                    if (frames >= frameCount || localFrames >= counts[index]) throw new Error('El GIF contiene más fotogramas de los previstos.');
                    const gce = Buffer.from([0x21, 0xf9, 4, control?.[1] ?? 0, delay & 255, delay >> 8, control?.[4] ?? 0, 0]);
                    await write(Buffer.concat([gce, Buffer.from([0x2c]), descriptor, palette, codeSize, data]));
                    frames++; localFrames++; control = null;
                }
                if (localFrames !== counts[index]) throw new Error('Faltan fotogramas en el tramo GIF.');
            } finally { await input.close(); }
        }
        if (frames !== frameCount) throw new Error('La unión GIF no cubre todo el proyecto.');
        await write(Buffer.from([0x3b])); await output.sync();
        return { frames, duration: Math.round(frameCount * 100 / fps) / 100, bytes };
    } finally { await output.close(); }
}
