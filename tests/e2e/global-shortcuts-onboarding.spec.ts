import { expect, test } from '@playwright/test';
import { artisan, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

test.describe('Atajos y primeros pasos', { tag: '@responsive' }, () => {
    test('E2E-34 · los atajos respetan la edición y las preferencias y la guía sobreviven a la recarga', async ({
        page,
    }, info) => {
        const id = `primeros-pasos-${stamp()}-${info.project.name}`;
        const user = { name: 'Primeros pasos', email: `e2e-${id}@example.test`, password: 'e2e-Guia-9-comprobar!' };
        const email = JSON.stringify(user.email);
        artisan(
            String.raw`App\Models\User::factory()->create(['email' => ${email}, 'name' => 'Primeros pasos', 'password' => ${JSON.stringify(user.password)}]);`,
        );

        try {
            await loginAs(page, user);
            const help = page.getByRole('alertdialog', { name: 'Ayuda y atajos' });
            const helpButton = page.getByRole('button', { name: 'Ayuda y atajos', exact: true });
            const welcome = page.getByRole('button', { name: 'Empezar guía de primeros pasos' });
            const brandStep = page.getByRole('alertdialog', { name: 'Crea o elige una marca' });
            const connectionStep = page.getByRole('alertdialog', { name: 'Conecta una cuenta' });
            const postStep = page.getByRole('alertdialog', { name: 'Crea tu primera publicación' });
            const repeat = help.getByRole('button', { name: 'Repetir guía de primeros pasos' });
            await expect(welcome).toBeVisible();
            await expect(brandStep).toHaveCount(0);
            await page.keyboard.press('?');
            await expect(help.getByRole('heading', { name: 'Ayuda y atajos' })).toBeFocused();
            await expect(help.getByText('Planificación', { exact: true })).toBeVisible();
            await expectNoOverflow(page);
            await page.screenshot({ path: info.outputPath('atajos-inicial.png') });
            await page.keyboard.press('Escape');
            await page.keyboard.press('p');
            await expect(page).toHaveURL(/\/calendar/);

            await welcome.click();
            await brandStep.getByRole('button', { name: 'Saltar guía' }).click();
            await expect(brandStep).toHaveCount(0);
            await page.reload();
            await expect(welcome).toHaveCount(0);
            await helpButton.click();
            await repeat.click();
            await expect(brandStep.getByRole('heading')).toBeFocused();
            await brandStep.getByRole('button', { name: 'Siguiente' }).click();
            await expect(connectionStep.getByRole('heading')).toBeFocused();
            await page.keyboard.press('Escape');
            seedComposerBrand(user.email, id);
            await page.reload();
            await helpButton.click();
            await help.getByRole('button', { name: 'Continuar guía de primeros pasos' }).click();
            await expect(connectionStep.getByRole('button', { name: 'Ir a Conexiones' })).toBeVisible();
            await connectionStep.getByRole('button', { name: 'Siguiente' }).click();
            await expect(postStep.getByRole('button', { name: 'Abrir el compositor' })).toBeVisible();
            await postStep.getByRole('button', { name: 'Terminar guía' }).click();
            await expect(postStep).toHaveCount(0);
            await page.reload();
            await helpButton.click();
            await repeat.click();
            await expect(brandStep).toBeVisible();
            await brandStep.getByRole('button', { name: 'Saltar guía' }).click();
            await expect(brandStep).toHaveCount(0);

            await page.goto('/profile/notifications');
            const device = page.getByLabel('Nombre de este dispositivo');
            await device.fill('');
            await device.press('n');
            await device.press('?');
            await expect(device).toHaveValue('n?');
            await expect(help).toHaveCount(0);
            await expect(page).toHaveURL(/\/profile\/notifications$/);
            const failurePush = page
                .getByRole('group', { name: 'Fallos de publicación', exact: true })
                .getByRole('checkbox', { name: 'Push', exact: true });
            await failurePush.uncheck();
            await page.getByRole('button', { name: 'Guardar preferencias' }).click();
            await expect(page.getByText('Guardado.', { exact: true })).toBeVisible();
            await page.reload();
            await expect(failurePush).not.toBeChecked();
            await helpButton.click();
            await help.getByRole('checkbox', { name: 'Activar los atajos globales de una sola tecla' }).uncheck();
            await help.getByRole('button', { name: 'Guardar atajos' }).click();
            await expect(help.getByRole('status')).toHaveText('Atajos guardados.');
            await page.reload();
            await page.keyboard.press('?');
            await expect(help).toHaveCount(0);
            await page.keyboard.press('ControlOrMeta+K');
            await expect(page.getByRole('alertdialog', { name: 'Buscar', exact: true })).toBeVisible();
            await page.keyboard.press('Escape');
            await expectNoOverflow(page);
            await page.screenshot({ path: info.outputPath('preferencias-guardadas.png') });
        } finally {
            artisan(String.raw`App\Models\User::where('email', ${email})->get()->each->delete();`);
        }
    });
});
