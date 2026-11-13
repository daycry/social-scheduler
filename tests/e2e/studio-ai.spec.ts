import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';
import { runRoot } from './isolated/runtime';

// T-14. Proveedores de IA FALSOS dentro del servidor desechable: con la bandera `studio-ai-on` el bootstrap E2E configura
// claves ficticias y atiende OpenAI/Google/Claude con un PNG real de 96x96 (tests/e2e/isolated/fakes.php). Sin bandera no
// hay claves y la UI debe decir «no configurada». Los trabajos se ejecutan con artisan (sin worker persistente).
const flagOn = path.join(runRoot, 'studio-ai-on');
const flagTextOff = path.join(runRoot, 'studio-ai-text-off');
test.afterEach(({}, info) => {
    fs.rmSync(flagOn, { force: true });
    fs.rmSync(flagTextOff, { force: true });
    cleanComposerBrands(e2eUser(info.project.name).email);
});

const ready = (page: Page) => expect(page.getByRole('button', { name: 'Añadir tabla', exact: true })).toBeEnabled();
const status = (page: Page) => page.getByTestId('studio-save-status');
const panelOf = (page: Page) => page.getByRole('region', { name: 'IA creativa' });
const openAi = async (page: Page) => { await page.getByRole('button', { name: 'IA', exact: true }).click(); return panelOf(page); };
const watchErrors = (page: Page) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
    page.on('console', message => { if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) errors.push(`console: ${message.text()}`); });
    return errors;
};
async function seedDesign(email: string, brandId: number, name: string, text: string, template = false): Promise<number> {
    const out = artisan(String.raw`$u = App\Models\User::where('email', '${email}')->firstOrFail(); $b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); $doc = App\Services\Studio\StudioDocument::blank(1080, 1080); $doc['pages'][0]['elements'] = [['id' => 'title', 'type' => 'text', 'x' => 40, 'y' => 60, 'width' => 800, 'height' => 120, 'text' => '${text}', 'fill' => '#111827', 'fontFamily' => 'Arial', 'fontSize' => 72]]; $d = app(App\Services\Studio\StudioService::class)->create($u, $b, ['name' => '${name}', 'document' => $doc, 'is_template' => ${template ? 'true' : 'false'}]); echo 'DESIGN:' . $d->id;`);
    return Number(out.match(/DESIGN:(\d+)/)![1]);
}
const runLatestJob = (brandId: number) => artisan(String.raw`$j = App\Models\Studio\StudioAiJob::withoutUserScope()->where('brand_id', ${brandId})->latest('id')->firstOrFail(); app(App\Services\Studio\StudioAi::class)->process($j->id); echo 'JOB:' . $j->id . ':' . $j->fresh()->status;`);
const documentJson = (page: Page, id: number) => page.evaluate(async designId => JSON.stringify((await (await fetch(`/studio/designs/${designId}`, { headers: { Accept: 'application/json' } })).json()).design.document), id);
const axeClean = async (page: Page) => expect((await new AxeBuilder({ page }).include('[aria-label="IA creativa"]').analyze()).violations).toEqual([]);

test('Studio IA: sin clave la funcion aparece como no configurada y no hay botones de generacion', { tag: '@responsive' }, async ({ page }, info) => {
    fs.writeFileSync(flagTextOff, 'asistente de texto apagado');
    const errors = watchErrors(page);
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `studio-ai-off-${stamp()}-${info.project.name}`);
    const designId = await seedDesign(owner.email, brand.id, 'Diseño sin IA', 'Hola');
    await loginAs(page, owner);
    await page.goto(`/studio/designs/${designId}/edit`);
    await ready(page);
    const panel = await openAi(page);
    await expect(panel.getByTestId('ai-unconfigured')).toContainText('Imágenes con IA: no configurada');
    await expect(panel.getByTestId('ai-text-unconfigured')).toContainText('Textos con IA: no configurado');
    await expect(panel.getByRole('button', { name: /Generar imagen|Rellenar una zona|Ampliar el lienzo|Variaciones|Generar textos/ })).toHaveCount(0);
    // La API tampoco ofrece nada: el estado dice no configurado y crear un trabajo se rechaza.
    const state = await page.evaluate(async id => (await fetch(`/brands/${id}/studio/ai`, { headers: { Accept: 'application/json' } })).json(), brand.id);
    expect(state.configured).toBe(false);
    await axeClean(page);
    await expectNoOverflow(page);
    expect(errors).toEqual([]);
});

test('Studio IA: generar, ver estados, insertar en el diseño, guardar y reabrir; errores de cuota y alt text', { tag: '@responsive' }, async ({ page }, info) => {
    test.setTimeout(240_000);
    fs.writeFileSync(flagOn, 'proveedores de IA falsos');
    const errors = watchErrors(page);
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `studio-ai-${stamp()}-${info.project.name}`);
    const designId = await seedDesign(owner.email, brand.id, 'Diseño con IA', 'Rebajas de verano');
    await loginAs(page, owner);
    await page.goto(`/studio/designs/${designId}/edit`);
    await ready(page);
    const panel = await openAi(page);
    await expect(panel.getByRole('button', { name: 'Generar imagen', exact: true })).toBeDisabled();

    // 1. Generar: el trabajo queda EN COLA hasta que se ejecuta el job.
    await panel.getByLabel('Descripción').fill('Una taza de café sobre una mesa de madera');
    await panel.getByLabel('Proporción').selectOption('16:9');
    await panel.getByLabel('Estilo').selectOption('photo');
    await panel.getByLabel('Variantes').fill('2');
    await panel.getByRole('button', { name: 'Generar imagen', exact: true }).click();
    const job = panel.getByRole('listitem').filter({ hasText: 'Una taza de café' });
    await expect(job.getByRole('status')).toHaveText('En cola');
    await axeClean(page);
    await expectNoOverflow(page);

    // 2. Se ejecuta el job con artisan (proveedor falso): la interfaz pasa sola a «Listo» con dos variantes.
    expect(runLatestJob(brand.id)).toMatch(/JOB:\d+:ready/);
    await expect(job.getByRole('status')).toHaveText('Listo', { timeout: 15_000 });
    await expect(job.getByRole('img')).toHaveCount(2);

    // 3. Insertar en el diseño, guardar y reabrir.
    await job.getByRole('button', { name: 'Insertar resultado 1 en el diseño' }).click();
    await expect(page.getByRole('button', { name: 'Deshacer' })).toBeEnabled();
    await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
    await expect(status(page)).toHaveText('Guardado');
    const assetId = Number(artisan(String.raw`echo App\Models\MediaAsset::withoutUserScope()->where('source', 'ai')->where('user_id', App\Models\Brand::withoutUserScope()->findOrFail(${brand.id})->user_id)->orderBy('id')->value('id');`));
    expect(await documentJson(page, designId)).toContain(`"asset_id":${assetId}`);
    await page.reload();
    await ready(page);
    expect(await documentJson(page, designId)).toContain(`"asset_id":${assetId}`);

    // 4. El recurso esta en la biblioteca de la marca con proveedor, modelo y prompt, y es un PNG real.
    const meta = JSON.parse(artisan(String.raw`$m = App\Models\MediaAsset::withoutUserScope()->findOrFail(${assetId}); echo json_encode(['source' => $m->source, 'ref' => json_decode($m->source_ref, true), 'mime' => $m->mime, 'png' => str_starts_with(Illuminate\Support\Facades\Storage::disk($m->disk)->get($m->path), "\x89PNG"), 'linked' => App\Models\Studio\StudioAsset::withoutUserScope()->where('media_asset_id', $m->id)->exists()]);`));
    expect(meta).toMatchObject({ source: 'ai', mime: 'image/png', png: true, linked: true, ref: { provider: 'openai', model: 'gpt-image-2.5-sunburst' } });
    expect(meta.ref.prompt).toContain('taza de café');
    await openAi(page);

    // 5. Error visible: cuota agotada del proveedor, con el mensaje en español y sin consumir cuota.
    const quotaPanel = panelOf(page);
    await quotaPanel.getByLabel('Descripción').fill('e2e-quota una imagen cualquiera');
    await quotaPanel.getByLabel('Proporción').selectOption('1:1');
    await quotaPanel.getByLabel('Variantes').fill('1');
    await quotaPanel.getByRole('button', { name: 'Generar imagen', exact: true }).click();
    const failing = quotaPanel.getByRole('listitem').filter({ hasText: 'e2e-quota' });
    await expect(failing.getByRole('status')).toHaveText('En cola');
    expect(runLatestJob(brand.id)).toMatch(/JOB:\d+:failed/);
    await expect(failing.getByRole('status')).toHaveText('Error', { timeout: 15_000 });
    await expect(failing.getByRole('alert')).toContainText('Se agotó la cuota o el saldo de la cuenta del proveedor de IA');
    await expect(quotaPanel.getByText('quedan 18 de 20')).toBeVisible();

    // 6. Editar con IA: variacion de una imagen de la biblioteca (desde el boton del recurso) y ampliar el lienzo.
    await page.getByRole('button', { name: 'Recursos', exact: true }).click();
    await page.getByRole('button', { name: /^Editar con IA ia-/ }).first().click();
    const edit = panelOf(page);
    await expect(edit.getByLabel('Qué quieres hacer')).toHaveValue('edit');
    await expect(edit.getByTestId('ai-mask-preview')).toBeVisible();
    await edit.getByLabel('Descripción').fill('Añade un vapor sobre la taza');
    await edit.getByLabel('Zona: ancho en porcentaje').fill('40');
    await edit.getByRole('button', { name: 'Rellenar una zona', exact: true }).click();
    const editJob = edit.getByRole('listitem').filter({ hasText: 'Añade un vapor' });
    await expect(editJob.getByRole('status')).toHaveText('En cola');
    expect(runLatestJob(brand.id)).toMatch(/JOB:\d+:ready/);
    await expect(editJob.getByRole('status')).toHaveText('Listo', { timeout: 15_000 });

    // 7. Alt text con Claude (falso) para una imagen de la biblioteca.
    await edit.getByLabel('Texto alternativo de una imagen').selectOption({ index: 1 });
    await edit.getByRole('button', { name: 'Sugerir texto alternativo' }).click();
    await expect(edit.getByTestId('ai-alt-result')).toContainText('círculo amarillo');
    await axeClean(page);
    expect(errors).toEqual([]);
});

test('Studio IA: titulares y copies, y relleno de plantilla desde una lista con Claude', { tag: '@responsive' }, async ({ page }, info) => {
    test.setTimeout(180_000);
    fs.writeFileSync(flagOn, 'proveedores de IA falsos');
    const errors = watchErrors(page);
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `studio-ai-text-${stamp()}-${info.project.name}`);
    const templateId = await seedDesign(owner.email, brand.id, 'Plantilla de oferta', 'Oferta {{producto}}', true);
    await loginAs(page, owner);
    await page.goto(`/studio/designs/${templateId}/edit`);
    await ready(page);
    const panel = await openAi(page);

    await panel.getByLabel('Brief para titulares y copies').fill('Rebajas de verano en cafeterías de especialidad');
    await panel.getByRole('button', { name: 'Generar textos' }).click();
    await expect(panel.getByRole('list', { name: 'Textos propuestos' }).getByRole('listitem')).toHaveCount(6);
    await panel.getByRole('button', { name: 'Insertar texto: Verano sin límites' }).click();
    await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
    await expect(status(page)).toHaveText('Guardado');
    expect(await documentJson(page, templateId)).toContain('Verano sin límites');

    await panel.getByLabel('Lista para rellenar la plantilla (uno por línea)').fill('Café\nTé');
    await panel.getByRole('button', { name: 'Rellenar plantilla' }).click();
    const rows = panel.getByRole('list', { name: 'Filas propuestas' }).getByRole('listitem');
    await expect(rows).toHaveCount(2);
    await expect(rows.first()).toContainText('producto: producto de Café');
    await rows.first().getByRole('button', { name: 'Crear diseño' }).click();
    await expect(rows.first().getByRole('link', { name: 'Abrir diseño creado' })).toBeVisible();
    const created = artisan(String.raw`$d = App\Models\Studio\Design::withoutUserScope()->where('brand_id', ${brand.id})->where('is_template', false)->where('name', 'Café')->firstOrFail(); echo json_encode($d->document['pages'][0]['elements']);`);
    expect(JSON.parse(created)[0].text).toBe('Oferta producto de Café');
    await axeClean(page);
    await expectNoOverflow(page);
    expect(errors).toEqual([]);
});
