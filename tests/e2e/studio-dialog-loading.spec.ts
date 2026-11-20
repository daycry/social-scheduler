import fs from 'node:fs';
import { expect, test } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

for (const failure of ['dialog', 'pdf'] as const) {
    test(`Studio: reports a ${failure} chunk failure and keeps the editor usable`, { tag: '@responsive' }, async ({ page }, info) => {
        const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
        const key = failure === 'dialog'
            ? 'resources/js/Components/Studio/ExportDialog.vue'
            : 'node_modules/pdf-lib/es/index.js';
        expect(manifest[key]?.file).toBeTruthy();
        const chunkPath = '/build/' + manifest[key].file;
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        const brand = seedComposerBrand(user.email, `studio-load-${failure}-${stamp()}-${info.project.name}`);
        const errors: string[] = [];
        const requests: string[] = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => {
            if (message.type() !== 'error') return;
            const url = message.location().url;
            if (url && new URL(url).pathname === chunkPath && message.text().includes('net::ERR_FAILED')) return;
            errors.push(message.text());
        });
        page.on('request', request => {
            if (request.method() === 'POST' && new RegExp('^/studio/(designs/[0-9]+/exports|exports/[0-9]+/post)$').test(new URL(request.url()).pathname)) {
                requests.push(request.url());
            }
        });

        try {
            await loginAs(page, user);
            await page.goto(`/studio?brand_id=${brand.id}`);
            await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
            const create = page.getByRole('dialog', { name: 'Nuevo diseño' });
            await create.getByLabel('Ancho', { exact: true }).fill('40');
            await create.getByLabel('Alto', { exact: true }).fill('40');
            await create.getByRole('button', { name: 'Crear diseño', exact: true }).click();
            await expect(page.getByRole('button', { name: 'Añadir texto', exact: true })).toBeEnabled();
            await page.reload();
            await expect(page.getByRole('button', { name: 'Añadir texto', exact: true })).toBeEnabled();
            await page.route(url => url.pathname === chunkPath, route => route.abort('failed'));

            if (failure === 'dialog') {
                const publish = page.getByRole('button', { name: 'Usar en publicación', exact: true });
                await publish.click();
                await expect(page.getByText('No se pudo abrir la exportación. Recarga la página.', { exact: true })).toBeVisible();
                await expect(publish).toBeEnabled();
                await expect(page.getByRole('dialog', { name: 'Exportar diseño' })).toHaveCount(0);
            } else {
                await page.getByRole('button', { name: 'Exportar', exact: true }).click();
                const dialog = page.getByRole('dialog', { name: 'Exportar diseño' });
                await dialog.getByLabel('Formato', { exact: true }).selectOption('pdf');
                await dialog.getByRole('button', { name: 'Descargar', exact: true }).click();
                await expect(dialog.getByRole('alert')).toHaveText('No se pudo cargar el generador de PDF. Recarga la página.');
                await expect(dialog.getByRole('button', { name: 'Descargar', exact: true })).toBeEnabled();
                await dialog.getByRole('button', { name: 'Cerrar exportación', exact: true }).click();
            }

            await expect(page.getByRole('button', { name: 'Añadir texto', exact: true })).toBeEnabled();
            expect(requests).toEqual([]);
            expect(errors).toEqual([]);
            await expectNoOverflow(page);
        } finally {
            cleanComposerBrands(user.email);
        }
    });
}
