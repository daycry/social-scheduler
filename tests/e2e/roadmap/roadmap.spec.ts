import { expect, test, type Page, type TestInfo } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { fixture, login, noOverflow, shot, type Seed, type User } from './support';

let user: User;
let other: User;
test.beforeEach(async ({}, info) => {
    ({ user, other } = fixture('reset', info.project.name));
});

const dateAt = (minutes: number) => {
    const date = new Date(Date.now() + minutes * 60_000);
    date.setMinutes(Math.ceil(date.getMinutes() / 5) * 5, 0, 0);
    const parts = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Madrid', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(date);
    return parts.replace(' ', 'T');
};

async function finish(page: Page, info: TestInfo, name: string) {
    await noOverflow(page);
    await page.mouse.move(0, 0);
    await expect(page.locator('#nprogress')).toHaveCount(0);
    const audit = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(audit.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'), name).toEqual([]);
    await shot(page, info, name);
}

async function scheduledTime(page: Page, value: string) {
    const native = page.locator('input[type="datetime-local"]');
    if (await native.count()) {
        await native.fill(value);
        await native.press('Tab');
        return;
    }
    await page.locator('#scheduled_at').click();
    const dialog = page.getByRole('dialog', { name: 'Elegir fecha y hora' });
    const date = value.slice(0, 10);
    for (let i = 0; i < 3 && ! await dialog.locator(`[data-date="${date}"]`).count(); i++) {
        await dialog.getByRole('button', { name: 'Mes siguiente' }).click();
    }
    await dialog.locator(`[data-date="${date}"]`).click();
    await dialog.getByLabel('Hora').selectOption({ label: value.slice(11, 13) });
    await dialog.getByLabel('Minutos').selectOption({ label: value.slice(14, 16) });
    await page.keyboard.press('Escape');
}

async function createScheduled(page: Page, seed: Seed, text: string, minutes = 1, imageFile?: string) {
    await page.goto(`/brands/${seed.brandId}/posts/create`);
    await page.getByRole('button', { name: /^Facebook: T43 Facebook/ }).click();
    if (imageFile) {
        await page.getByRole('button', { name: /Tipo de contenido en Facebook/ }).click();
        await page.getByRole('option', { name: /^Foto/ }).click();
        const uploaded = page.waitForResponse((response) => /\/media\/\d+\/upload$/.test(new URL(response.url()).pathname) && response.request().method() === 'PUT');
        await page.locator('input[type="file"]').setInputFiles(imageFile);
        expect((await uploaded).status()).toBe(200);
    }
    await page.getByLabel('Texto', { exact: true }).fill(text);
    await scheduledTime(page, dateAt(minutes));
    await expect(page.getByRole('button', { name: 'Programar', exact: true })).toBeEnabled();
    await page.getByRole('button', { name: 'Programar', exact: true }).click();
    await expect(page).toHaveURL(/\/calendar/);
}

async function newKey(page: Page, name: string, readOnly = true): Promise<string> {
    await page.goto('/api-keys');
    await page.getByLabel(/Nombre \(p\. ej\./).fill(name);
    if (readOnly) await page.getByRole('button', { name: 'Solo lectura', exact: true }).click();
    await page.getByRole('button', { name: 'Crear API key', exact: true }).click();
    await expect(page.getByTestId('new-key')).toBeVisible();
    return (await page.getByTestId('new-key').innerText()).trim();
}

async function rpc(page: Page, token: string, method: string, params: object = {}) {
    const response = await page.request.post('/mcp', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json, text/event-stream' }, data: { jsonrpc: '2.0', id: 1, method, params } });
    const text = await response.text();
    const payload = text.startsWith('data:') || text.includes('\ndata:')
        ? JSON.parse(text.split('\n').filter((line) => line.startsWith('data:')).at(-1)!.slice(5).trim())
        : JSON.parse(text || '{}');
    return { response, payload, text };
}

async function tool(page: Page, token: string, name: string, args: object = {}) {
    const result = await rpc(page, token, 'tools/call', { name, arguments: args });
    expect(result.response.status(), name).toBe(200);
    expect(result.payload.result.isError, `${name}: ${result.text}`).not.toBe(true);
    return JSON.parse(result.payload.result.content.find((content: any) => content.type === 'text').text);
}

test('E2E-01 · registro, verificación, panel vacío, login y recuperación', async ({ page }, info) => {
    const email = `e2e-sec-roadmap-register-${info.project.name}-${Date.now()}@example.test`;
    await page.goto('/register');
    await page.getByLabel('Nombre', { exact: true }).fill('T43 Registro');
    await page.getByLabel('Correo electrónico').fill(email);
    await page.getByLabel('Contraseña', { exact: true }).fill(user.password);
    await page.getByLabel('Confirmar contraseña').fill(user.password);
    await shot(page, info, 'e2e-01-registro');
    await page.getByRole('button', { name: /Crear cuenta|Registrarme|Registrar/ }).click();
    await expect(page).toHaveURL(/verify-email/);
    fixture('verify', info.project.name, { email });
    await page.goto('/dashboard');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await page.goto('/brands');
    await expect(page.getByRole('heading', { name: 'Crea tu primera marca', exact: true })).toBeVisible();
    await finish(page, info, 'e2e-01-panel-vacio');
    // Logout through the product's own menu, then exercise a fresh password login.
    await page.getByRole('button', { name: 'Menú de usuario', exact: true }).click();
    await page.getByRole('button', { name: 'Salir', exact: true }).click();
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login/);
    await login(page, { ...user, email });
    await page.context().clearCookies();
    await page.goto('/forgot-password');
    await page.getByLabel('Correo electrónico').fill(email);
    await page.getByRole('button', { name: 'Enviar enlace de recuperación' }).click();
    await expect.poll(() => fixture('state', info.project.name).mail.some((mail: any) => mail.email === email && typeof mail.token === 'string' && mail.token.length > 20)).toBe(true);
    await shot(page, info, 'e2e-01-recuperacion');
});

test('E2E-02 · aislamiento de una marca entre dos sesiones reales', async ({ page, browser }, info) => {
    await login(page, user);
    const name = `e2e-t43-privada-${info.project.name}`;
    await page.goto('/brands/create');
    await page.getByLabel('Nombre', { exact: true }).fill(name);
    await page.getByLabel('Zona horaria').fill('Europe/Madrid');
    await page.getByRole('button', { name: 'Guardar', exact: true }).click();
    await page.goto('/brands');
    const href = await page.getByRole('main').getByRole('link', { name, exact: true }).getAttribute('href');
    expect(href).toBeTruthy();
    const context = await browser.newContext({ baseURL: 'http://localhost:8081', locale: 'es-ES', timezoneId: 'Europe/Madrid', viewport: info.project.use.viewport, isMobile: info.project.use.isMobile, hasTouch: info.project.use.hasTouch });
    const pageB = await context.newPage();
    try {
        await login(pageB, other);
        const response = await pageB.goto(href!);
        expect([403, 404]).toContain(response!.status());
        await expect(pageB.locator('body')).not.toContainText(name);
        await shot(pageB, info, 'e2e-02-marca-ajena');
        await pageB.goto('/brands');
        await expect(pageB.getByRole('main').getByRole('link', { name, exact: true })).toHaveCount(0);
    } finally { await context.close(); }
});

test('E2E-03 · OAuth local de Instagram, Facebook, YouTube y TikTok y desconexión', async ({ page }, info) => {
    const seed = fixture<Seed>('seed', info.project.name, { networks: [] });
    await login(page, user);
    await page.goto(`/brands/${seed.brandId}/connections`);
    await page.locator('[data-network="instagram"]').getByRole('button', { name: '+ Conectar', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Falta un paso: elige qué cuentas conectar' })).toBeVisible();
    for (const checkbox of await page.locator('#eleccion-cuentas').getByRole('checkbox').all()) await checkbox.check();
    await page.getByRole('button', { name: 'Conectar seleccionadas' }).click();
    for (const network of ['youtube', 'tiktok']) {
        await page.locator(`[data-network="${network}"]`).getByRole('button', { name: '+ Conectar', exact: true }).click();
        await expect(page.locator(`[data-network="${network}"]`)).toContainText('Conectada');
    }
    const accounts = fixture('state', info.project.name).accounts;
    expect(accounts.map((account: any) => account.network).sort()).toEqual(['facebook', 'instagram', 'tiktok', 'youtube']);
    expect(accounts.every((account: any) => account.status === 'activa' && account.hasToken)).toBe(true);
    await finish(page, info, 'e2e-03-cuentas-conectadas');
    page.on('dialog', (dialog) => dialog.accept());
    await page.locator('[data-network="instagram"]').getByRole('button', { name: 'Desconectar', exact: true }).click();
    await expect.poll(() => fixture('state', info.project.name).accounts.find((account: any) => account.network === 'instagram')).toMatchObject({ status: 'desconectada', hasToken: false });
    await expect(page.locator('[data-network="instagram"]').getByRole('button', { name: 'Desconectar', exact: true })).toHaveCount(0);
});

test('E2E-04 · job de renovación y reconexión conserva las publicaciones pendientes', async ({ page }, info) => {
    const seed = fixture<Seed>('seed', info.project.name);
    const pending = fixture('post', info.project.name, { brandId: seed.brandId, accountId: seed.accounts.facebook });
    expect(fixture('refresh', info.project.name, { accountId: seed.accounts.facebook })).toMatchObject({ status: 'activa', renewed: true });
    expect(fixture('refresh', info.project.name, { accountId: seed.accounts.facebook, fail: true }).status).toBe('requiere_reconexion');
    expect(fixture('state', info.project.name).posts.some((post: any) => post.id === pending.postId && post.status === 'programada')).toBe(true);
    await login(page, user);
    await page.goto(`/brands/${seed.brandId}/connections`);
    const account = page.locator(`[data-account="${seed.accounts.facebook}"]`);
    await expect(account.getByRole('button', { name: 'Reconectar', exact: true })).toBeVisible();
    await expect(account).toContainText('Las publicaciones programadas se conservan');
    await finish(page, info, 'e2e-04-requiere-reconexion');
});

test('E2E-05 · reel demasiado largo rechazado sin guardar publicación', async ({ page }, info) => {
    const seed = fixture<Seed>('seed', info.project.name);
    await login(page, user);
    await page.goto(`/brands/${seed.brandId}/posts/create`);
    await page.getByRole('button', { name: /^Instagram: T43 Instagram/ }).click();
    await page.getByRole('button', { name: /Tipo de contenido en Instagram/ }).click();
    await page.getByRole('option', { name: /^Reel/ }).click();
    const video = fixture<{ path: string }>('long-video', info.project.name);
    const uploaded = page.waitForResponse((response) => /\/media\/\d+\/upload$/.test(new URL(response.url()).pathname) && response.request().method() === 'PUT');
    await page.locator('input[type="file"]').setInputFiles(video.path);
    const uploadResponse = await uploaded;
    expect(uploadResponse.status()).toBe(200);
    const uploadedId = Number(uploadResponse.url().match(/\/media\/(\d+)\/upload$/)![1]);
    const asset = fixture('upload-meta', info.project.name, { assetId: uploadedId });
    expect(asset.duration).toBeGreaterThan(900);
    await page.getByLabel('Texto', { exact: true }).fill('e2e t43 reel rechazado');
    await scheduledTime(page, dateAt(5));
    await expect(page.getByRole('button', { name: 'Programar', exact: true })).toBeDisabled();
    await expect(page.getByRole('main')).toContainText(/El video dura 15 min 1 s.*maximo 15 min/i);
    await finish(page, info, 'e2e-05-duracion-rechazada');
    const csrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(csrf).toBeTruthy();
    const response = await page.request.post(`/brands/${seed.brandId}/posts`, { maxRedirects: 0, headers: { Accept: 'application/json', Referer: page.url(), 'X-XSRF-TOKEN': decodeURIComponent(csrf!.value) }, data: { text: 'e2e t43 reel rechazado', schedule: true, scheduled_at: dateAt(5), media: [asset.id], targets: [{ account_id: seed.accounts.instagram, content_type: 'instagram_reel' }] } });
    expect(response.status()).toBe(302);
    expect(response.headers().location).toContain(`/brands/${seed.brandId}/posts/create`);
    expect(fixture('state', info.project.name).posts).toEqual([]);
});

test('E2E-06 · compositor programa, dispatcher y worker publican con enlace', async ({ page }, info) => {
    const seed = fixture<Seed>('seed', info.project.name);
    await login(page, user);
    const text = 'e2e t43 publicacion puntual';
    const image = fixture<{ path: string }>('image-file', info.project.name, { assetId: seed.imageId });
    await createScheduled(page, seed, text, 1, image.path);
    const before = fixture('state', info.project.name);
    expect(before.targets).toHaveLength(1);
    expect(before.targets[0].status).toBe('programada');
    await shot(page, info, 'e2e-06-programada');
    fixture('due', info.project.name, { minutes: 10 });
    const target = fixture('state', info.project.name).targets[0];
    expect(target.status).toBe('publicada');
    expect(target.externalId).toMatch(/^t43-external-/);
    await page.goto(`/calendar?view=list&brand_id=${seed.brandId}`);
    const row = page.getByRole('row').filter({ hasText: text });
    await expect(row).toContainText('Publicada');
    await expect(row.locator(`a[href="${target.externalUrl}"]`)).toBeVisible();
    await finish(page, info, 'e2e-06-publicada');
});

test('E2E-07 · reintento transitorio y timeout tras enviar no duplican la publicación', async ({ page }, info) => {
    const seed = fixture<Seed>('seed', info.project.name);
    for (const text of ['e2e t43-transient', 'e2e t43-timeout']) {
        fixture('post', info.project.name, { brandId: seed.brandId, accountId: seed.accounts.facebook, text });
    }
    fixture('due', info.project.name, { minutes: 2 });
    fixture('due', info.project.name, { minutes: 30 });
    const state = fixture('state', info.project.name);
    await info.attach('reintentos-y-red-externa-simulada', { body: JSON.stringify({ targets: state.targets, externals: state.externals }, null, 2), contentType: 'application/json' });
    expect(state.targets).toHaveLength(2);
    expect(state.targets.every((target: any) => target.status === 'publicada')).toBe(true);
    for (const target of state.targets) expect(Object.keys(state.externals.records).filter((id) => id === String(target.id))).toHaveLength(1);
    expect(Object.keys(state.externals.records).filter((id) => state.targets.some((target: any) => String(target.id) === id))).toHaveLength(2);
    const timeoutPost = state.posts.find((post: any) => post.text.includes('t43-timeout'));
    const timeoutTarget = state.targets.find((target: any) => target.postId === timeoutPost.id);
    expect(state.externals.calls[String(timeoutTarget.id)]).toBe(1);
    await login(page, user);
    await page.goto(`/posts/${timeoutPost.id}/edit`);
    await expect(page.getByRole('main')).toContainText('Publicada');
    await finish(page, info, 'e2e-07-idempotencia');
});

test('E2E-08 · calendario filtra y reprograma pendientes, bloquea las publicadas', async ({ page }, info) => {
    const seed = fixture<Seed>('seed', info.project.name);
    const pending = fixture('post', info.project.name, { brandId: seed.brandId, accountId: seed.accounts.facebook, text: 'e2e t43 reprogramar' });
    const otherBrand = fixture<Seed>('seed', info.project.name, { brandName: `e2e-t43-otra-${info.project.name}` });
    fixture('post', info.project.name, { brandId: otherBrand.brandId, accountId: otherBrand.accounts.facebook, text: 'e2e t43 OTRA MARCA no visible' });
    fixture('post', info.project.name, { brandId: seed.brandId, accountId: seed.accounts.instagram, text: 'e2e t43 OTRA RED no visible', contentType: 'instagram_image', media: [seed.imageId] });
    await login(page, user);
    await page.goto(`/calendar?view=list&brand_id=${seed.brandId}&network=facebook`);
    await expect(page.getByRole('main')).not.toContainText('OTRA MARCA no visible');
    await expect(page.getByRole('main')).not.toContainText('OTRA RED no visible');
    const input = page.getByLabel(`Fecha de la publicación ${pending.postId}`, { exact: true });
    const changed = dateAt(60);
    await input.fill(changed);
    await input.press('Tab');
    await expect.poll(() => {
        const iso = fixture('state', info.project.name).posts.find((post: any) => post.id === pending.postId).scheduledAt;
        return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Madrid', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(iso)).replace(' ', 'T');
    }).toBe(changed);
    await expect(input).toHaveValue(changed);
    fixture('due', info.project.name, { minutes: 70 });
    await page.reload();
    await expect(page.getByRole('row').filter({ hasText: 'e2e t43 reprogramar' })).toContainText('Publicada');
    await expect(page.getByLabel(`Fecha de la publicación ${pending.postId}`, { exact: true })).toHaveCount(0);
    await finish(page, info, 'e2e-08-calendario');
});

test('E2E-09 · snapshots reales por job son idempotentes y conservan null', async ({ page }, info) => {
    const seed = fixture<Seed>('seed', info.project.name);
    fixture('post', info.project.name, { brandId: seed.brandId, accountId: seed.accounts.facebook });
    fixture('due', info.project.name, { minutes: 2 });
    fixture('snapshots', info.project.name);
    const first = fixture('state', info.project.name);
    expect(first.accountSnapshots.length).toBe(4);
    expect(first.postSnapshots.length).toBe(1);
    expect(first.accountSnapshots.every((snapshot: any) => snapshot.metrics.views === null)).toBe(true);
    expect(first.postSnapshots[0].metrics.views).toBeNull();
    fixture('snapshots', info.project.name);
    const second = fixture('state', info.project.name);
    await info.attach('snapshots-primera-y-segunda-ejecucion', { body: JSON.stringify({ first: { accounts: first.accountSnapshots, posts: first.postSnapshots }, second: { accounts: second.accountSnapshots, posts: second.postSnapshots } }, null, 2), contentType: 'application/json' });
    expect(second.accountSnapshots.map((snapshot: any) => snapshot.id)).toEqual(first.accountSnapshots.map((snapshot: any) => snapshot.id));
    expect(second.postSnapshots.map((snapshot: any) => snapshot.id)).toEqual(first.postSnapshots.map((snapshot: any) => snapshot.id));
    await login(page, user);
    await page.goto(`/dashboard/facebook?brand_id=${seed.brandId}`);
    await expect(page.getByRole('main')).toContainText(/No disponible/i);
    await shot(page, info, 'e2e-09-snapshot');
});

test('E2E-10 · dashboard de treinta días cambia red y periodo con gráficos y no disponible', async ({ page }, info) => {
    const seed = fixture<Seed>('seed', info.project.name);
    fixture('history', info.project.name);
    expect(fixture('state', info.project.name).accountSnapshots).toHaveLength(120);
    await login(page, user);
    for (const network of ['instagram', 'facebook', 'youtube', 'tiktok']) {
        await page.goto(`/dashboard/${network}?brand_id=${seed.brandId}`);
        await expect(page.getByRole('heading', { name: 'Crecimiento', exact: true })).toBeVisible();
        await expect(page.getByRole('main')).toContainText('Seguidores');
        if (network === 'instagram') await expect(page.getByText('Alcance', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Engagement (cuenta)', { exact: true })).toBeVisible();
        // The canonical views metric replaces the old impressions metric in Meta.
        if (network !== 'tiktok') await expect(page.getByText('Visualizaciones', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('main')).toContainText(/No disponible/i);
        await expect(page.getByRole('img', { name: 'Gráfico de evolución' }).first()).toBeVisible();
        await page.getByRole('button', { name: '7 días', exact: true }).click();
        await expect(page).toHaveURL(/from=/);
        await page.getByRole('button', { name: '30 días', exact: true }).click();
        await expect(page.getByRole('img', { name: 'Gráfico de evolución' }).first()).toBeVisible();
    }
    await finish(page, info, 'e2e-10-dashboard');
});

test('E2E-11 · informes PDF y CSV generados por la cola y descargados con datos propios', async ({ page }, info) => {
    const seed = fixture<Seed>('seed', info.project.name);
    const foreign = fixture<{ marker: string }>('foreign-report-data', info.project.name);
    fixture('history', info.project.name, { views: { facebook: 200 } });
    fixture('post', info.project.name, { brandId: seed.brandId, accountId: seed.accounts.facebook, text: 'e2e t43 PUBLICACION PROPIA INFORME' });
    fixture('due', info.project.name, { minutes: 2 });
    fixture('snapshots', info.project.name);
    await login(page, user);
    await page.goto('/reports');
    await page.getByRole('combobox', { name: /^Marca/ }).selectOption(String(seed.brandId));
    await page.getByLabel('Desde', { exact: true }).fill(new Date(Date.now() - 31 * 86400000).toISOString().slice(0, 10));
    await page.getByLabel('Hasta', { exact: true }).fill(new Date(Date.now() + 86400000).toISOString().slice(0, 10));
    await page.getByRole('button', { name: 'Generar informe' }).click();
    fixture('worker', info.project.name);
    await page.reload();
    const reports = fixture('state', info.project.name).reports;
    expect(reports).toHaveLength(2);
    expect(reports.every((report: any) => report.status === 'ready')).toBe(true);
    for (const report of reports) {
        const item = page.locator(`[data-report="${report.id}"]`);
        await expect(item).toContainText('Listo');
        const downloadEvent = page.waitForEvent('download');
        await item.getByRole('link', { name: 'Descargar', exact: true }).click();
        const download = await downloadEvent;
        const file = info.outputPath(`informe.${report.format}`);
        await download.saveAs(file);
        const bytes = fs.readFileSync(file);
        if (report.format === 'pdf') {
            expect(bytes.subarray(0, 5).toString()).toBe('%PDF-');
            expect(bytes.length).toBeGreaterThan(1000);
            const text = execFileSync('pdftotext', [file, '-'], { encoding: 'utf8' });
            expect(text).toContain(seed.brandName);
            expect(text).toContain('Publicaciones destacadas');
            expect(text).toContain('PUBLICACION PROPIA INFORME');
            expect(text).toContain('Seguidores');
            expect(text).not.toContain(foreign.marker);
        } else {
            const text = bytes.toString('utf8');
            expect(text).toContain(seed.brandName);
            expect(text).toContain('PUBLICACION PROPIA INFORME');
            expect(text).not.toContain(other.email);
            expect(text).not.toContain(foreign.marker);
        }
        await info.attach(`informe-${report.format}`, { path: file, contentType: report.format === 'pdf' ? 'application/pdf' : 'text/csv' });
    }
    await finish(page, info, 'e2e-11-informes-listos');
});

test('E2E-12 · API key visible una vez, revocada en UI y rechazada por MCP', async ({ page }, info) => {
    await login(page, user);
    const name = 'e2e t43 key revocable';
    const key = await newKey(page, name);
    expect((await rpc(page, key, 'tools/list')).response.status()).toBe(200);
    await page.reload();
    await expect(page.getByTestId('new-key')).toHaveCount(0);
    page.on('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: `Revocar ${name}`, exact: true }).click();
    await expect(page.getByText(name, { exact: true })).toHaveCount(0);
    const rejected = await rpc(page, key, 'tools/list');
    expect(rejected.response.status()).toBe(401);
    expect(rejected.text).not.toContain('list_brands');
    await finish(page, info, 'e2e-12-key-revocada');
});

test('API-01 · initialize, catálogo y flujo completo de herramientas con key válida', async ({ page }, info) => {
    const seed = fixture<Seed>('seed', info.project.name);
    fixture('history', info.project.name);
    await login(page, user);
    const key = await newKey(page, 'e2e t43 API initialize', false);
    const initialized = await rpc(page, key, 'initialize', { protocolVersion: '2025-03-26', capabilities: {}, clientInfo: { name: 't43', version: '1' } });
    expect(initialized.response.status()).toBe(200);
    expect(initialized.payload.result.protocolVersion).toBeTruthy();
    const list = await rpc(page, key, 'tools/list');
    expect(list.response.status()).toBe(200);
    const names = list.payload.result.tools.map((tool: any) => tool.name);
    for (const name of ['list_brands', 'create_post', 'get_calendar', 'get_stats', 'get_post_stats', 'generate_report', 'get_report']) expect(names).toContain(name);
    const brands = await tool(page, key, 'list_brands');
    expect(JSON.stringify(brands)).toContain(seed.brandName);
    const accounts = await tool(page, key, 'list_accounts', { brand_id: seed.brandId });
    expect(JSON.stringify(accounts)).toContain('T43 Facebook');
    const content = { text: 'e2e t43 MCP original', schedule: true, scheduled_at: dateAt(5), media_ids: [], targets: [{ account_id: seed.accounts.facebook, content_type: 'facebook_text' }] };
    const created = await tool(page, key, 'create_post', { brand_id: seed.brandId, ...content });
    expect(created.id).toBeGreaterThan(0);
    const updated = await tool(page, key, 'update_post', { post_id: created.id, ...content, text: 'e2e t43 MCP actualizado' });
    expect(updated.text).toBe('e2e t43 MCP actualizado');
    const calendar = await tool(page, key, 'get_calendar', { brand_id: seed.brandId });
    expect(JSON.stringify(calendar)).toContain('e2e t43 MCP actualizado');
    const stats = await tool(page, key, 'get_stats', { brand_id: seed.brandId });
    expect(JSON.stringify(stats)).toContain('followers');
    const postStats = await tool(page, key, 'get_post_stats', { post_id: created.id });
    expect(postStats.id).toBe(created.id);
    expect(postStats.targets[0].metrics.views).toBeNull();
    const queued = await tool(page, key, 'generate_report', { brand_id: seed.brandId, format: 'csv' });
    expect(queued.id).toBeGreaterThan(0);
    fixture('worker', info.project.name);
    const ready = await tool(page, key, 'get_report', { report_id: queued.id });
    expect(ready.status).toBe('ready');
    const signed = ready.download_url ?? ready.url;
    expect(signed).toContain('signature=');
    const downloaded = await page.request.get(signed);
    expect(downloaded.status()).toBe(200);
    expect(await downloaded.text()).toContain(seed.brandName);
    const deleted = await tool(page, key, 'delete_post', { post_id: created.id });
    expect(deleted.deleted).toBe(true);
    expect(fixture('state', info.project.name).posts.some((post: any) => post.id === created.id)).toBe(false);
});

test('API-02 · key inválida no devuelve herramientas ni datos', async ({ page }) => {
    const invalid = await rpc(page, 't43-not-a-real-key', 'tools/list');
    expect(invalid.response.status()).toBe(401);
    expect(invalid.text).not.toContain('list_brands');
    expect(invalid.text).not.toContain('e2e-t43-');
});

test('API-03 · MCP rechaza leer una marca ajena sin revelar sus datos', async ({ page, browser }, info) => {
    const seed = fixture<Seed>('seed', info.project.name);
    const context = await browser.newContext({ baseURL: 'http://localhost:8081', locale: 'es-ES', timezoneId: 'Europe/Madrid', viewport: info.project.use.viewport, isMobile: info.project.use.isMobile, hasTouch: info.project.use.hasTouch });
    const pageB = await context.newPage();
    try {
        await login(pageB, other);
        const key = await newKey(pageB, 'e2e t43 API isolation');
        const result = await rpc(pageB, key, 'tools/call', { name: 'list_accounts', arguments: { brand_id: seed.brandId } });
        expect(result.response.status()).toBe(200);
        expect(result.payload.result.isError).toBe(true);
        expect(result.text).not.toContain(seed.brandName);
        expect(result.text).not.toContain('T43 Facebook');
        const list = await rpc(pageB, key, 'tools/call', { name: 'list_brands', arguments: {} });
        expect(list.text).not.toContain(seed.brandName);
    } finally { await context.close(); }
});
