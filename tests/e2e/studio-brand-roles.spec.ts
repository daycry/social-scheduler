import { expect, test } from '@playwright/test';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

test('E2E-47 · un aprobador revisa solo los diseños de su marca y decide en Aprobaciones', { tag: '@responsive' }, async ({ browser, page }, info) => {
    test.setTimeout(120_000);
    const owner = e2eUser(info.project.name);
    const run = stamp();
    const brand = seedComposerBrand(owner.email, 'studio-approval-' + run);
    const other = seedComposerBrand(owner.email, 'studio-approval-private-' + run);
    const guest = { email: 'e2e-studio-approval-' + run + '@example.test', name: 'Aprobadora E2E', password: 'E2e-Studio-Approval-84!' };
    const result = artisan(String.raw`$owner = App\Models\User::where('email', '${owner.email}')->firstOrFail();
        $guest = App\Models\User::factory()->create(['email' => '${guest.email}', 'name' => '${guest.name}', 'password' => Illuminate\Support\Facades\Hash::make('${guest.password}')]);
        $studio = app(App\Services\Studio\StudioService::class);
        $document = App\Services\Studio\StudioDocument::blank(1080, 1080);
        foreach ([${brand.id} => 'Diseño para revisión', ${other.id} => 'Diseño privado E2E'] as $id => $name) {
            $brand = App\Models\Brand::ownedBy($owner)->findOrFail($id);
            echo 'DESIGN:' . $studio->create($owner, $brand, ['name' => $name, 'document' => $document])->id . ' ';
        }`);
    const [visible, hidden] = Array.from(result.matchAll(/DESIGN:(\d+)/g), match => Number(match[1]));
    const { baseURL, viewport, isMobile, hasTouch } = info.project.use;
    const context = await browser.newContext({ baseURL, viewport, isMobile, hasTouch });
    try {
        await loginAs(page, owner);
        await page.goto('/approvals');
        const team = page.getByTestId('brand-' + brand.id);
        await team.getByTestId('invite-email').fill(guest.email);
        await team.getByTestId('invite-send').click();
        await expect(team.getByText(guest.email)).toBeVisible();

        await page.goto('/studio?brand_id=' + brand.id);
        await page.getByLabel('Opciones de Diseño para revisión').click();
        await page.getByRole('button', { name: 'Solicitar aprobación' }).click();
        await expect(page.getByTestId('pending-design-' + visible)).toBeVisible();

        const reviewer = await context.newPage();
        await loginAs(reviewer, guest);
        await reviewer.goto('/studio');
        const shared = reviewer.getByTestId('studio-shared');
        await expect(shared).toContainText('Diseño para revisión');
        await expect(shared).toContainText('Aprobador');
        await expect(shared).not.toContainText('Diseño privado E2E');
        await reviewer.screenshot({ path: info.outputPath('shared-studio.png'), fullPage: true });
        expect((await reviewer.request.get('/studio/designs/' + hidden + '/edit')).status()).toBe(404);

        await reviewer.goto('/approvals');
        const pending = reviewer.getByTestId('pending-design-' + visible);
        await pending.getByRole('link', { name: 'Ver detalle' }).click();
        await expect(reviewer).toHaveURL(new RegExp('/studio/designs/' + visible + '/edit$'));
        await expect(reviewer.getByRole('button', { name: 'Guardar ahora' })).toHaveCount(0);
        await reviewer.goto('/approvals');
        await pending.getByTestId('approve').click();
        await expect(reviewer.getByTestId('approvals-message')).toHaveText('Diseño aprobado.');
        await expect(pending).toHaveCount(0);
        await reviewer.screenshot({ path: info.outputPath('design-approved.png'), fullPage: true });
        await expectNoOverflow(reviewer);

        await page.goto('/approvals');
        const recent = page.getByRole('region', { name: 'Tus decisiones recientes' });
        await expect(recent).toContainText('Diseño para revisión');
        await expect(recent).toContainText('Aprobado');
    } finally {
        await context.close();
        try {
            cleanComposerBrands(owner.email);
        } finally {
                artisan(String.raw`App\Models\User::where('email', '${guest.email}')->delete();`);
        }
    }
});
