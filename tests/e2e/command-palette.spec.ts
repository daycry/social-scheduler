import { expect, test, type Page } from '@playwright/test';
import {
    artisan,
    axeSeriousCritical,
    cleanComposerBrands,
    clearUserThrottle,
    createExtraE2eUser,
    e2eUser,
    expectNoOverflow,
    loginAs,
    seedComposerBrand,
    seedInboxComment,
    settle,
    stamp,
    watch,
    type ComposerBrand,
} from './helpers';

/**
 * Búsqueda global y paleta de comandos (T-85, E2E-24): Ctrl+K o el botón de la cabecera, resultados solo del usuario, navegación con el
 * teclado, Esc y devolución del foco. Usuarios `e2e-*`; las marcas `e2e-composer-*` (propias y de un segundo usuario) se limpian al terminar.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;
let otherEmail: string;
let token: string;

/** Una publicación propia y otra de OTRO usuario con el mismo texto buscable: la ajena nunca puede aparecer. */
function seedPalette(brandId: number, other: string, word: string) {
    artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`App\Models\Post::factory()->create(['brand_id' => $b->id, 'text' => 'e2e paleta propia ${word}']); ` +
            String.raw`$o = App\Models\User::where('email', '${other}')->firstOrFail(); ` +
            String.raw`$ob = App\Models\Brand::factory()->for($o)->create(['name' => 'e2e-composer-ajena-${word}']); ` +
            String.raw`App\Models\Post::factory()->create(['brand_id' => $ob->id, 'text' => 'e2e paleta ajena ${word}']);`,
    );
}

const openWithShortcut = (page: Page) => page.keyboard.press('ControlOrMeta+K');
const paletteDialog = (page: Page) => page.getByRole('dialog', { name: 'Buscar', exact: true });

test.describe('Paleta de comandos', { tag: '@responsive' }, () => {
    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        token = `pal${stamp()}`;
        cleanComposerBrands(user.email);
        brand = seedComposerBrand(user.email, `${token}-${info.project.name}`);
        otherEmail = createExtraE2eUser(`paleta-${token}`);
        cleanComposerBrands(otherEmail);
        seedPalette(brand.id, otherEmail, token);
        clearUserThrottle(user.email);
        await loginAs(page, user);
    });

    test.afterEach(async ({}, info) => {
        cleanComposerBrands(e2eUser(info.project.name).email);
        cleanComposerBrands(otherEmail);
    });

    test('E2E-24 · paleta de comandos: Ctrl+K busca solo contenido propio, se navega con el teclado, Esc devuelve el foco y el botón también la abre', async ({
        page,
    }, info) => {
        const w = watch(page);
        await page.goto(`/calendar?brand_id=${brand.id}`);
        await settle(page);
        const trigger = page.getByRole('button', { name: 'Buscar en la aplicación', exact: true });
        await expect(trigger).toBeVisible();
        await expectNoOverflow(page);

        // 1. Ctrl+K abre la paleta con el foco en el campo y las páginas de la app al momento (sin pedir nada al servidor).
        await page.getByTestId('brand-switcher').focus();
        await openWithShortcut(page);
        const dialog = paletteDialog(page);
        await expect(dialog).toBeVisible();
        const input = dialog.getByRole('combobox');
        await expect(input).toBeFocused();
        await expect(input).toHaveAttribute('aria-expanded', 'true');
        await expect(dialog.getByRole('option', { name: /Calendario/ })).toBeVisible();
        const box = await dialog.boundingBox();
        expect(box!.width, 'la paleta cabe en la pantalla').toBeLessThanOrEqual(page.viewportSize()!.width);
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Paleta abierta, estado inicial');
        await page.screenshot({ path: info.outputPath('command-palette-initial.png'), fullPage: true });

        // 2. Una publicación y una marca propias aparecen; la publicación (y la marca) de otro usuario, nunca.
        await input.fill(token);
        const post = dialog.getByRole('option', { name: new RegExp(`e2e paleta propia ${token}`) });
        const brandOption = dialog.getByRole('option', { name: brand.name, exact: true });
        await expect(post).toBeVisible();
        await expect(brandOption).toBeVisible();
        await expect(dialog.getByRole('group', { name: 'Publicaciones', exact: true })).toBeVisible();
        await expect(dialog.getByRole('group', { name: 'Marcas', exact: true })).toBeVisible();
        await expect(dialog.getByRole('option', { name: /ajena/ })).toHaveCount(0);
        await expect(dialog.getByRole('option')).toHaveCount(2);
        await expect(dialog.getByTestId('command-palette-status')).toHaveText('2 resultados.');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Paleta con resultados');

        // Sin coincidencias: mensaje visible y anunciado.
        await input.fill(`${token}-no-existe`);
        await expect(dialog.getByTestId('command-palette-empty')).toBeVisible();
        await expect(dialog.getByTestId('command-palette-status')).toContainText('Sin resultados');
        await input.fill(token);
        await expect(post).toBeVisible();

        // 3. Flechas e Intro: la primera opción está activa; ↓ pasa a la marca, ↑ vuelve (y ↑ desde la primera da la vuelta); Intro abre la marca.
        await expect(post).toHaveAttribute('aria-selected', 'true');
        await page.keyboard.press('ArrowDown');
        await expect(brandOption).toHaveAttribute('aria-selected', 'true');
        await expect(input).toHaveAttribute('aria-activedescendant', (await brandOption.getAttribute('id'))!);
        await page.keyboard.press('ArrowUp');
        await expect(post).toHaveAttribute('aria-selected', 'true');
        await page.keyboard.press('ArrowUp');
        await expect(brandOption).toHaveAttribute('aria-selected', 'true');
        await page.screenshot({ path: info.outputPath('command-palette-results.png'), fullPage: true });
        await page.keyboard.press('Enter');
        await page.waitForURL(new RegExp(`/brands/${brand.id}$`));
        await expect(page.getByRole('heading', { name: brand.name, exact: true })).toBeVisible();
        await expect(paletteDialog(page)).toHaveCount(0);

        // Intro sobre la publicación abre su edición.
        await openWithShortcut(page);
        await paletteDialog(page).getByRole('combobox').fill(token);
        await expect(post).toBeVisible();
        await page.keyboard.press('Enter');
        await page.waitForURL(/\/posts\/\d+\/edit$/);
        await settle(page);
        await page.screenshot({ path: info.outputPath('command-palette-final.png'), fullPage: true });

        // 4. Esc cierra y el foco vuelve al elemento que tenía antes de abrir la paleta.
        await page.goto(`/calendar?brand_id=${brand.id}`);
        await settle(page);
        await page.getByTestId('brand-switcher').focus();
        await openWithShortcut(page);
        await expect(paletteDialog(page)).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(paletteDialog(page)).toHaveCount(0);
        await expect(page.getByTestId('brand-switcher')).toBeFocused();

        // 5. También se abre con el botón visible (sin atajos) y, al cerrarla, el foco vuelve al botón.
        await trigger.click();
        await expect(paletteDialog(page)).toBeVisible();
        await paletteDialog(page).getByRole('combobox').fill('calendario');
        await expect(paletteDialog(page).getByRole('option', { name: /Calendario/ })).toBeVisible();
        await paletteDialog(page).getByRole('button', { name: 'Cerrar la búsqueda', exact: true }).click();
        await expect(paletteDialog(page)).toHaveCount(0);
        await expect(trigger).toBeFocused();
        expect(w.errors, 'errores de consola').toEqual([]);
    });

    test('E2E-24 · la paleta también se abre con Ctrl+K en el compositor y respeta el aviso de cambios sin guardar', async ({
        page,
    }) => {
        const w = watch(page);
        await page.goto(`/brands/${brand.id}/posts/create`);
        await settle(page);
        await expectNoOverflow(page);
        await page.locator('#text').fill('e2e paleta borrador sin guardar');
        await openWithShortcut(page);
        const dialog = paletteDialog(page);
        await expect(dialog).toBeVisible();
        await expectNoOverflow(page);
        await dialog.getByRole('combobox').fill('calendario');
        await dialog.getByRole('option', { name: /Calendario/ }).click();

        // Navegar desde la paleta con cambios sin guardar pregunta antes; seguir editando se queda en el compositor.
        const guard = page.getByRole('alertdialog');
        await expect(guard).toBeVisible();
        await guard.getByRole('button', { name: 'Seguir editando', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`/brands/${brand.id}/posts/create$`));
        await expect(page.locator('#text')).toHaveValue('e2e paleta borrador sin guardar');

        await openWithShortcut(page);
        await paletteDialog(page).getByRole('combobox').fill('calendario');
        await paletteDialog(page)
            .getByRole('option', { name: /Calendario/ })
            .click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Salir sin guardar', exact: true }).click();
        await page.waitForURL(/\/calendar/);
        expect(w.errors, 'errores de consola').toEqual([]);
    });

    test('E2E-24 · la cabecera con el botón de búsqueda y una marca de nombre largo no se desborda a 1024, 1280 y 1440 px', async ({
        page,
    }, info) => {
        test.skip(info.project.name !== 'desktop', 'solo cambia el ancho del escritorio');
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        const longBrand = seedComposerBrand(user.email, `${token}-cabecera-con-un-nombre-muy-largo`);
        seedInboxComment(longBrand.id, 'e2e cabecera con insignia de inbox');
        expect(longBrand.name.length).toBeGreaterThanOrEqual(30);
        await page.goto(`/calendar?brand_id=${longBrand.id}`);
        await settle(page);
        const modules = [
            'Estudio',
            'Analítica',
            'Planificación',
            'SmartLinks',
            'Sitios',
            'Inbox',
            'Informes',
            'Conexiones',
        ];
        for (const width of [1024, 1280, 1440]) {
            await page.setViewportSize({ width, height: 800 });
            await expect(page.getByRole('button', { name: 'Buscar en la aplicación', exact: true })).toBeVisible();
            await expect(page.getByTestId('brand-switcher')).toHaveAttribute('title', longBrand.name);
            await expect(page.getByTestId('inbox-badge')).toBeVisible();
            for (const name of modules) {
                await expect(
                    page.getByRole('navigation', { name: 'Módulos' }).getByRole('link', { name }),
                ).toBeVisible();
            }
            await expectNoOverflow(page);
        }
    });
});
