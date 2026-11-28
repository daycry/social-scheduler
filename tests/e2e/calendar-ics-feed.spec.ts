import { expect, test, type Page } from '@playwright/test';
import {
    artisan,
    axeSeriousCritical,
    cleanComposerBrands,
    createExtraE2eUser,
    e2eUser,
    expectNoOverflow,
    loginAs,
    seedComposerBrand,
    settle,
    stamp,
    watch,
    type ComposerBrand,
} from './helpers';

/**
 * T-90 · Fechas señaladas y feed .ics (E2E-29). Las fechas señaladas se ven en el calendario y se ocultan (la preferencia persiste); el enlace
 * de suscripción se crea, se descarga como iCalendar válido con SOLO las publicaciones del usuario, se regenera (el anterior da 404, tras un
 * diálogo de confirmación) y se revoca. Usuarios `e2e-sec-*` y marcas `e2e-composer-*`: nada toca datos reales ni publica en redes.
 */
test.describe.configure({ mode: 'serial' });

/** Deja al usuario sin feed y con las fechas señaladas visibles. */
const resetFeed = (email: string) =>
    artisan(
        String.raw`App\Models\User::where('email', '${email}')->update(['calendar_feed_token_hash' => null, 'calendar_feed_token_at' => null, 'hide_marketing_dates' => false]);`,
    );

/** Una publicación propia programada en 3 días y otra de OTRO usuario con el mismo aspecto: la ajena nunca puede salir en el feed. */
function seedPosts(brandId: number, other: string, word: string) {
    artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`App\Models\Post::factory()->create(['brand_id' => $b->id, 'text' => 'e2e ics propia, con coma; ${word}', 'status' => App\Enums\PostStatus::Scheduled, 'scheduled_at' => now()->addDays(3)]); ` +
            String.raw`$o = App\Models\User::where('email', '${other}')->firstOrFail(); ` +
            String.raw`$ob = App\Models\Brand::factory()->for($o)->create(['name' => 'e2e-composer-ajena-${word}']); ` +
            String.raw`App\Models\Post::factory()->create(['brand_id' => $ob->id, 'text' => 'e2e ics ajena ${word}', 'status' => App\Enums\PostStatus::Scheduled, 'scheduled_at' => now()->addDays(3)]);`,
    );
}

const panel = (page: Page) => page.getByTestId('calendar-feed-panel');
const showDates = (page: Page) => panel(page).getByLabel(/Mostrar las fechas señaladas/);
const blackFriday = (page: Page) => page.getByTestId('marketing-date').filter({ hasText: 'Black Friday' });
const feedPath = (url: string) => new URL(url).pathname;

async function confirmDialog(page: Page, title: string, button: string) {
    const dialog = page.getByRole('alertdialog', { name: title, exact: true });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: button, exact: true }).click();
    await expect(dialog).toBeHidden();
    await settle(page);
}

test.describe('Fechas señaladas y feed .ics', { tag: '@responsive' }, () => {
    let brand: ComposerBrand;
    let otherEmail: string;
    let word: string;

    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        word = `ics${stamp()}`;
        cleanComposerBrands(user.email);
        resetFeed(user.email);
        brand = seedComposerBrand(user.email, `${word}-${info.project.name}`);
        otherEmail = createExtraE2eUser(`ics-${word}`);
        cleanComposerBrands(otherEmail);
        seedPosts(brand.id, otherEmail, word);
        await loginAs(page, user);
    });

    test.afterEach(async ({}, info) => {
        const user = e2eUser(info.project.name);
        try {
            resetFeed(user.email);
        } finally {
            cleanComposerBrands(user.email);
            cleanComposerBrands(otherEmail);
        }
    });

    test('E2E-29 · fechas señaladas visibles y ocultables; el .ics es válido, solo del usuario, regenerable y revocable', async ({
        page,
    }, info) => {
        const w = watch(page);
        await page.goto('/calendar?view=month&date=2027-11-15');
        await settle(page);

        // 1. Las fechas señaladas se ven, distinguibles de las publicaciones, y se ocultan; la preferencia sobrevive a recargar.
        await expect(blackFriday(page)).toBeVisible();
        await expectNoOverflow(page);
        // Las etiquetas (también las de texto solo para lectores de pantalla) no ensanchan la página en móvil, con el nombre más largo en la semana.
        await page.goto('/calendar?view=week&date=2027-04-28');
        await settle(page);
        await expect(page.getByTestId('marketing-date').filter({ hasText: 'Día de la Madre (España)' })).toBeVisible();
        await expectNoOverflow(page);
        await page.goto('/calendar?view=week&date=2027-11-24');
        await settle(page);
        await expect(blackFriday(page)).toBeVisible();
        await expectNoOverflow(page);
        await page.goto('/calendar?view=month&date=2027-11-15');
        await settle(page);
        await expect(showDates(page)).toBeChecked();
        await page.screenshot({ path: info.outputPath('calendario-fechas-inicial.png'), fullPage: true });
        await showDates(page).uncheck();
        await settle(page);
        await expect(blackFriday(page)).toHaveCount(0);
        await page.reload();
        await settle(page);
        await expect(showDates(page)).not.toBeChecked();
        await expect(blackFriday(page)).toHaveCount(0);
        await showDates(page).check();
        await settle(page);
        await expect(blackFriday(page)).toBeVisible();

        // 2. Crear el enlace: se muestra una vez y el .ics es válido y solo contiene lo del usuario.
        await panel(page).getByRole('button', { name: 'Crear enlace de suscripción', exact: true }).click();
        await settle(page);
        const urlField = panel(page).getByLabel(/Tu enlace/);
        await expect(urlField).toBeVisible();
        const url = await urlField.inputValue();
        expect(url).toMatch(/\/calendar\/feed\/[A-Za-z0-9_-]{43}\.ics$/);
        await page.screenshot({ path: info.outputPath('calendario-feed-creado.png'), fullPage: true });

        const feed = await page.request.get(feedPath(url));
        expect(feed.status()).toBe(200);
        expect(feed.headers()['content-type']).toContain('text/calendar');
        expect(feed.headers()['content-type']).toContain('charset=utf-8');
        expect(feed.headers()['cache-control']).toContain('no-store');
        expect(feed.headers()['x-robots-tag']).toContain('noindex');
        const body = await feed.text();
        expect(body.startsWith('BEGIN:VCALENDAR\r\n')).toBe(true);
        expect(body.endsWith('END:VCALENDAR\r\n')).toBe(true);
        expect(body).toContain('VERSION:2.0');
        expect(body).toMatch(/PRODID:/);
        expect(body).toMatch(/DTSTART:\d{8}T\d{6}Z/);
        expect(body).not.toMatch(/(?<!\r)\n/);
        for (const line of body.split('\r\n')) expect(Buffer.byteLength(line)).toBeLessThanOrEqual(75);
        const unfolded = body.replace(/\r\n[ \t]/g, '');
        expect(unfolded).toContain(`e2e ics propia\\, con coma\\; ${word}`);
        expect(unfolded).not.toContain(`e2e ics ajena ${word}`);
        expect(unfolded).not.toContain(`e2e-composer-ajena-${word}`);
        expect(unfolded).toContain('SUMMARY:Navidad');

        // 3. Con las fechas ocultas, el feed no las lleva (las publicaciones sí).
        await showDates(page).uncheck();
        await settle(page);
        const hidden = (await (await page.request.get(feedPath(url))).text()).replace(/\r\n[ \t]/g, '');
        expect(hidden).not.toContain('SUMMARY:Navidad');
        expect(hidden).toContain(`e2e ics propia\\, con coma\\; ${word}`);
        await expect(urlField).toBeVisible(); // la URL recién creada sigue en el panel tras cambiar la casilla
        await showDates(page).check();
        await settle(page);

        // 4. Tras recargar, la URL ya no se muestra (solo el hash está guardado).
        await page.reload();
        await settle(page);
        await expect(panel(page).getByTestId('calendar-feed-active')).toBeVisible();
        await expect(panel(page).getByLabel(/Tu enlace/)).toHaveCount(0);

        // 5. Regenerar pide confirmación; el enlace anterior da 404 y el nuevo funciona.
        await panel(page).getByRole('button', { name: 'Regenerar enlace', exact: true }).click();
        await confirmDialog(page, 'Regenerar el enlace', 'Regenerar');
        const fresh = await panel(page)
            .getByLabel(/Tu enlace/)
            .inputValue();
        expect(fresh).not.toBe(url);
        expect((await page.request.get(feedPath(url))).status()).toBe(404);
        expect((await page.request.get(feedPath(fresh))).status()).toBe(200);

        // 6. Revocar también pide confirmación y deja el feed sin servicio.
        await panel(page).getByRole('button', { name: 'Revocar enlace', exact: true }).click();
        await confirmDialog(page, 'Revocar el enlace', 'Revocar');
        await expect(
            panel(page).getByRole('button', { name: 'Crear enlace de suscripción', exact: true }),
        ).toBeVisible();
        expect((await page.request.get(feedPath(fresh))).status()).toBe(404);

        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Calendario con fechas señaladas');
        expect(w.errors).toEqual([]);
    });
});
