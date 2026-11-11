import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomUUID, createHash } from 'node:crypto';
import { chromium } from 'playwright';
import { attemptDirectory, checkedSegment, segmentFolder, boundedProcess, checkedNamespace, checkpointRelative, publishCheckpoint } from './av-files.mjs';
import { openResourceServer } from './av-server.mjs';
import { audiovisualRenderOptions } from '../../resources/js/Studio/audiovisual-plan.js';

const project = fileURLToPath(new URL('../../', import.meta.url));
export async function renderVisualSegment({ directory, plan, assets = [], fonts = [], segment, signal, format = 'mp4', namespace = null }) {
    checkedNamespace(namespace);
    directory = await attemptDirectory(directory); checkedSegment(plan, segment); signal?.throwIfAborted();
    if (!['mp4', 'gif'].includes(format)) throw new Error('Formato audiovisual no admitido.');
    const render = audiovisualRenderOptions(plan.document, plan.options), { width, height } = render;
    const folder = await segmentFolder(directory, namespace), name = `video-${String(segment.index).padStart(6, '0')}.${format}`;
    const temporary = path.join(folder, `${randomUUID()}.part.${format}`), target = path.join(folder, name);
    let browser, server;
    const abort = () => { void browser?.close().catch(() => {}); };
    try {
        server = await openResourceServer(directory, assets, fonts);
        browser = await chromium.launch({ headless: true, ...(process.env.STUDIO_CHROMIUM_PATH ? { executablePath: process.env.STUDIO_CHROMIUM_PATH } : {}) });
        signal?.addEventListener('abort', abort, { once: true }); signal?.throwIfAborted();
        const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 1, serviceWorkers: 'block', acceptDownloads: false });
        await context.route('**/*', route => route.request().method() === 'GET' && server.allows(route.request().url()) ? route.continue() : route.abort());
        const page = await context.newPage(); page.setDefaultTimeout(20000);
        await page.goto(server.url);
        await page.addScriptTag({ path: path.join(project, 'public/build/studio-project-renderer.js') });
        const prepared = await page.evaluate(input => globalThis.prepareStudioProject(input), { document: plan.document, assets: server.assets, fonts: server.fonts, renderScale: render.scale });
        if (prepared.frameCount !== plan.frameCount || prepared.fps !== plan.fps || prepared.width !== width || prepared.height !== height) throw new Error('El navegador y el servidor no comparten el reloj o tamaño de render.');
        async function* frames() {
            for (let frame = segment.frameStart; frame < segment.frameStart + segment.frameCount; frame++) {
                signal?.throwIfAborted();
                await page.evaluate(frame => globalThis.paintStudioProjectFrame(frame), frame);
                const bytes = await page.locator('#studio-project-output').screenshot({ type: 'png', animations: 'disabled' });
                if (bytes.length > 64000000) throw new Error('Un fotograma supera el límite de transferencia.');
                yield bytes;
            }
        }
        const encoding = format === 'mp4' ? ['-vf', 'pad=ceil(iw/2)*2:ceil(ih/2)*2:color=black', '-filter_threads', '1',
            '-c:v', 'libx264', '-threads', '2', '-preset', 'fast', '-crf', String(render.crf), '-pix_fmt', 'yuv420p', '-movflags', '+faststart']
            : ['-filter_complex', `pad=ceil(iw/2)*2:ceil(ih/2)*2:color=black,split[a][b];[a]palettegen=stats_mode=single:max_colors=${render.paletteColors}[p];[b][p]paletteuse=new=1`,
                '-filter_complex_threads', '1', '-threads', '1', '-gifflags', '0', '-loop', '0'];
        await boundedProcess(process.env.STUDIO_FFMPEG_BINARY ?? 'ffmpeg', ['-hide_banner', '-loglevel', 'error', '-nostdin', '-n',
            '-f', 'image2pipe', '-framerate', String(plan.fps), '-vcodec', 'png', '-i', 'pipe:0', '-an',
            ...encoding, '-frames:v', String(segment.frameCount), '-fs', String(512 * 1024 ** 2), temporary],
        { timeout: 80000, maxBytes: 1024, signal, input: frames() });
        const probe = JSON.parse((await boundedProcess(process.env.STUDIO_FFPROBE_BINARY ?? 'ffprobe', ['-v', 'error', '-count_frames',
            '-show_entries', 'stream=codec_type,codec_name,width,height,avg_frame_rate,nb_read_frames:format=duration', '-of', 'json', temporary], { signal, maxBytes: 65536 })).toString());
        const stream = probe.streams?.find(stream => stream.codec_type === 'video');
        const [numerator, denominator] = (stream?.avg_frame_rate ?? '').split('/').map(Number);
        const paddedWidth = Math.ceil(width / 2) * 2, paddedHeight = Math.ceil(height / 2) * 2;
        if (stream?.codec_name !== (format === 'mp4' ? 'h264' : 'gif') || Number(stream.nb_read_frames) !== segment.frameCount || (format === 'mp4' && numerator / denominator !== plan.fps)
            || stream.width !== paddedWidth || stream.height !== paddedHeight || probe.streams?.length !== 1
            || Math.abs(Number(probe.format?.duration) - segment.frameCount / plan.fps) > 1 / plan.fps + (format === 'gif' ? 0.01 : 0)) throw new Error('El tramo generado no coincide con sus fotogramas, resolución o códec.');
        const stat = await fs.stat(temporary);
        if (stat.size < 1 || stat.size > 512 * 1024 ** 2) throw new Error('El tramo de vídeo supera su presupuesto de disco.');
        // Only a fully probed segment is published; an interrupted .part is never a downloadable result.
        await publishCheckpoint(temporary, target, namespace);
        const hash = createHash('sha256');
        const file = await fs.open(target, 'r'); try { for await (const bytes of file.createReadStream()) hash.update(bytes); } finally { await file.close(); }
        return { file: checkpointRelative('segments', name, namespace), namespace, size: stat.size, sha256: hash.digest('hex'), frameStart: segment.frameStart,
            frameCount: segment.frameCount, fps: plan.fps, width: paddedWidth, height: paddedHeight, scale: render.scale, quality: render.quality };
    } finally {
        signal?.removeEventListener('abort', abort); await browser?.close().catch(() => {}); await server?.close(); await fs.rm(temporary, { force: true });
    }
}
