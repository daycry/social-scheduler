// Private per-lease audiovisual phase entry point. Not an HTTP or MCP API.
import fs from 'node:fs/promises';
import path from 'node:path';
import { attemptDirectory, checkedNamespace } from './av-files.mjs';
import { compileAudiovisual } from '../../resources/js/Studio/audiovisual-plan.js';
import { prepareAudioSource } from './av-pcm.mjs';
import { mixAudioSegment } from './av-audio.mjs';
import { renderVisualSegment } from './av-video.mjs';
import { assembleProject } from './av-finalize.mjs';

const phases = ['plan', 'normalize', 'render', 'mix', 'assemble'];
const same = (a, b) => process.platform === 'win32' ? a.toLowerCase() === b.toLowerCase() : a === b;
let response, timer, polling;
const controller = new AbortController();
try {
    const [supplied, namespace, phase, indexValue, ...extra] = process.argv.slice(2);
    checkedNamespace(namespace);
    const deadline = Number(process.env.STUDIO_AV_DEADLINE_MS), remaining = deadline - Date.now();
    if (process.env.STUDIO_AV_MANAGED !== '1' || !namespace || !phases.includes(phase) || extra.length
        || !/^\d{1,16}$/.test(indexValue ?? '') || !Number.isSafeInteger(Number(indexValue)) || !Number.isSafeInteger(deadline) || remaining > 85000) throw new Error('invalid_request');
    if (remaining <= 0) throw new Error('phase_timeout');
    timer = setTimeout(() => controller.abort(new Error('phase_timeout')), remaining);
    const signal = controller.signal, directory = await attemptDirectory(supplied), index = Number(indexValue);
    const work = path.join(directory, 'work'), file = path.join(work, `${namespace}.json`), cancel = path.join(work, `${namespace}.cancel`);
    if (!same(await fs.realpath(work), work) || !same(await fs.realpath(file), file)) throw new Error('invalid_request');
    const stat = await fs.stat(file);
    if (!stat.isFile() || stat.size < 1 || stat.size > 8 * 1024 ** 2) throw new Error('invalid_request');
    async function checkCancel() {
        try { await fs.lstat(cancel); controller.abort(new Error('phase_cancelled')); }
        catch (error) { if (error.code !== 'ENOENT') controller.abort(new Error('phase_failed')); }
    }
    await checkCancel(); signal.throwIfAborted();
    polling = setInterval(() => { void checkCancel(); }, 100);
    const content = await fs.readFile(file);
    if (content.length !== stat.size || content.length > 8 * 1024 ** 2) throw new Error('invalid_request');
    const input = JSON.parse(content.toString('utf8'));
    if (!input || typeof input !== 'object' || Array.isArray(input) || Object.keys(input).some(key => !['document', 'assets', 'fonts', 'options', 'format', 'audioSources', 'segments'].includes(key))
        || !['mp4', 'gif'].includes(input.format) || !Array.isArray(input.assets) || !Array.isArray(input.fonts)
        || !Array.isArray(input.audioSources) || !Array.isArray(input.segments)) throw new Error('invalid_request');
    const plan = compileAudiovisual(input.document, input.assets, input.options);
    if (plan.segments.length > 4096) throw new Error('plan_budget');
    const audioIds = new Set(input.format === 'mp4' ? plan.audio.map(input => input.assetId) : []);
    const assetIds = new Set(audioIds), visualIds = new Set(), fontIds = new Set();
    function collect(elements, start, end, duration) {
        for (const element of elements) {
            if (element.visible === false) continue;
            const timing = element.timing ?? { start: 0, duration };
            const from = Math.max(start, timing.start), to = Math.min(end, timing.start + timing.duration);
            if (from >= to) continue;
            if (element.type === 'group') collect(element.elements, from, to, duration);
            else if (['image', 'video'].includes(element.type)) { assetIds.add(element.asset_id); visualIds.add(element.asset_id); }
            else if (element.type === 'text' && element.font_id != null) fontIds.add(element.font_id);
        }
    }
    for (const page of plan.timeline.pages) collect(page.source.elements, 0, Math.min(page.source.duration, page.end - page.start), page.source.duration);
    if (plan.document.timeline.captions?.cues.length && plan.document.timeline.captions.style.font_id != null) fontIds.add(plan.document.timeline.captions.style.font_id);
    const assets = input.assets.filter(asset => assetIds.has(asset.id)), fonts = input.fonts.filter(font => fontIds.has(font.id));
    if (assets.length !== assetIds.size || fonts.length !== fontIds.size || assets.some(asset => asset.status !== 'listo')) throw new Error('missing_resource');
    const common = { directory, namespace, plan, signal };
    signal.throwIfAborted();
    let result;
    if (phase === 'plan') result = { frameCount: plan.frameCount, fps: plan.fps, duration: plan.duration, sampleRate: plan.sampleRate,
        segmentCount: plan.segments.length, segmentFrames: plan.options.segmentFrames, selection: plan.selection, render: plan.render,
        assetIds: [...assetIds], fontIds: [...fontIds], audioAssetIds: [...audioIds] };
    else if (phase === 'normalize') {
        if (!audioIds.has(index)) throw new Error('invalid_request');
        result = await prepareAudioSource({ ...common, asset: assets.find(asset => asset.id === index) });
    } else if (phase === 'render' || phase === 'mix') {
        const segment = plan.segments[index];
        if (!segment || (phase === 'mix' && (input.format === 'gif' || !plan.audio.length))) throw new Error('invalid_request');
        result = phase === 'render' ? await renderVisualSegment({ ...common, assets: assets.filter(asset => visualIds.has(asset.id)), fonts, segment, format: input.format })
            : await mixAudioSegment({ ...common, segment, audioSources: input.audioSources });
    } else result = await assembleProject({ ...common, segments: input.segments, format: input.format });
    signal.throwIfAborted();
    if (Date.now() >= deadline) throw new Error('phase_timeout');
    response = { ok: true, phase, index, namespace, result };
} catch (error) {
    const code = controller.signal.reason?.message ?? error.message;
    response = { ok: false, error_code: ['invalid_request', 'phase_timeout', 'phase_cancelled', 'plan_budget', 'missing_resource'].includes(code) ? code : 'phase_failed' };
} finally { clearTimeout(timer); clearInterval(polling); }
const output = JSON.stringify(response);
process.stdout.write((Buffer.byteLength(output) <= 8 * 1024 ** 2 ? output : JSON.stringify({ ok: false, error_code: 'output_budget' })) + '\n');
process.exitCode = response.ok ? 0 : 1;
