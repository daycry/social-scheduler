import AxeBuilder from '@axe-core/playwright';
import { errors, expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, settle, stamp, watch } from './helpers';

async function axeNow(page: Page, label: string) {
    await settle(page);
    await expect(page.locator('#nprogress')).toHaveCount(0);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const bad = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    const detail = bad.map((v) => `${v.id} [${v.impact}] ${v.help}\n   ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join('\n   ')}`).join('\n');
    expect(bad, `violaciones de accesibilidad en ${label}:\n${detail}`).toEqual([]);
}

const order = (page: Page) => page.locator('[data-builder] [data-block]').evaluateAll((els) => els.map((e) => e.getAttribute('data-block-type')));

test('Report builder: blocks, template, PDF download, schedule and brand isolation', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const a = seedComposerBrand(user.email, `A-report-builder-${stamp()}`);
    const z = seedComposerBrand(user.email, `Z-report-builder-${stamp()}`);
    const errors = watch(page);
    const note = '<b>Nota</b> & "comillas"';
    const tplName = `e2e-plantilla-${stamp()}`;

    try {
        await loginAs(page, user);
        await page.goto(`/reports?brand_id=${a.id}`);
        await expect(page.getByLabel('Marca', { exact: true })).toHaveValue(String(a.id));
        expect(await order(page)).toEqual(['kpis', 'evolution', 'top_posts']);

        // Add a text note and two more blocks, then reorder: the note goes first.
        await page.getByLabel('Tipo de bloque').selectOption('text');
        await page.getByRole('button', { name: 'Añadir bloque', exact: true }).click();
        await page.getByLabel('Texto del bloque 4').fill(note);
        await page.getByLabel('Tipo de bloque').selectOption('competitors');
        await page.getByRole('button', { name: 'Añadir bloque', exact: true }).click();
        await page.getByLabel('Tipo de bloque').selectOption('ads');
        await page.getByRole('button', { name: 'Añadir bloque', exact: true }).click();
        expect(await order(page)).toEqual(['kpis', 'evolution', 'top_posts', 'text', 'competitors', 'ads']);
        for (let i = 0; i < 3; i++) await page.getByRole('button', { name: /^Subir bloque \d+: Nota de texto$/ }).click();
        expect(await order(page)).toEqual(['text', 'kpis', 'evolution', 'top_posts', 'competitors', 'ads']);
        await page.getByRole('button', { name: /^Quitar bloque 6: Anuncios/ }).click();
        await page.getByLabel('Tipo de bloque').selectOption('ads');
        await page.getByRole('button', { name: 'Añadir bloque', exact: true }).click();
        expect(await order(page)).toEqual(['text', 'kpis', 'evolution', 'top_posts', 'competitors', 'ads']);

        // Preview shows the brand data and the note as plain text.
        await page.getByRole('button', { name: 'Vista previa', exact: true }).click();
        const preview = page.locator('[data-preview]');
        await expect(preview).toContainText(a.name);
        await expect(preview.locator('[data-preview-block="text"]')).toContainText(note);
        await expect(preview.locator('[data-preview-block="ads"]')).toContainText('Meta Ads no está disponible');
        await expect(preview.locator('[data-preview-block="text"] b')).toHaveCount(0);
        await expectNoOverflow(page);
        await axeNow(page, 'informes con vista previa');

        // Save the template and restore it after a reload.
        await page.getByLabel('Nombre de la plantilla').fill(tplName);
        await page.getByRole('button', { name: 'Guardar plantilla', exact: true }).click();
        await expect(page.getByRole('status').filter({ hasText: 'Plantilla guardada.' })).toBeVisible();
        await page.reload();
        expect(await order(page)).toEqual(['kpis', 'evolution', 'top_posts']);
        await page.getByLabel('Plantilla guardada').selectOption({ label: tplName });
        await page.getByRole('button', { name: 'Cargar plantilla', exact: true }).click();
        expect(await order(page)).toEqual(['text', 'kpis', 'evolution', 'top_posts', 'competitors', 'ads']);
        await expect(page.getByLabel('Texto del bloque 1')).toHaveValue(note);

        await page.route('**/reports', async route => {
            const data = route.request().postDataJSON();
            await route.continue({ postData: JSON.stringify({ ...data, formats: ['pdf', 'e2e.invalid'] }) });
        }, { times: 1 });
        await page.getByRole('button', { name: 'Generar informe', exact: true }).click();
        const formats = page.getByRole('group', { name: 'Formato', exact: true });
        await expect(formats).toBeFocused();
        await expect(formats).toHaveAccessibleDescription(/format|válid/i);
        await expect(formats.locator('input[type=checkbox][aria-describedby]')).toHaveCount(0);
        await expect(page.locator('[data-report]')).toHaveCount(0);

        // Generate the PDF only; the disposable server has no worker, so run the queued job as the worker would.
        await page.getByLabel('CSV', { exact: true }).uncheck();
        await page.getByRole('button', { name: 'Generar informe', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`/reports\\?brand_id=${a.id}$`));
        const row = page.locator('[data-report]').first();
        await expect(row).toContainText(a.name);
        artisan(`$b = ${a.id}; foreach (App\\Models\\Report::withoutUserScope()->where('brand_id', $b)->where('status', 'pending')->get() as $r) { app(App\\Services\\ReportService::class)->generate($r); }`);
        await page.reload();
        await expect(page.locator('[data-report]').first()).toContainText('Listo');
        await settle(page);
        const [download] = await Promise.all([page.waitForEvent('download'), page.locator('[data-report]').first().getByRole('link', { name: 'Descargar' }).click()]);
        expect(download.suggestedFilename()).toMatch(/^informe-.+\.pdf$/);
        const bytes = fs.readFileSync((await download.path())!);
        expect(bytes.subarray(0, 4).toString('latin1')).toBe('%PDF');

        // Schedule a weekly email; invalid recipients are rejected, valid ones listed.
        await page.getByLabel('Plantilla a enviar').selectOption({ label: tplName });
        const recipients = page.getByLabel('Destinatarios');
        await recipients.fill('cliente@example.com, no-es-un-email');
        await page.getByRole('button', { name: 'Programar informe', exact: true }).click();
        await expect(recipients).toHaveAttribute('aria-invalid', 'true');
        await expect(recipients).toBeFocused();
        await expect(recipients).toHaveAccessibleDescription(/email|correo|válid/i);
        await expect(page.locator('[data-schedule]')).toHaveCount(0);
        await page.getByLabel('Plantilla a enviar').selectOption({ label: tplName });
        await page.getByLabel('Destinatarios').fill('Cliente@example.com, equipo@example.com');
        await page.getByRole('button', { name: 'Programar informe', exact: true }).click();
        const schedule = page.locator('[data-schedule]');
        await expect(schedule).toHaveCount(1);
        await expect(schedule).toContainText('cliente@example.com, equipo@example.com');
        await expect(schedule).toContainText('Semanal');
        await expectNoOverflow(page);
        await axeNow(page, 'informes programados');

        // Switching brand never mixes templates, schedules or the preview of the other brand.
        await page.getByRole('button', { name: 'Vista previa', exact: true }).click();
        await expect(page.locator('[data-preview]')).toContainText(a.name);
        await page.getByLabel('Marca', { exact: true }).selectOption(String(z.id));
        await expect(page.locator('[data-preview]')).toHaveCount(0);
        await expect(page.locator('[data-schedule]')).toHaveCount(0);
        await expect(page.getByLabel('Plantilla guardada').locator('option', { hasText: tplName })).toHaveCount(0);
        await page.getByRole('button', { name: 'Vista previa', exact: true }).click();
        await expect(page.locator('[data-preview]')).toContainText(z.name);
        await expect(page.locator('[data-preview]')).not.toContainText(a.name);
        await expectNoOverflow(page);

        expect(errors.errors).toEqual([]);
    } finally {
        artisan(`App\\Models\\ReportTemplate::withoutUserScope()->where('name', 'like', 'e2e-plantilla-%')->get()->each->delete();`);
        cleanComposerBrands(user.email);
    }
});

test('E2E-13 · informes pausa las consultas ocultas y alcanza el tope', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    const brand = seedComposerBrand(user.email, `e2e-report-poll-${stamp()}`);
    const isPoll = (headers: Record<string, string>) =>
        headers['x-inertia-partial-data']?.split(',').sort().join(',') === 'pagination,polling,reports';
    let polls = 0;

    try {
        artisan(String.raw`
            $brand = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id});
            App\Models\Report::factory()->create(['brand_id' => $brand->id, 'user_id' => $brand->user_id, 'status' => App\Models\Report::STATUS_PENDING]);
        `);
        await loginAs(page, user);
        await page.addInitScript(() => {
            Object.defineProperty(document, 'hidden', {
                configurable: true,
                get: () => document.documentElement?.dataset.testVisibility !== 'visible',
            });
            Object.defineProperty(document, 'visibilityState', {
                configurable: true,
                get: () => document.hidden ? 'hidden' : 'visible',
            });
        });
        const setVisibility = (visibility: 'visible' | 'hidden') => page.evaluate(state => {
            document.documentElement.dataset.testVisibility = state;
            document.dispatchEvent(new Event('visibilitychange'));
        }, visibility);
        page.on('request', request => {
            if (isPoll(request.headers())) polls++;
        });

        const expectNoPollDuring = async (milliseconds: number) => {
            const requestObserved = page.waitForRequest(request => isPoll(request.headers()), { timeout: 750 })
                .then(() => true, error => {
                    if (error instanceof errors.TimeoutError) return false;
                    throw error;
                });
            await page.clock.fastForward(milliseconds);
            expect(await requestObserved).toBe(false);
        };
        const start = new Date();
        await page.clock.install({ time: start });
        await page.clock.pauseAt(new Date(start.getTime() + 60_000));
        await page.goto(`/reports?brand_id=${brand.id}`);
        await expect(page.getByText('En cola', { exact: true })).toBeVisible();
        await expectNoPollDuring(60_000);
        await setVisibility('visible');

        const backoff = [4_000, 8_000, 16_000, 30_000];
        for (let attempt = 0; attempt < 40; attempt++) {
            const delay = backoff[attempt];
            // Pin the initial steps and the first capped interval before running the rest of the budget.
            if (delay) await expectNoPollDuring(delay - 200);
            const nextPoll = page.waitForResponse(response => isPoll(response.request().headers()));
            await page.clock.fastForward(delay ? 400 : 30_100);
            expect(await (await nextPoll).finished()).toBeNull();
            await page.clock.runFor(30);
            expect(polls).toBe(attempt + 1);
            if (attempt === 0) {
                await setVisibility('hidden');
                await expectNoPollDuring(60_000);
                // The 8 s interval is measured from resuming, rather than from the last completed request.
                await setVisibility('visible');
            }
        }

        await expect(page.getByRole('button', { name: 'Volver a consultar' })).toBeVisible();
        await expectNoPollDuring(60_000);
        expect(polls).toBe(40);
    } finally {
        cleanComposerBrands(user.email);
    }
});
