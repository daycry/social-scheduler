import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { artisan, cleanCanvaMedia, cleanComposerBrands, e2eUser, loginAs, seedComposerBrand, stamp } from './helpers';
import { runRoot } from './isolated/runtime';

async function openNewDesign(page: Page, brandId: number, name: string) {
    await page.goto(`/studio?brand_id=${brandId}`);
    await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Nuevo diseño' });
    await dialog.getByLabel('Nombre', { exact: true }).fill(name);
    await dialog.getByRole('button', { name: 'Crear diseño', exact: true }).click();
    await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
    await expect(page.getByLabel('Nombre del diseño')).toHaveValue(name);
}

async function capture(page: Page, info: TestInfo, name: string) {
    await info.attach(name, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
}

test('E2E-41 · puentes · actualizar la publicación desde su diseño', { tag: '@responsive' }, async ({ page }, info) => {
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `bridge-post-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, owner);
        await openNewDesign(page, brand.id, 'Diseño vinculado');
        const designPath = new URL(page.url()).pathname;
        await page.getByRole('button', { name: 'Usar en publicación', exact: true }).click();
        await expect(page).toHaveURL(/\/posts\/\d+\/edit$/);
        const postPath = new URL(page.url()).pathname;
        const thumbnail = page.locator('aside img');
        await expect(thumbnail).toBeVisible();
        const firstPreview = await thumbnail.getAttribute('src');
        await capture(page, info, 'publicación-antes-de-reexportar');

        const edit = page.getByRole('link', { name: 'Editar en el Estudio' });
        await expect(edit).toBeVisible();
        await edit.click();
        const action = page.getByRole('button', { name: 'Actualizar publicación', exact: true });
        await expect(action).toBeEnabled();
        const returned = new URL(page.url());
        expect(returned.pathname).toBe(designPath);
        expect(returned.searchParams.get('brand_id')).toBe(String(brand.id));
        await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await page.getByLabel('Contenido del texto', { exact: true }).fill('Segunda versión');
        await action.click();
        const exportDialog = page.getByRole('dialog', { name: 'Exportar diseño' });
        const registration = page.waitForResponse(response => response.request().method() === 'POST'
            && new URL(response.url()).pathname.endsWith('/exports'));
        const update = page.waitForResponse(response => response.request().method() === 'POST'
            && /\/studio\/exports\/\d+\/post$/.test(new URL(response.url()).pathname));
        await exportDialog.getByRole('button', { name: 'Actualizar publicación', exact: true }).click();
        const exported = await (await registration).json();
        const response = await update;
        expect(response.status()).toBe(200);
        expect((await response.json()).post.edit_url).toBe(postPath);

        await expect(page).toHaveURL(url => url.pathname === postPath);
        await expect(thumbnail).not.toHaveAttribute('src', firstPreview!);
        await expect(thumbnail).toHaveAttribute('src', new RegExp(`/media/${exported.export.media_ids[0]}(?:/|$)`));
        await expect(page.getByRole('button', { name: 'Quitar', exact: true })).toHaveCount(1);
        await capture(page, info, 'publicación-reexportada');

        const postId = Number(postPath.match(/\/posts\/(\d+)\/edit$/)?.[1]);
        expect(postId).toBeGreaterThan(0);
        artisan(String.raw`App\Models\Post::withoutUserScope()->where('brand_id', ${brand.id})->findOrFail(${postId})->forceFill(['status' => 'publicada'])->save();`);
        await page.getByRole('link', { name: 'Editar en el Estudio' }).click();
        await page.getByRole('button', { name: 'Actualizar publicación', exact: true }).click();
        const publishedAttempt = page.waitForResponse(response => response.request().method() === 'POST'
            && /\/studio\/exports\/\d+\/post$/.test(new URL(response.url()).pathname));
        await exportDialog.getByRole('button', { name: 'Actualizar publicación', exact: true }).click();
        expect((await publishedAttempt).status()).toBe(422);
        await expect(exportDialog.getByRole('alert')).toContainText('La publicación ya se ha publicado o se está publicando');
        const failedExportPath = new URL((await publishedAttempt).url()).pathname;
        const draftAttempt = page.waitForResponse(response => response.request().method() === 'POST'
            && new URL(response.url()).pathname === failedExportPath);
        const recovery = exportDialog.getByRole('button', { name: 'Crear borrador con esta exportación', exact: true });
        await expect(recovery).toBeFocused();
        await recovery.click();
        const draft = await draftAttempt;
        expect(draft.status()).toBe(201);
        expect(draft.request().postDataJSON()).toEqual({});
        const draftPath = (await draft.json()).post.edit_url;
        expect(draftPath).not.toBe(postPath);
        await expect(page).toHaveURL(url => url.pathname === draftPath);
        await expect(page.getByRole('link', { name: 'Editar en el Estudio' })).toBeVisible();
    } finally {
        cleanComposerBrands(owner.email);
    }
});

test('E2E-42 · puentes · importar Canva dos veces y conservar un recurso', { tag: '@responsive' }, async ({ page }, info) => {
    const owner = e2eUser(info.project.name);
    const canvaFlag = path.join(runRoot, 'canva-on');
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `bridge-canva-${stamp()}-${info.project.name}`);
    fs.writeFileSync(canvaFlag, 'Canva falso');
    try {
        await loginAs(page, owner);
        artisan(String.raw`$brand = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id});
            App\Models\Canva\CanvaConnection::create(['user_id' => $brand->user_id, 'brand_id' => $brand->id,
                'access_token' => 'e2e-canva-access', 'refresh_token' => 'e2e-canva-refresh', 'expires_at' => now()->addHour(),
                'scopes' => config('services.canva.scopes'), 'capabilities' => [], 'capabilities_at' => now(), 'status' => 'active']);`);
        await openNewDesign(page, brand.id, 'Recursos importados');
        const designPath = new URL(page.url()).pathname;
        const resource = page.getByRole('button', { name: 'Añadir Rebajas E2E.png', exact: true });
        await page.getByRole('button', { name: 'Recursos', exact: true }).click();
        await expect(resource).toHaveCount(0);
        await capture(page, info, 'recursos-antes-de-importar');

        for (let attempt = 0; attempt < 2; attempt++) {
            await page.goto(`/brands/${brand.id}/canva`);
            const start = page.waitForResponse(response => response.request().method() === 'POST'
                && new URL(response.url()).pathname.endsWith('/canva/exports'));
            await page.getByTestId('canva-design-DAFe2eRebaj1').getByRole('button', { name: 'Exportar a la biblioteca' }).click();
            const { job } = await (await start).json();
            const card = page.getByTestId(`canva-job-${job.id}`);
            await expect(card.getByRole('status')).toHaveText('En cola');
            expect(artisan(String.raw`for ($i = 0; $i < 40 && ($job = Illuminate\Support\Facades\Queue::connection('database')->pop('media')); $i++) { $job->fire(); } echo 'QUEUE:done';`)).toContain('QUEUE:done');
            await expect(card.getByRole('status')).toHaveText('Listo', { timeout: 20_000 });
            await page.goto(designPath);
            await page.getByRole('button', { name: 'Recursos', exact: true }).click();
            await expect(resource).toBeVisible();
            await expect(resource).toHaveCount(1);
        }
        await capture(page, info, 'recursos-después-de-reimportar');
    } finally {
        fs.rmSync(canvaFlag, { force: true });
        cleanCanvaMedia(owner.email);
        cleanComposerBrands(owner.email);
    }
});

test('T-101 · restablecer un destino y volver a personalizarlo conserva el origen', { tag: '@responsive' }, async ({ page }, info) => {
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `restore-studio-media-${stamp()}`);
    try {
        await loginAs(page, owner);
        await openNewDesign(page, brand.id, 'Diseño para una red');
        await page.getByRole('button', { name: 'Usar en publicación', exact: true }).click();
        await expect(page).toHaveURL(/\/posts\/\d+\/edit$/);
        const postPath = new URL(page.url()).pathname;
        const postId = Number(postPath.match(/\/posts\/(\d+)\/edit$/)?.[1]);
        const ids = JSON.parse(artisan(String.raw`
            $post = App\Models\Post::withoutUserScope()->findOrFail(${postId});
            $account = App\Models\SocialAccount::withoutUserScope()->where('brand_id', ${brand.id})->where('network', 'facebook')->sole();
            app(App\Services\PostService::class)->update($post->user, $post, ['media' => $post->media->modelKeys(), 'targets' => [
                ['account_id' => $account->id, 'content_type' => 'facebook_photo']
            ]]);
            echo json_encode(['account' => $account->id, 'origin' => $post->design_export_id]);
        `));
        await page.reload();
        await page.getByRole('button', { name: 'Editar por red', exact: true }).click();
        await page.getByTestId(`tab-${ids.account}`).click();
        await page.getByTestId('target-media-customize').click();
        await page.getByTestId('target-media-reset').click();
        await page.getByTestId('target-media-customize').click();
        const saved = page.waitForResponse(response => response.request().method() === 'PUT'
            && new URL(response.url()).pathname === postPath.replace(/\/edit$/, ''));
        await page.getByRole('button', { name: 'Más acciones de publicación', exact: true }).click();
        await page.getByRole('menuitem', { name: 'Guardar borrador', exact: true }).click();
        expect((await saved).status()).toBe(303);
        await expect(page.getByRole('status').filter({ hasText: 'Borrador guardado.' })).toBeVisible();
        const origin = artisan(String.raw`
            echo json_encode(App\Models\Post::withoutUserScope()->findOrFail(${postId})->targets()->sole()->mediaItems()->sole()->design_export_id);
        `);
        expect(JSON.parse(origin)).toBe(ids.origin);
    } finally {
        cleanComposerBrands(owner.email);
    }
});

test('T-106 · biblioteca · subir desde el compositor y elegir el mismo archivo', { tag: '@responsive' }, async ({ page, context }, info) => {
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `shared-upload-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, owner);
        await page.goto(`/brands/${brand.id}/posts/create`);
        const composerFile = page.getByTestId('editor-toolbar').locator('input[type="file"]');
        const assetPath = `/brands/${brand.id}/studio/assets`;
        let failFirstLink!: () => void;
        const pendingLink = new Promise<void>(resolve => { failFirstLink = resolve; });
        await page.route(`**${assetPath}`, async route => {
            await pendingLink;
            await route.fulfill({ status: 503, json: { message: 'Temporalmente no disponible' } });
        }, { times: 1 });
        const firstAttempt = page.waitForRequest(request => request.method() === 'POST' && new URL(request.url()).pathname === assetPath);
        try {
            await composerFile.setInputFiles({
                name: 'imagen-del-compositor.png', mimeType: 'image/png',
                buffer: fs.readFileSync(path.resolve('tests/Fixtures/images/astronaut.png')),
            });
            await firstAttempt;
            await expect(composerFile).toBeEnabled();
            await expect(page.getByTestId('uploading-note')).toHaveCount(0);
        } finally {
            failFirstLink();
        }
        const linked = page.waitForResponse(response => response.request().method() === 'POST'
            && new URL(response.url()).pathname === assetPath && response.status() === 201);
        await page.getByRole('button', { name: 'Reintentar vínculo de imagen-del-compositor.png', exact: true }).click();
        const attachment = await linked;
        expect(attachment.status()).toBe(201);
        const { asset } = await attachment.json();
        const repeated = await page.evaluate(async ({ assetPath, id }) => {
            const token = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const response = await fetch(assetPath, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
                body: JSON.stringify({ media_id: id }),
            });
            return { status: response.status, id: (await response.json()).asset?.id };
        }, { assetPath, id: Number(asset.id) });
        expect(repeated).toEqual({ status: 201, id: asset.id });
        await expect(page.getByRole('button', { name: 'Quitar', exact: true })).toHaveCount(1);

        const editor = await context.newPage();
        try {
            await openNewDesign(editor, brand.id, 'Imagen del compositor');
            await editor.getByRole('button', { name: 'Recursos', exact: true }).click();
            const resource = editor.getByRole('button', { name: 'Añadir imagen-del-compositor.png', exact: true });
            await expect(resource).toHaveCount(1);
            await expect(resource.locator('img')).toHaveAttribute('src', new RegExp(`/assets/${asset.id}/file$`));
            await capture(editor, info, 'recurso-subido-desde-el-compositor');
        } finally {
            await editor.close();
        }

        await page.getByRole('button', { name: 'Quitar', exact: true }).click();
        await page.getByRole('button', { name: 'Desde la biblioteca', exact: true }).click();
        const picker = page.getByRole('dialog', { name: 'Desde la biblioteca' });
        const item = picker.getByRole('button', { name: 'Añadir imagen-del-compositor.png', exact: true });
        await expect(item).toHaveCount(1);
        await item.click();
        await expect(picker).toBeHidden();
        await expect(page.getByRole('button', { name: 'Quitar', exact: true })).toHaveCount(1);
        await capture(page, info, 'archivo-seleccionado-de-la-biblioteca');

        const openImage = async (source: Page) => {
            const newTab = source.waitForEvent('popup');
            await source.getByRole('button', { name: 'Abrir en el Estudio, imagen-del-compositor.png, abre en una pestaña nueva', exact: true }).click();
            const studio = await newTab;
            try {
                await expect(studio).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
                await expect(studio.getByLabel('Nombre del diseño')).toHaveValue('imagen-del-compositor.png');
                await expect(studio.getByTestId('studio-layer')).toHaveCount(1);
            } finally {
                await studio.close();
            }
        };
        const text = page.getByLabel('Texto', { exact: true });
        await text.fill('Borrador todavía sin guardar');
        const openInStudio = page.getByRole('button', { name: 'Abrir en el Estudio, imagen-del-compositor.png, abre en una pestaña nueva', exact: true });
        await context.route('**/studio/designs/from-image', async route => {
            const form = new URLSearchParams(route.request().postData() ?? '');
            form.set('media_id', '2147483647');
            await route.continue({ postData: form.toString() });
        }, { times: 1 });
        await openInStudio.focus();
        const popup = page.waitForEvent('popup');
        await openInStudio.click();
        const errorTab = await popup;
        try {
            await expect(errorTab).toHaveURL(new RegExp(`/brands/${brand.id}/studio/designs(?:[?#].*)?$`));
            await expect(errorTab.getByText('La imagen ya no está disponible.', { exact: true })).toBeVisible();
            await capture(errorTab, info, 'imagen-no-disponible-en-la-pestana-de-estudio');
        } finally {
            await errorTab.close();
        }
        await expect(text).toHaveValue('Borrador todavía sin guardar');
        if (info.project.name === 'desktop') await expect(openInStudio).toBeFocused();
        await expect(openInStudio).not.toHaveAttribute('aria-disabled', 'true');
        await openImage(page);
        await expect(text).toHaveValue('Borrador todavía sin guardar');
        await expect(page).toHaveURL(/\/posts\/create$/);
        await expect(page.getByRole('button', { name: 'Quitar', exact: true })).toHaveCount(1);

        artisan(String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id});
            app(App\Services\LibraryService::class)->store($b->user, $b, 'Plantilla con imagen', null, [${Number(asset.id)}]);`);
        const library = await context.newPage();
        try {
            await library.goto(`/library?brand_id=${brand.id}`);
            await expect(library.getByText('Plantilla con imagen', { exact: true })).toBeVisible();
            await openImage(library);
        } finally {
            await library.close();
        }
    } finally {
        cleanComposerBrands(owner.email);
    }
});

test('T-106 · conserva como independiente un archivo del diseño que se vuelve a elegir', { tag: '@responsive' }, async ({ page }, info) => {
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `reattach-design-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, owner);
        await openNewDesign(page, brand.id, 'Diseño para volver a adjuntar');
        await page.getByRole('button', { name: 'Usar en publicación', exact: true }).click();
        await expect(page).toHaveURL(/\/posts\/\d+\/edit$/);
        const postPath = new URL(page.url()).pathname;
        const thumbnail = page.locator('aside img');
        await expect(thumbnail).toBeVisible();
        const preview = await thumbnail.getAttribute('src');
        await page.getByRole('button', { name: 'Quitar', exact: true }).click();
        await page.getByRole('button', { name: 'Desde la biblioteca', exact: true }).click();
        const picker = page.getByRole('dialog', { name: 'Desde la biblioteca' });
        await picker.getByRole('button', { name: 'Añadir Diseño para volver a adjuntar-1.png', exact: true }).click();
        await expect(picker).toBeHidden();
        await expect(thumbnail).toHaveAttribute('src', preview!);

        const saved = page.waitForResponse(response => response.request().method() === 'PUT'
            && new URL(response.url()).pathname === postPath.replace(/\/edit$/, ''));
        await page.getByRole('button', { name: 'Más acciones de publicación', exact: true }).click();
        await page.getByRole('menuitem', { name: 'Guardar borrador', exact: true }).click();
        expect((await saved).status()).toBe(303);
        await expect(page.getByRole('status').filter({ hasText: 'Borrador guardado.' })).toBeVisible();

        await page.getByRole('link', { name: 'Editar en el Estudio' }).click();
        await page.getByRole('button', { name: 'Actualizar publicación', exact: true }).click();
        const exportDialog = page.getByRole('dialog', { name: 'Exportar diseño' });
        const updated = page.waitForResponse(response => response.request().method() === 'POST'
            && /\/studio\/exports\/\d+\/post$/.test(new URL(response.url()).pathname));
        await exportDialog.getByRole('button', { name: 'Actualizar publicación', exact: true }).click();
        expect((await updated).status()).toBe(200);
        await expect(page).toHaveURL(url => url.pathname === postPath);
        await expect(thumbnail).toHaveAttribute('src', preview!);
        await expect(page.getByRole('button', { name: 'Quitar', exact: true })).toHaveCount(1);
    } finally {
        cleanComposerBrands(owner.email);
    }
});

test('T-106 · el reintento no recuperable enfoca el aviso y después el cargador', { tag: '@responsive' }, async ({ page }, info) => {
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `shared-upload-focus-${stamp()}`);
    try {
        await loginAs(page, owner);
        await page.goto(`/brands/${brand.id}/posts/create`);
        let attempts = 0;
        await page.route(`**/brands/${brand.id}/studio/assets`, async route => {
            if (route.request().method() !== 'POST') return route.fallback();
            attempts++;
            await route.fulfill({ status: attempts === 1 ? 503 : 419, json: { message: 'Vínculo de prueba' } });
        });
        const uploader = page.getByTestId('editor-toolbar').locator('input[type="file"]');
        await uploader.setInputFiles({
            name: 'aviso-de-vinculo.png', mimeType: 'image/png',
            buffer: fs.readFileSync(path.resolve('tests/Fixtures/images/astronaut.png')),
        });
        const retry = page.getByRole('button', { name: 'Reintentar vínculo de aviso-de-vinculo.png', exact: true });
        await retry.focus();
        await retry.click();
        const dismiss = page.getByRole('button', { name: 'Descartar aviso de aviso-de-vinculo.png', exact: true });
        await expect(dismiss).toBeFocused();
        await expect(retry).toHaveCount(0);
        await expect(page.getByTestId('editor-toolbar').getByRole('listitem').getByText(/sesión.*caduc/i)).toBeVisible();
        await dismiss.click();
        await expect(uploader).toBeFocused();
        await expect(page.getByRole('button', { name: 'Quitar', exact: true })).toHaveCount(1);
        expect(attempts).toBe(2);
    } finally {
        cleanComposerBrands(owner.email);
    }
});
