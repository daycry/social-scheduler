import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import {
    artisan,
    axeSeriousCritical,
    cleanComposerBrands,
    e2eUser,
    expectNoOverflow,
    loginAs,
    seedComposerBrand,
    seedExtraAccount,
    settle,
    stamp,
    watch,
    type ComposerBrand,
} from './helpers';
import { runRoot } from './isolated/runtime';

/**
 * T-93 · Plan de contenido en lenguaje natural (E2E-32). La IA es FALSA dentro del servidor desechable (`tests/e2e/isolated/fakes.php`):
 * con la bandera `studio-ai-on` propone tres publicaciones de solo texto; con `studio-ai-text-off` el asistente no está configurado. Antes de
 * confirmar no existe ningún borrador; al confirmar (con doble clic) solo se crean las elegidas, una sola vez. Marcas `e2e-composer-*`.
 */
test.describe.configure({ mode: 'serial' });

const flagOn = path.join(runRoot, 'studio-ai-on');
const flagTextOff = path.join(runRoot, 'studio-ai-text-off');

test.describe('Plan de contenido', { tag: '@responsive' }, () => {
    let brand: ComposerBrand;

    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        brand = seedComposerBrand(user.email, `plan-${stamp()}-${info.project.name}`);
        seedExtraAccount(brand.id, 'bluesky', 'e2e Bluesky');
        await loginAs(page, user);
    });

    test.afterEach(async ({}, info) => {
        fs.rmSync(flagOn, { force: true });
        fs.rmSync(flagTextOff, { force: true });
        cleanComposerBrands(e2eUser(info.project.name).email);
    });

    const open = async (page: Page) => {
        await page.goto(`/content-plan?brand_id=${brand.id}`);
        await settle(page);
    };

    /** Textos y estados de las publicaciones de la marca, en orden de creación. */
    const posts = (): { text: string; status: string; targets: number }[] => {
        const out = artisan(
            String.raw`echo 'POSTS:' . json_encode(App\Models\Post::withoutUserScope()->where('brand_id', ${brand.id})->orderBy('id')->get()->map(fn ($p) => ['text' => $p->text, 'status' => $p->status->value, 'targets' => $p->targets()->count()])->all());`,
        );
        return JSON.parse(out.match(/POSTS:(\[.*\])/)![1]);
    };

    test('E2E-32 · sin clave el botón está inactivo y explica por qué', async ({ page }, info) => {
        fs.writeFileSync(flagTextOff, 'asistente de texto apagado');
        const w = watch(page);
        await open(page);

        await expect(page.getByTestId('plan-unconfigured')).toContainText('no configurado');
        await expect(page.getByRole('button', { name: 'Proponer plan' })).toBeDisabled();
        await expect(page.getByTestId('plan-excluded')).toContainText('e2e Instagram');
        await page.screenshot({ path: info.outputPath('content-plan-sin-clave.png') });
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'plan de contenido sin clave');
        expect(w.errors, w.errors.join('\n')).toEqual([]);
        expect(posts()).toEqual([]);
    });

    test('E2E-32 · propone un lote, no crea nada hasta confirmar y solo crea lo elegido (una vez)', async ({
        page,
    }, info) => {
        fs.writeFileSync(flagOn, 'proveedores de IA falsos');
        const w = watch(page);
        await open(page);

        await page.getByLabel('Briefing').fill('La semana que viene, 3 posts sobre el lanzamiento de la nueva hogaza');
        await page.getByRole('button', { name: 'Proponer plan' }).click();

        // Tres publicaciones propuestas, con fecha, cuentas y texto, y NADA creado todavía.
        const review = page.getByTestId('plan-review');
        await expect(review).toBeVisible();
        const items = review.getByTestId('plan-item');
        await expect(items).toHaveCount(3);
        await expect(items.nth(0).getByTestId('plan-item-text')).toHaveValue(/^Plan e2e 1: La semana que viene/);
        await expect(items.nth(0).getByTestId('plan-item-date')).toHaveValue(/^\d{4}-\d{2}-\d{2}T09:00$/);
        expect(posts()).toEqual([]);
        await page.screenshot({ path: info.outputPath('content-plan-propuesta.png') });
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'plan de contenido propuesto');

        // Revisar: editar el texto de la primera y desmarcar la segunda.
        await items.nth(0).getByTestId('plan-item-text').fill('Primera, editada a mano');
        await page.getByLabel('Incluir la publicación 2').uncheck();
        const confirm = page.getByRole('button', { name: 'Crear borradores de 2 publicaciones' });
        await expect(confirm).toBeEnabled();
        expect(posts()).toEqual([]);

        // Un doble clic en «Confirmar» no duplica el lote.
        await confirm.dblclick();
        const done = page.getByTestId('plan-created');
        await expect(done).toContainText('Se han creado 2 borradores');
        await expect(review).toHaveCount(0);

        const created = posts();
        expect(created).toHaveLength(2);
        expect(created[0]).toEqual({ text: 'Primera, editada a mano', status: 'borrador', targets: 2 });
        expect(created[1].text).toMatch(/^Plan e2e 3: /);
        expect(created.every((p) => p.status === 'borrador')).toBe(true);
        await page.screenshot({ path: info.outputPath('content-plan-creado.png') });
        await expectNoOverflow(page);
        expect(w.errors, w.errors.join('\n')).toEqual([]);
    });
});
