import { expect, test } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, quietFor, seedComposerBrand, stamp } from './helpers';

function waveBytes() {
    const buffer = Buffer.alloc(44 + 32000);
    buffer.write('RIFF', 0); buffer.writeUInt32LE(buffer.length - 8, 4); buffer.write('WAVEfmt ', 8);
    buffer.writeUInt32LE(16, 16); buffer.writeUInt16LE(1, 20); buffer.writeUInt16LE(1, 22);
    buffer.writeUInt32LE(16000, 24); buffer.writeUInt32LE(32000, 28); buffer.writeUInt16LE(2, 32); buffer.writeUInt16LE(16, 34);
    buffer.write('data', 36); buffer.writeUInt32LE(32000, 40);
    for (let i = 0; i < 16000; i++) buffer.writeInt16LE(Math.round(12000 * Math.sin(2 * Math.PI * 440 * i / 16000)), 44 + i * 2);
    return buffer;
}

test('Studio audio resources API: private binary upload, byte ranges and native browser playback', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-audio-resource-${stamp()}-${info.project.name}`);
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        const wave = waveBytes();
        const resource = await page.evaluate(async ({ brandId, bytes }) => {
            const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': csrf, 'Content-Type': 'application/json' };
            const start = await fetch(`/brands/${brandId}/studio/audio`, { method: 'POST', headers,
                body: JSON.stringify({ name: 'tono-440.wav', mime: 'audio/wav', size: bytes.length }) });
            if (start.status !== 201) throw new Error(`Audio start ${start.status}: ${await start.text()}`);
            const ticket = await start.json();
            const upload = await fetch(ticket.upload.url, { method: 'PUT', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf, 'Content-Type': 'audio/wav' }, body: new Uint8Array(bytes) });
            if (!upload.ok) throw new Error(`Audio upload ${upload.status}: ${await upload.text()}`);
            const asset = (await upload.json()).asset;
            const range = await fetch(asset.preview_url, { headers: { Range: 'bytes=0-31' } });
            const result = { asset, rangeStatus: range.status, range: range.headers.get('Content-Range'),
                firstBytes: [...new Uint8Array(await range.arrayBuffer())], mime: range.headers.get('Content-Type') };
            const audio = document.createElement('audio'); audio.id = 'native-resource-probe'; audio.preload = 'auto'; audio.src = asset.preview_url;
            // This native element verifies the resource endpoint; it is not a product editor control.
            document.body.append(audio);
            await new Promise<void>((resolve, reject) => {
                const timeout = setTimeout(() => reject(new Error('Audio metadata timeout')), 10000);
                audio.onloadedmetadata = () => { clearTimeout(timeout); resolve(); };
                audio.onerror = () => { clearTimeout(timeout); reject(new Error('Audio decode failed')); };
                audio.load();
            });
            return { ...result, duration: audio.duration };
        }, { brandId: brand.id, bytes: [...wave] });
        expect(resource.asset.status).toBe('listo'); expect(resource.asset.audio_codec).toBe('pcm_s16le');
        expect(resource.rangeStatus).toBe(206); expect(resource.range).toBe(`bytes 0-31/${wave.length}`);
        expect(resource.firstBytes).toEqual([...wave.subarray(0, 32)]); expect(resource.mime).toBe('audio/wav');
        expect(resource.duration).toBeCloseTo(1, 2);
        // A trusted gesture permits native media playback without weakening Chromium's autoplay policy.
        await page.getByRole('heading', { name: 'Estudio creativo', exact: true }).click();
        await page.locator('#native-resource-probe').evaluate((element: HTMLAudioElement) => element.play());
        await expect.poll(() => page.locator('#native-resource-probe').evaluate((element: HTMLAudioElement) => element.currentTime)).toBeGreaterThan(0.05);
        await page.locator('#native-resource-probe').evaluate((element: HTMLAudioElement) => element.pause());
        const paused = await page.locator('#native-resource-probe').evaluate((element: HTMLAudioElement) => element.currentTime);
        await quietFor(page, 100); // negativa: en pausa currentTime no avanza
        expect(await page.locator('#native-resource-probe').evaluate((element: HTMLAudioElement) => element.currentTime)).toBeCloseTo(paused, 2);
        const catalogs = await page.evaluate(async brandId => ({
            audio: await (await fetch(`/brands/${brandId}/studio/audio`)).json(),
            social: await (await fetch('/media', { headers: { Accept: 'application/json' } })).json(),
        }), brand.id);
        expect(catalogs.audio.assets.map((asset: { id: number }) => asset.id)).toContain(resource.asset.id);
        expect(catalogs.social.media.map((asset: { id: number }) => asset.id)).not.toContain(resource.asset.id);
        await expectNoOverflow(page); expect(errors).toEqual([]);
    } finally {
        await page.locator('#native-resource-probe').evaluateAll(elements => elements.forEach(element => {
            const audio = element as HTMLAudioElement; audio.pause(); audio.removeAttribute('src'); audio.load(); audio.remove();
        }));
        cleanComposerBrands(user.email);
    }
});
