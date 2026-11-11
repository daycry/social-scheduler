import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type BrowserContext, type Page, type TestInfo } from '@playwright/test';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

const GUEST = { name: 'e2e Colaboradora', password: 'E2e-Collab-Disposable-Aa1!' };
const OWNER_PANEL = 'Compartir y comentarios';

const watched = new Map<Page, string[]>();
function watch(page: Page) {
    const errors: string[] = [];
    watched.set(page, errors);
    page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
    // 404/409 are the expected answers of revoked access and stale saves; the browser logs them as failed resources.
    page.on('console', message => { if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) errors.push(`console: ${message.text()}`); });
    return page;
}

async function newClient(browser: Browser, info: TestInfo): Promise<{ context: BrowserContext; page: Page }> {
    const use = info.project.use;
    const context = await browser.newContext({ baseURL: use.baseURL, viewport: use.viewport ?? undefined, isMobile: use.isMobile, hasTouch: use.hasTouch, locale: 'es-ES', timezoneId: 'Europe/Madrid' });
    return { context, page: watch(await context.newPage()) };
}

async function axeClean(page: Page, selector?: string) {
    const builder = new AxeBuilder({ page });
    const result = await (selector ? builder.include(selector) : builder).analyze();
    expect(result.violations).toEqual([]);
}

const openPanel = async (page: Page) => {
    await page.getByRole('button', { name: OWNER_PANEL, exact: true }).click();
    return page.getByRole('dialog', { name: OWNER_PANEL });
};
const closePanel = async (page: Page) => {
    await page.getByRole('dialog', { name: OWNER_PANEL }).getByRole('button', { name: 'Cerrar compartir y comentarios' }).click();
    await expect(page.getByRole('dialog', { name: OWNER_PANEL })).toBeHidden();
};
const ready = (page: Page) => expect(page.getByRole('button', { name: 'Añadir tabla', exact: true })).toBeEnabled();
const status = (page: Page) => page.getByTestId('studio-save-status');
const savedName = (page: Page, id: number) => page.evaluate(async designId => (await (await fetch(`/studio/designs/${designId}`, { headers: { Accept: 'application/json' } })).json()).design.name, id);

test('Studio: dos usuarios comparten, comentan, editan a la vez, ven el 409 y se revoca el acceso', async ({ browser, page }, info) => {
    test.setTimeout(240_000);
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `studio-collab-${stamp()}-${info.project.name}`);
    const guest = { email: `e2e-collab-${info.project.name}-${stamp()}@example.test`, ...GUEST };
    artisan(String.raw`$u = App\Models\User::firstOrNew(['email' => '${guest.email}']); $u->forceFill(['name' => '${guest.name}', 'password' => Illuminate\Support\Facades\Hash::make('${guest.password}'), 'email_verified_at' => now()])->save();`);
    const created = artisan(String.raw`$u = App\Models\User::where('email', '${owner.email}')->firstOrFail(); $b = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id}); $doc = App\Services\Studio\StudioDocument::blank(1080, 1080); $doc['pages'][0]['elements'] = [['id' => 'title', 'type' => 'text', 'x' => 40, 'y' => 60, 'width' => 800, 'height' => 120, 'text' => 'Rebajas de verano', 'fill' => '#111827', 'fontFamily' => 'Arial', 'fontSize' => 72]]; $d = app(App\Services\Studio\StudioService::class)->create($u, $b, ['name' => 'Campaña colaborativa', 'document' => $doc]); echo 'DESIGN:' . $d->id;`);
    const designId = Number(created.match(/DESIGN:(\d+)/)![1]);
    const edit = `/studio/designs/${designId}/edit`;
    const clients: BrowserContext[] = [];
    try {
        watch(page);
        await loginAs(page, owner);
        const member = await newClient(browser, info);
        const anonymous = await newClient(browser, info);
        clients.push(member.context, anonymous.context);
        await loginAs(member.page, { ...guest });

        // 1. La propietaria comparte por email con rol de comentarista.
        await page.goto(edit);
        await ready(page);
        let panel = await openPanel(page);
        await panel.getByRole('tab', { name: 'Compartir' }).click();
        await panel.getByLabel('Email', { exact: true }).fill('nadie@example.test');
        await panel.getByLabel('Rol', { exact: true }).selectOption('commenter');
        await panel.getByRole('button', { name: 'Compartir', exact: true }).click();
        // Respuesta uniforme: no revela si el email existe y no crea ningún acceso.
        await expect(panel.getByRole('status').filter({ hasText: 'Si existe una cuenta con ese email, tendrá acceso a este diseño.' })).toBeVisible();
        await expect(panel.getByTestId('studio-share-row')).toHaveCount(0);
        await panel.getByLabel('Email', { exact: true }).fill(guest.email);
        await panel.getByRole('button', { name: 'Compartir', exact: true }).click();
        await expect(panel.getByTestId('studio-share-row')).toContainText(guest.email);
        await axeClean(page, 'dialog[open]');

        // 2. La invitada ve el diseño en «Compartidos conmigo» y lo revisa como comentarista (sin editor).
        await member.page.goto('/studio');
        const shared = member.page.getByTestId('studio-shared');
        await expect(shared).toContainText('Campaña colaborativa');
        await expect(shared).toContainText('Comentarista');
        await shared.getByRole('link', { name: /Campaña colaborativa/ }).click();
        await expect(member.page.getByTestId('studio-review')).toBeVisible();
        await expect(member.page.getByTestId('studio-review-page')).toBeVisible();
        await expect(member.page.getByRole('button', { name: 'Añadir tabla' })).toHaveCount(0);
        const patch = await member.page.evaluate(async id => {
            const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            return (await fetch(`/studio/designs/${id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf }, body: JSON.stringify({ name: 'Intruso', document: {}, revision: 1 }) })).status;
        }, designId);
        expect(patch).toBe(403);
        await member.page.getByLabel('Nuevo comentario').fill('Revisar el título de esta página');
        await member.page.getByLabel('Dónde se aplica').selectOption('page');
        await member.page.getByRole('button', { name: 'Publicar comentario' }).click();
        await expect(member.page.getByTestId('studio-comment-thread')).toContainText('Revisar el título de esta página');
        await axeClean(member.page);
        await expectNoOverflow(member.page);
        await member.page.getByRole('tab', { name: 'Versiones' }).click();
        await expect(member.page.getByTestId('studio-version-row')).toHaveCount(1);
        await expect(member.page.getByRole('button', { name: /Restaurar/ })).toHaveCount(0);

        // 3. La propietaria ve el comentario, responde y lo resuelve.
        await closePanel(page);
        panel = await openPanel(page);
        const thread = panel.getByTestId('studio-comment-thread').first();
        await expect(thread).toContainText('Revisar el título de esta página');
        await expect(thread).toContainText(guest.name);
        await thread.getByRole('button', { name: 'Responder' }).click();
        await panel.getByLabel('Respuesta', { exact: true }).fill('Corregido, gracias');
        await panel.getByRole('button', { name: 'Publicar respuesta' }).click();
        await expect(thread).toContainText('Corregido, gracias');
        await thread.getByRole('button', { name: 'Resolver' }).click();
        await expect(panel.getByText('No hay hilos abiertos.')).toBeVisible();
        await panel.getByLabel(/Mostrar hilos resueltos/).check();
        await expect(panel.getByTestId('studio-comment-thread').first()).toContainText('Resuelto');
        await panel.getByRole('button', { name: 'Reabrir' }).click();
        await expect(panel.getByTestId('studio-comment-thread').first()).not.toContainText('Resuelto por');

        // 4. Sube el rol a editor: la invitada abre el editor completo.
        await panel.getByRole('tab', { name: 'Compartir' }).click();
        await panel.getByLabel(`Rol de ${guest.email}`).selectOption('editor');
        await expect(panel.getByText('Rol actualizado.')).toBeVisible();
        await closePanel(page);
        await member.page.goto(edit);
        await ready(member.page);
        await expect(member.page.getByText(/Editas el diseño de .* como editor/)).toBeVisible();
        await expect(member.page.getByRole('button', { name: 'Exportar', exact: true })).toHaveCount(0);

        // 5. Edición concurrente: la invitada guarda primero y la propietaria recibe el 409 con salida sin perder su trabajo.
        await member.page.getByLabel('Nombre del diseño').fill('Versión de la invitada');
        await member.page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(status(member.page)).toHaveText('Guardado');
        await page.getByLabel('Nombre del diseño').fill('Versión de la propietaria');
        await expect(status(page)).toHaveText('Conflicto de revisión');
        await expect(page.getByRole('alert').first()).toContainText(`El diseño cambió en otra sesión (${guest.name})`);
        await expect(page.getByRole('button', { name: 'Guardar como copia' })).toBeVisible();
        await axeClean(page, '[role="alert"]');
        expect(await savedName(page, designId)).toBe('Versión de la invitada');
        await page.getByRole('button', { name: 'Guardar como copia' }).click();
        await expect(page).toHaveURL(new RegExp(`/studio/designs/(?!${designId}/)\\d+/edit$`));
        await ready(page);
        await expect(page.getByLabel('Nombre del diseño')).toHaveValue('Versión de la propietaria (mi copia)');
        expect(await savedName(page, designId)).toBe('Versión de la invitada');

        // 6. Versiones: la propietaria restaura la primera como una versión nueva.
        await page.goto(edit);
        await ready(page);
        panel = await openPanel(page);
        await panel.getByRole('tab', { name: 'Versiones' }).click();
        await expect(panel.getByTestId('studio-version-row')).toHaveCount(2);
        await expect(panel.getByTestId('studio-version-row').first()).toContainText(guest.name);
        await panel.getByRole('button', { name: /Restaurar versión 1/ }).click();
        await expect(page.getByLabel('Nombre del diseño')).toHaveValue('Campaña colaborativa');
        await expect(page.getByText('Versión restaurada como una versión nueva.')).toBeVisible();
        expect(await savedName(page, designId)).toBe('Campaña colaborativa');

        // 7. Enlace de solo lectura: acceso anónimo, axe y revocación inmediata.
        panel = await openPanel(page);
        await panel.getByRole('tab', { name: 'Compartir' }).click();
        await panel.getByLabel('Permiso del enlace').selectOption('reader');
        await panel.getByRole('button', { name: 'Crear enlace' }).click();
        const readerUrl = await panel.getByTestId('studio-created-link').inputValue();
        expect(readerUrl).toMatch(/\/d\/[A-Za-z0-9]{48}$/);
        const opened = await anonymous.page.goto(readerUrl);
        expect(opened?.status()).toBe(200);
        await expect(anonymous.page.getByTestId('studio-review-page')).toBeVisible();
        await expect(anonymous.page.getByText('Acceso de lector por enlace')).toBeVisible();
        await expect(anonymous.page.getByLabel('Nuevo comentario')).toHaveCount(0);
        await axeClean(anonymous.page);
        await expectNoOverflow(anonymous.page);
        await panel.getByTestId('studio-link-row').first().getByRole('button', { name: /Revocar/ }).click();
        await expect(panel.getByText('Enlace revocado: ya no da acceso.')).toBeVisible();
        expect((await anonymous.page.goto(readerUrl))?.status()).toBe(404);

        // 8. Enlace con comentarios: el visitante comenta con su nombre y la propietaria lo ve como invitado.
        await panel.getByLabel('Permiso del enlace').selectOption('commenter');
        await panel.getByRole('button', { name: 'Crear enlace' }).click();
        const commentUrl = await panel.getByTestId('studio-created-link').inputValue();
        await anonymous.page.goto(commentUrl);
        await anonymous.page.getByLabel('Tu nombre').fill('Visitante');
        await anonymous.page.getByLabel('Nuevo comentario').fill('Me encanta la propuesta');
        await anonymous.page.getByRole('button', { name: 'Publicar comentario' }).click();
        await expect(anonymous.page.getByTestId('studio-comment-thread').filter({ hasText: 'Me encanta la propuesta' })).toBeVisible();
        await closePanel(page);
        panel = await openPanel(page);
        await expect(panel.getByTestId('studio-comment-thread').filter({ hasText: 'Me encanta la propuesta' })).toContainText('Visitante (invitado)');

        // 9. Revocación del acceso de la invitada: el servidor responde 404 en la página, la API y la lista.
        await panel.getByRole('tab', { name: 'Compartir' }).click();
        await panel.getByRole('button', { name: new RegExp(`Quitar acceso a ${guest.email}`) }).click();
        await expect(panel.getByText(`Acceso de ${guest.email} revocado.`)).toBeVisible();
        expect((await member.page.goto(edit))?.status()).toBe(404);
        expect(await member.page.evaluate(async id => (await fetch(`/studio/designs/${id}`, { headers: { Accept: 'application/json' } })).status, designId)).toBe(404);
        expect(await member.page.evaluate(async id => (await fetch(`/studio/designs/${id}/comments`, { headers: { Accept: 'application/json' } })).status, designId)).toBe(404);
        await member.page.goto('/studio');
        await expect(member.page.getByTestId('studio-shared')).toHaveCount(0);
        await expectNoOverflow(page);

        for (const [client, errors] of watched) expect(errors, `errores de navegador en ${client.url()}`).toEqual([]);
    } finally {
        for (const context of clients) await context.close().catch(() => {});
        cleanComposerBrands(owner.email);
        artisan(String.raw`$u = App\Models\User::where('email', '${guest.email}')->first(); if ($u) { Illuminate\Support\Facades\DB::table('audit_logs')->where('user_id', $u->id)->delete(); $u->delete(); }`);
    }
});
