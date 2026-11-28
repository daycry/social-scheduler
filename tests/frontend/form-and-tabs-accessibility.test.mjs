import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { renderToString } from '@vue/server-renderer';
import { createServer } from 'vite';
import { computed, createSSRApp } from 'vue';
import { onTabsKeydown } from '../../resources/js/Composables/useTabs.js';
import { firstGroupError } from '../../resources/js/lib/form-errors.js';

const videoError = 'Usa la URL de un video de YouTube';
const permissionError = 'El permiso seleccionado no es válido.';
const loginError = 'Estas credenciales no coinciden con nuestros registros.';
const fixtureModules = new Map([
    ['@inertiajs/vue3', `
        export const Head = () => null;
        export const Link = (_, { slots }) => slots.default?.();
        export const router = {};
        export const useForm = fields => ({
            ...fields, processing: false,
            errors: 'email' in fields ? { email: ${JSON.stringify(loginError)} } :
                'abilities' in fields ? { 'abilities.2': ${JSON.stringify(permissionError)} } :
                'pages' in fields ? { 'pages.0.blocks.0.url': ${JSON.stringify(videoError)} } :
                'blocks' in fields ? { 'blocks.0.url': ${JSON.stringify(videoError)} } : {},
        });
        export const usePage = () => ({ props: {} });
    `],
    ['@laravel/passkeys', 'export const Passkeys = {};'],
    ['@laravel/passkeys/vue', 'export const usePasskeyVerify = () => ({ errorInstance: { value: null } });'],
    ['@/Layouts/AuthenticatedLayout.vue', 'export default (_, { slots }) => slots.default?.();'],
]);

let server;

before(async () => {
    server = await createServer({
        configFile: false,
        cacheDir: '.cache/vite-accessibility',
        ssr: { noExternal: ['@inertiajs/vue3', '@laravel/passkeys'] },
        resolve: {
            // La resolución nativa de rutas puede hacer que Vite cargue el entrypoint CommonJS de Vue como ESM en Windows.
            preserveSymlinks: true,
            alias: { '@': fileURLToPath(new URL('../../resources/js', import.meta.url)) },
        },
        server: { middlewareMode: true, ws: false, watch: null },
        plugins: [{
            name: 'accessibility-fixtures',
            enforce: 'pre',
            resolveId(source) {
                if (source.endsWith('/Layouts/AuthenticatedLayout.vue')) {
                    return '\0@/Layouts/AuthenticatedLayout.vue';
                }
                return fixtureModules.has(source) ? '\0' + source : null;
            },
            load: id => id.startsWith('\0') ? fixtureModules.get(id.slice(1)) : null,
        }, vue()],
    });
});

after(async () => { await server?.close(); });

async function render(file, props, directory = 'Pages') {
    const { default: component } = await server.ssrLoadModule('/resources/js/' + directory + '/' + file);
    const app = createSSRApp(component, props);
    const { confirmKey, createConfirmState } = await server.ssrLoadModule('/resources/js/Composables/useConfirm.js');
    const { themeKey } = await server.ssrLoadModule('/resources/js/Composables/useTheme.js');
    app.provide(confirmKey, createConfirmState());
    app.provide(themeKey, {
        isGuest: computed(() => true),
        preference: computed(() => 'system'),
        dark: computed(() => false),
        setGuestPreference() {},
    });
    app.config.globalProperties.$page = { props: {} };
    app.config.globalProperties.route = name => '/' + name;
    return renderToString(app);
}

test('the login input points at its rendered validation error', async () => {
    const html = await render('Auth/Login.vue');
    const email = html.match(/<input\b[^>]+id="email"[^>]*>/)?.[0] ?? '';
    assert.ok(email, 'the email input is rendered');
    assert.match(html, new RegExp(loginError.replace('.', '\\.')));
    assert.match(email, /aria-invalid="true"/);
    const errorId = email.match(/aria-describedby="([^"]+)"/)?.[1];
    assert.ok(errorId, 'the invalid input has an accessible description');
    assert.ok(html.includes(`id="${errorId}"`), 'the description resolves to an element');
});

test('installation tabs give a single tab stop and connect the visible panel to that tab', async () => {
    const html = await render('ApiKeys/Index.vue', {
        keys: [], abilities: {}, presets: {}, endpoint: 'http://localhost/mcp',
    });
    const tabs = html.match(/<button\b[^>]+role="tab"[^>]*>/g) ?? [];
    assert.ok(tabs.length > 1, 'the installation client has multiple tabs');
    const active = tabs.find(tab => tab.includes('aria-selected="true"'));
    assert.ok(active, 'one client is selected');
    assert.match(active, /tabindex="0"/);
    assert.ok(tabs.filter(tab => tab !== active).every(tab => tab.includes('tabindex="-1"')));
    const panel = html.match(/<(?:div|section)\b[^>]+role="tabpanel"[^>]*>/)?.[0] ?? '';
    const id = active.match(/aria-controls="([^"]+)"/)?.[1];
    assert.ok(id && panel.includes(`id="${id}"`));
    assert.match(panel, /tabindex="0"/);
});

test('permissions associate validation errors beyond the first array entry', async () => {
    const html = await render('ApiKeys/Index.vue', {
        keys: [], abilities: { 'posts:read': 'Publicaciones', 'brands:read': 'Marcas' }, presets: {}, endpoint: 'http://localhost/mcp',
    });
    const fieldset = html.match(/<fieldset\b[^>]*>/)?.[0] ?? '';
    const errorId = fieldset.match(/aria-describedby="([^"]+)"/)?.[1];
    assert.ok(errorId, 'the permission group points at its validation error');
    assert.match(fieldset, /data-error-target="true"/);
    assert.match(fieldset, /tabindex="-1"/);
    assert.ok(html.includes(`id="${errorId}"`));
    assert.ok(html.includes(permissionError));
    const checkboxes = html.match(/<input\b[^>]*type="checkbox"[^>]*>/g) ?? [];
    assert.equal(checkboxes.length, 2);
    assert.ok(checkboxes.every(checkbox => !checkbox.includes('aria-describedby') && !checkbox.includes('aria-invalid')));
});

test('a group error prefers its root and matches only its own indexed elements', () => {
    const errors = { format_versions: 'Otro grupo', 'formats.2': 'Formato no válido' };
    assert.equal(firstGroupError(errors, 'formats'), 'Formato no válido');
    assert.equal(firstGroupError({ ...errors, formats: 'Elige un formato' }, 'formats'), 'Elige un formato');
    assert.equal(firstGroupError(errors, 'format'), undefined);
});

test('collaboration keeps the available tab reachable if the requested tab is hidden', async () => {
    const html = await render('Studio/StudioCollabPanel.vue', {
        access: { is_owner: false }, link: { token: 'fixture' }, initialTab: 'share',
    }, 'Components');
    const selected = html.match(/<button\b[^>]+role="tab"[^>]+aria-selected="true"[^>]*>/)?.[0] ?? '';
    const id = selected.match(/id="([^"]+)"/)?.[1];
    assert.ok(id?.endsWith('-tab-comments'));
    assert.match(selected, /tabindex="0"/);
    assert.ok(html.includes(`aria-labelledby="${id}"`));
});

test('keyboard selection wraps among tabs in the current list and focuses the new selection', () => {
    const focused = [];
    const selected = [];
    const list = { querySelectorAll: () => tabs };
    const makeTab = (id, active, parent = list) => ({
        closest: selector => selector === '[role="tablist"]' ? parent : null,
        getAttribute: name => name === 'aria-selected' ? String(active) : null,
        focus: () => focused.push(id),
        click: () => selected.push(id),
    });
    const week = makeTab('week', true);
    const month = makeTab('month', false);
    const listView = makeTab('list', false);
    const nested = makeTab('nested', false, {});
    const tabs = [week, month, listView, nested];
    let handled = 0;
    const press = (from, key, more = {}) => onTabsKeydown({
        key, currentTarget: list, target: { closest: () => from },
        preventDefault: () => { handled++; },
        ...more,
    });

    press(week, 'ArrowLeft');
    press(week, 'ArrowRight');
    press(week, 'Home');
    press(week, 'ArrowRight', { altKey: true });
    press(nested, 'End');

    assert.deepEqual(focused, ['list', 'month', 'week']);
    assert.deepEqual(selected, ['list', 'month']);
    assert.equal(handled, 3);
});

test('Sites associates the URL error with the video input', async t => {
    const existingRoute = globalThis.route;
    globalThis.route = name => '/' + name;
    t.after(() => { globalThis.route = existingRoute; });
    const html = await render('Sites/Edit.vue', {
        site: {
            id: 5, title: 'Ejemplo', slug: 'ejemplo', theme_color: '#172554', theme_source: 'custom', theme_mode: 'light',
            pages: [{ key: 'home', title: 'Inicio', slug: 'inicio', blocks: [{ type: 'youtube', url: 'https://example.com' }] }],
        },
        brand: { id: 1 }, limits: { pages: 5, blocks: 10 }, stats: { total: 0, by_page: {} }, theme: { accent: '#172554' },
    });
    const input = html.match(/<input\b[^>]+aria-label="URL del video 1"[^>]*>/)?.[0] ?? '';
    assert.match(input, /aria-invalid="true"/);
    const errorId = input.match(/aria-describedby="([^"]+)"/)?.[1];
    assert.ok(errorId && html.includes(`id="${errorId}"`) && html.includes(videoError));
});

test('SmartLinks associates the URL error with the video input', async () => {
    const html = await render('SmartLinks/Index.vue', {
        brand: { id: 1, name: 'Marca' }, defaults: { theme_color: '#172554', max_blocks: 10 },
        link: { blocks: [{ type: 'youtube', url: 'https://example.com' }] },
        stats: { views: 0, clicks: 0, subscribers: 0 },
    });
    const input = html.match(/<input\b[^>]+aria-label="URL del bloque 1"[^>]*>/)?.[0] ?? '';
    assert.match(input, /aria-invalid="true"/);
    const errorId = input.match(/aria-describedby="([^"]+)"/)?.[1];
    assert.ok(errorId && html.includes(`id="${errorId}"`) && html.includes(videoError));
});
