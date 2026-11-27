import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

test.describe('E2E-33 · Catálogo de plantillas del Estudio', { tag: '@responsive' }, () => {
    test('crea un diseño desde una plantilla animada, previsualiza y exporta un fotograma', async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        const brand = seedComposerBrand(user.email, `studio-catalog-${stamp()}-${info.project.name}`);
        const errors: string[] = [];
        page.on('pageerror', error => errors.push(error.message));
        try {
            await loginAs(page, user);
            await page.goto(`/studio?brand_id=${brand.id}`);
            const catalog = page.getByRole('region', { name: 'Una idea para empezar', exact: true });
            await catalog.getByLabel('Formato de plantilla').selectOption('story');
            await catalog.getByLabel('Buscar plantillas').fill('AGENDA');
            await expect(catalog.getByRole('status')).toHaveText('1 plantilla');
            const template = catalog.getByRole('button', { name: /^Agenda de la semana.*1080 × 1920.*Animada$/ });
            await template.scrollIntoViewIfNeeded();
            await expect(template.locator('img')).toBeVisible();
            await expect.poll(() => template.locator('img').evaluate(img => (img as HTMLImageElement).naturalWidth)).toBeGreaterThan(0);
            await expectNoOverflow(page);
            await page.screenshot({ path: info.outputPath('studio-template-initial.png') });

            await template.click();
            await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
            await expect(page).toHaveTitle(/Agenda de la semana/);
            const timeline = page.getByRole('region', { name: 'Línea de tiempo', exact: true });
            await expect(timeline.getByTestId('timeline-animation-in')).toHaveCount(1);
            await expect(timeline.getByTestId('timeline-animation-out')).toHaveCount(1);
            const clock = timeline.getByLabel('Tiempo de reproducción', { exact: true });
            const preview = page.getByLabel('Previsualización de la línea de tiempo', { exact: true });
            const headlinePixels = () => preview.evaluate(node => {
                const canvas = node as HTMLCanvasElement;
                const { width, height } = canvas;
                // La región del titular queda entre la etiqueta y el detalle, sobre el fondo claro.
                const data = canvas.getContext('2d')!.getImageData(
                    Math.floor(width * 0.09), Math.floor(height * 0.28), Math.floor(width * 0.58), Math.floor(height * 0.23),
                ).data;
                let count = 0;
                for (let i = 0; i < data.length; i += 4) {
                    if (data[i + 3] > 200 && data[i] + data[i + 1] + data[i + 2] < 500) count++;
                }
                return count;
            });
            await clock.fill('2');
            await clock.press('Tab');
            await expect(preview).toBeVisible();
            await expect.poll(headlinePixels).toBeGreaterThan(50);
            const visiblePixels = await headlinePixels();
            await clock.fill('0');
            await clock.press('Tab');
            await expect.poll(headlinePixels).toBeLessThan(visiblePixels / 2);
            await clock.fill('2');
            await clock.press('Tab');
            await expect.poll(headlinePixels).toBeGreaterThan(visiblePixels / 2);
            await expectNoOverflow(page);
            await page.screenshot({ path: info.outputPath('studio-template-preview.png') });

            await page.getByRole('button', { name: 'Exportar', exact: true }).click();
            const exporter = page.getByLabel('Exportar diseño', { exact: true });
            await exporter.getByLabel('Formato', { exact: true }).selectOption('png');
            await expect(exporter.getByLabel('Fotograma de Agenda de la semana (s)', { exact: true })).toHaveValue('2');
            const download = page.waitForEvent('download');
            await exporter.getByRole('button', { name: 'Descargar', exact: true }).click();
            const output = info.outputPath('studio-template-frame.png');
            await (await download).saveAs(output);
            const png = readFileSync(output);
            expect(png.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
            expect([png.readUInt32BE(16), png.readUInt32BE(20)]).toEqual([1080, 1920]);
            expect(errors).toEqual([]);
        } finally {
            cleanComposerBrands(user.email);
        }
    });
});

test('E2E-33 · Chromium renderiza las 60 páginas nuevas con la fuente de marca y sin desbordes', async ({ page }) => {
    const { build } = await import('vite');
    const result = await build({
        configFile: false,
        publicDir: false,
        logLevel: 'error',
        build: {
            write: false,
            lib: { entry: 'tests/e2e/support/studio-template-renderer.ts', formats: ['es'] },
            rolldownOptions: { output: { codeSplitting: false } },
        },
    });
    const outputs = Array.isArray(result) ? result : [result];
    const bundles = outputs.flatMap(output => 'output' in output ? output.output : []);
    const entry = bundles.find(chunk => chunk.type === 'chunk' && chunk.isEntry);
    if (entry?.type !== 'chunk') throw new Error('Falta el módulo de prueba del renderizador.');

    const fontBytes = readFileSync('tests/Fixtures/fonts/SpaceGrotesk-Variable.woff2');
    const font = {
        size: fontBytes.length,
        sha256: createHash('sha256').update(fontBytes).digest('hex'),
        file_url: '/_e2e/studio-template-brand.woff2',
    };
    const rendererPath = '/_e2e/studio-template-renderer.js';
    const hostPath = '/_e2e/studio-template-canvas';
    // Un origen HTTP de prueba para que el cargador real pueda solicitar la fuente privada.
    await page.route(hostPath, route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><link rel="icon" href="data:,">' }));
    await page.route(rendererPath, route => route.fulfill({ contentType: 'text/javascript', body: entry.code }));
    await page.route(font.file_url, route => route.fulfill({ contentType: 'font/woff2', body: fontBytes }));
    const errors: string[] = [];
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    page.on('pageerror', error => errors.push(error.message));

    await page.goto(hostPath);
    const { templates, renderedPages, checkedTexts, failures } = await page.evaluate(async ({ rendererPath, font }) => {
        const { checkSocialTemplateRender }: typeof import('./support/studio-template-renderer') = await import(rendererPath);
        return checkSocialTemplateRender(font);
    }, { rendererPath, font });

    expect([...errors, ...failures]).toEqual([]);
    expect(templates).toBe(50);
    expect(renderedPages).toBe(60);
    expect(checkedTexts).toBeGreaterThanOrEqual(renderedPages);
});
