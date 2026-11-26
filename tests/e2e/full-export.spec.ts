import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import zlib from 'node:zlib';
import {
    artisan,
    axeSeriousCritical,
    cleanComposerBrands,
    createExtraE2eUser,
    e2eUser,
    expectNoOverflow,
    loginAs,
    seedComposerBrand,
    seedComposerPosts,
    settle,
    stamp,
    watch,
    type ComposerBrand,
} from './helpers';

/**
 * Exportación completa de datos en ZIP (T-84, E2E-23): se pide, el job de la cola se procesa desde el spec, llega el aviso, se descarga el
 * ZIP y solo contiene datos del usuario y ningún secreto. Usuarios `e2e-*` y marcas `e2e-composer-*`: no toca datos reales.
 */
test.describe.configure({ mode: 'serial' });

const label = `full-export-${stamp()}`;

/** Lee un ZIP con el directorio central (sin dependencias): nombre de entrada a contenido ya inflado. */
function readZip(buffer: Buffer): Map<string, Buffer> {
    const end = buffer.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
    expect(end, 'directorio central del ZIP').toBeGreaterThan(-1);
    const total = buffer.readUInt16LE(end + 10);
    let position = buffer.readUInt32LE(end + 16);
    const entries = new Map<string, Buffer>();
    for (let i = 0; i < total; i++) {
        expect(buffer.readUInt32LE(position)).toBe(0x02014b50);
        const method = buffer.readUInt16LE(position + 10);
        const compressed = buffer.readUInt32LE(position + 20);
        const nameLength = buffer.readUInt16LE(position + 28);
        const extraLength = buffer.readUInt16LE(position + 30);
        const commentLength = buffer.readUInt16LE(position + 32);
        const offset = buffer.readUInt32LE(position + 42);
        const name = buffer.toString('utf8', position + 46, position + 46 + nameLength);
        const start = offset + 30 + buffer.readUInt16LE(offset + 26) + buffer.readUInt16LE(offset + 28);
        const data = buffer.subarray(start, start + compressed);
        entries.set(name, method === 8 ? zlib.inflateRawSync(data) : data);
        position += 46 + nameLength + extraLength + commentLength;
    }
    return entries;
}

/** Procesa de forma síncrona la cola del job (conexión y cola exports), como haría un worker. El aviso de éxito no usa cola. */
const runQueue = () =>
    artisan(
        "Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'exports','--queue'=>'exports','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>100,'--sleep'=>0]);",
    );

/** Deja al usuario sin exportaciones ni avisos de pruebas anteriores. */
const cleanExports = (email: string) =>
    artisan(
        String.raw`$u = App\Models\User::where('email', '${email}')->first(); if ($u) { App\Models\DataExport::withoutUserScope()->where('user_id', $u->id)->get()->each(function ($e) { $e->deleteFile(); $e->delete(); }); $u->notifications()->delete(); } echo 'CLEAN';`,
    );

/** Valor que NO debe aparecer en el ZIP: lo lee la base de pruebas (secretos descifrados y hash de la contraseña). */
const secretsOf = (email: string): string[] =>
    JSON.parse(
        artisan(
            String.raw`$u = App\Models\User::where('email', '${email}')->firstOrFail(); echo 'SECRETS:' . json_encode(array_values(array_filter([$u->password, ...App\Models\SocialAccount::withoutUserScope()->where('user_id', $u->id)->get()->flatMap(fn ($a) => [$a->access_token, $a->refresh_token])->all()])));`,
        ).match(/SECRETS:(\[.*\])/)![1],
    );

let brand: ComposerBrand;
let other: ComposerBrand;
let otherEmail: string;

test.describe('Exportar mis datos', { tag: '@responsive' }, () => {
    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        cleanExports(user.email);
        brand = seedComposerBrand(user.email, `${label}-${info.project.name}`);
        seedComposerPosts(user.email, brand.id);
        otherEmail = createExtraE2eUser(`export-${info.project.name}`);
        cleanComposerBrands(otherEmail);
        other = seedComposerBrand(otherEmail, `${label}-ajena-${info.project.name}`);
        seedComposerPosts(otherEmail, other.id);
        await loginAs(page, user);
    });

    test.afterEach(async ({}, info) => {
        const user = e2eUser(info.project.name);
        cleanExports(user.email);
        cleanComposerBrands(user.email);
        cleanComposerBrands(otherEmail);
    });

    test('E2E-23 · exportar mis datos: se pide, llega el aviso y el ZIP descargado solo trae datos propios y ningún secreto', async ({
        page,
    }, info) => {
        const w = watch(page);
        const user = e2eUser(info.project.name);
        await page.goto('/profile/export');
        await settle(page);
        await expect(page.getByRole('heading', { level: 1, name: 'Exportar mis datos', exact: true })).toBeVisible();
        await expect(page.getByText('Aún no has pedido ninguna exportación.', { exact: true })).toBeVisible();
        await expect(page.getByTestId('info-note')).toContainText('Credenciales y secretos');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Exportar mis datos: estado inicial');
        await page.screenshot({ path: info.outputPath('full-export-initial.png'), fullPage: true });

        await page.getByRole('button', { name: 'Pedir exportación', exact: true }).click();
        await expect(page.getByTestId('export-status')).toHaveText('En cola');
        await expect(page.getByRole('button', { name: 'Exportación en curso…', exact: true })).toBeDisabled();

        runQueue();

        await page.goto('/notifications');
        await settle(page);
        const notice = page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'Tu exportación de datos está lista', exact: true }) });
        await expect(notice).toContainText('Ya puedes descargar el ZIP con tus datos.');
        await notice.getByRole('link', { name: 'Ver exportación', exact: true }).click();
        await page.waitForURL(/\/profile\/export$/);
        await expect(page.getByTestId('export-status')).toHaveText('Lista para descargar');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Exportar mis datos: lista');

        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Descargar ZIP', exact: true }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/^exportacion-\d{4}-\d{2}-\d{2}\.zip$/);
        const entries = readZip(fs.readFileSync((await download.path())!));
        const text = [...entries.values()].map((content) => content.toString('utf8')).join('\n');

        expect(entries.has('LEEME.txt') && entries.has('manifest.json')).toBe(true);
        expect(entries.get('datos/marcas.json')!.toString('utf8')).toContain(brand.name);
        expect(entries.get('datos/publicaciones.json')!.toString('utf8')).toContain('e2e borrador de calendario');
        expect(text, 'datos de otro usuario').not.toContain(other.name);
        expect(text, 'datos de otro usuario').not.toContain(otherEmail);
        for (const secret of secretsOf(user.email)) expect(text, 'secreto en el ZIP').not.toContain(secret);
        const manifest = JSON.parse(entries.get('manifest.json')!.toString('utf8'));
        expect(manifest.format_version).toBe(1);

        await expect(page.getByTestId('export-status')).toHaveText('Lista para descargar');
        await page.screenshot({ path: info.outputPath('full-export-final.png'), fullPage: true });
        expect(w.errors, 'errores de consola').toEqual([]);
    });
});
