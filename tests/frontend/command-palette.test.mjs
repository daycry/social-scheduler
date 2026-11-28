import assert from 'node:assert/strict';
import test from 'node:test';
import {
    createRemoteSearch,
    filterPages,
    formatLocalDate,
    groupEntries,
    nextIndex,
    pageCommands,
    queryLength,
    restoreIndex,
} from '../../resources/js/lib/commandPalette.js';
import { HttpError } from '../../resources/js/lib/http.js';

/** Contexto mínimo del menú: `has` decide qué rutas opcionales existen y `brand` si hay marca elegida. */
function context({ brand = null, missing = [], current = [] } = {}) {
    const route = Object.assign((name, params = {}) => `/${name}?${JSON.stringify(params)}`, { params: {} });
    return {
        brand,
        q: brand ? { brand_id: brand.id } : {},
        shell: { networks: [{ value: 'bluesky', label: 'Bluesky' }], accounts: {}, brands: [], currentBrand: brand },
        page: { props: { view: 'week', unreadInbox: 0 } },
        route,
        is: (...names) => names.some((name) => current.includes(name)),
        has: (name) => !missing.includes(name),
    };
}

const titles = (pages) => pages.map((page) => page.title);

test('pages and actions come from navigation.js and keep its conditions', () => {
    const withBrand = pageCommands(context({ brand: { id: 4, name: 'Marca' } }));
    assert.ok(titles(withBrand).includes('Crear publicación'));
    assert.ok(titles(withBrand).includes('Importar CSV'));
    assert.ok(titles(withBrand).includes('Competidores'));
    assert.ok(titles(withBrand).includes('Bluesky'), 'las redes del menú también');
    assert.equal(withBrand.find((page) => page.title === 'Bluesky').network, 'bluesky');

    const withoutBrand = pageCommands(context({ missing: ['competitors.index', 'studio.index'] }));
    assert.ok(!titles(withoutBrand).includes('Crear publicación'), 'sin marca no hay enlace');
    assert.ok(!titles(withoutBrand).includes('Competidores'), 'una ruta no registrada no aparece');
    assert.ok(!titles(withoutBrand).includes('Mis diseños'), 'un módulo opcional ausente no aparece');
    assert.ok(titles(withoutBrand).includes('Perfil'), 'las secciones de la cuenta siempre');
});

test('page entries are unique and carry their module as context', () => {
    const pages = pageCommands(context({ brand: { id: 1, name: 'M' } }));
    const keys = pages.map((page) => page.key);
    assert.equal(new Set(keys).size, keys.length);
    assert.equal(pages.find((page) => page.title === 'Calendario').meta, 'Planificación');
    assert.ok(pages.every((page) => page.type === 'page' && page.external === false));
});

test('filtering ignores case and accents and ranks title prefixes first', () => {
    const pages = pageCommands(context({ brand: { id: 1, name: 'M' } }));
    assert.deepEqual(titles(filterPages(pages, 'CAMPANAS')), ['Campañas']);
    assert.equal(titles(filterPages(pages, 'crear'))[0], 'Crear publicación');
    assert.ok(titles(filterPages(pages, 'planificacion')).includes('Calendario'), 'también por el nombre del módulo');
    assert.deepEqual(filterPages(pages, 'zzzz'), []);
    assert.equal(filterPages(pages, '   ', 3).length, 3);
    assert.ok(titles(filterPages(pages, 'publicación crear')).includes('Crear publicación'), 'cualquier orden de palabras');
});

test('groups keep a continuous index across pages and server results', () => {
    const pages = filterPages(pageCommands(context({ brand: { id: 1, name: 'M' } })), 'calendario');
    const results = [
        { type: 'brand', id: 7, title: 'Café', brand: null, date: null, status: null, url: '/brands/7', external: false },
        { type: 'post', id: 3, title: 'Oferta', brand: 'Café', date: '2026-11-19 20:30', status: 'Programada', url: '/posts/3/edit', external: false },
    ];
    const groups = groupEntries(pages, results);

    assert.deepEqual(groups.map((group) => group.type), ['page', 'post', 'brand']);
    assert.deepEqual(groups.flatMap((group) => group.entries.map((entry) => entry.index)), [0, 1, 2]);
    const post = groups[1].entries[0];
    assert.equal(post.title, 'Oferta');
    assert.ok(post.meta.startsWith('Café · ') && post.meta.endsWith(' · Programada'));
    assert.deepEqual(groupEntries([], []), []);
});

test('local dates are written as given, in any browser timezone, even on a daylight saving gap', () => {
    const previous = process.env.TZ;
    process.env.TZ = 'Europe/Madrid';
    try {
        assert.match(formatLocalDate('2026-03-29 02:30'), / 2:30/, 'esa hora no existe en Madrid y no debe moverse');
        assert.match(formatLocalDate('2026-10-25 02:30'), / 2:30/);
    } finally {
        if (previous === undefined) delete process.env.TZ;
        else process.env.TZ = previous;
    }
    assert.match(formatLocalDate('2026-11-19 20:30'), /19/);
    assert.match(formatLocalDate('2026-11-19 20:30'), /20:30/);
    assert.doesNotMatch(formatLocalDate('2026-11-19'), /:/);
    assert.equal(formatLocalDate(null), '');
    assert.equal(formatLocalDate('ayer'), '');
});

test('keyboard navigation wraps around with the arrows', () => {
    assert.equal(nextIndex(0, 'ArrowDown', 3), 1);
    assert.equal(nextIndex(2, 'ArrowDown', 3), 0);
    assert.equal(nextIndex(0, 'ArrowUp', 3), 2);
    assert.equal(nextIndex(1, 'Home', 3), -1, 'Inicio y Fin mueven el cursor del campo, no la opción');
    assert.equal(nextIndex(1, 'End', 3), -1);
    assert.equal(nextIndex(0, 'a', 3), -1);
    assert.equal(nextIndex(0, 'ArrowDown', 0), -1);
});

test('the page order starts with the active module, so its pages are always on the first screen', () => {
    const pages = pageCommands(context({ brand: { id: 1, name: 'M' }, current: ['calendar.*'] }));
    assert.equal(pages[0].title, 'Calendario');
    assert.equal(pages[0].meta, 'Planificación');
    assert.ok(titles(filterPages(pages, '')).includes('Calendario'));
    assert.equal(pageCommands(context({ current: [] }))[0].meta, 'Cuenta', 'sin módulo de la barra activo, el contextual de la cuenta');
});

test('the active option is kept when it is still in the list and resets to the first otherwise', () => {
    const list = [{ key: 'a' }, { key: 'b' }, { key: 'c' }];
    assert.equal(restoreIndex(list, 'b'), 1);
    assert.equal(restoreIndex(list, 'z'), 0);
    assert.equal(restoreIndex(list, undefined), 0);
    assert.equal(restoreIndex([], 'b'), 0);
});

test('the query is measured in code points, like the server does', () => {
    assert.equal(queryLength('😀'), 1);
    assert.equal(queryLength(' a😀 '), 2);
    assert.equal(queryLength('ab'), 2);
});

/** Temporizador manual y peticiones que se resuelven cuando el test lo decide. */
function harness() {
    const timers = new Map();
    let nextTimer = 1;
    const requests = [];
    const states = [];
    const search = createRemoteSearch({
        delay: 250,
        setTimer: (callback) => {
            timers.set(nextTimer, callback);
            return nextTimer++;
        },
        clearTimer: (id) => timers.delete(id),
        request: (query, signal) => new Promise((resolve, reject) => requests.push({ query, signal, resolve, reject })),
        onChange: (state) => states.push(state),
    });
    const fire = () => {
        const [id, callback] = [...timers][0];
        timers.delete(id);
        callback();
    };
    const result = (title) => ({ type: 'post', id: 1, title, brand: null, date: null, status: null, url: '/p', external: false });
    return { search, timers, requests, states, fire, result, last: () => states.at(-1) };
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('typing is debounced into a single request and short queries ask for nothing', async () => {
    const { search, timers, requests, fire, last } = harness();
    search.update('a');
    assert.equal(timers.size, 0);
    assert.equal(last().status, 'idle');
    search.update('😀');
    assert.equal(timers.size, 0, 'un emoji es un solo carácter: el servidor lo rechazaría');
    search.update('a😀');
    assert.equal(timers.size, 1);

    search.update('ab');
    search.update('abc');
    search.update(' abcd ');
    assert.equal(timers.size, 1, 'cada tecla anula el temporizador anterior');
    assert.equal(last().status, 'loading');
    assert.equal(requests.length, 0);

    fire();
    assert.deepEqual(requests.map((request) => request.query), ['abcd']);
});

test('a new query aborts the previous request and drops its late answer', async () => {
    const { search, requests, fire, result, last } = harness();
    search.update('uno');
    fire();
    search.update('dos');
    assert.equal(requests[0].signal.aborted, true);
    fire();
    assert.equal(requests[1].signal.aborted, false);

    requests[1].resolve([result('dos')]);
    await settle();
    requests[0].resolve([result('uno')]);
    await settle();

    assert.equal(last().status, 'ready');
    assert.equal(last().query, 'dos');
    assert.deepEqual(last().results.map((r) => r.title), ['dos']);
});

test('answers arriving out of order never overwrite the newest one, even if the transport ignores the abort', async () => {
    const { search, requests, fire, result, last } = harness();
    search.update('uno');
    fire();
    search.update('dos');
    fire();

    requests[0].resolve([result('viejo')]);
    await settle();
    assert.equal(last().status, 'loading', 'la respuesta vieja no cierra el estado de carga de la nueva');
    requests[1].resolve([result('nuevo')]);
    await settle();

    assert.deepEqual(last().results.map((r) => r.title), ['nuevo']);
});

test('closing cancels the timer and the request and ignores what arrives later', async () => {
    const { search, timers, requests, fire, result, last } = harness();
    search.update('uno');
    search.close();
    assert.equal(timers.size, 0);

    search.update('dos');
    fire();
    search.close();
    assert.equal(requests[0].signal.aborted, true);
    requests[0].resolve([result('tarde')]);
    await settle();

    assert.equal(last().status, 'idle');
    assert.deepEqual(last().results, []);
});

test('errors become a message, and a cancelled request is not an error', async () => {
    const { search, requests, fire, last } = harness();
    search.update('uno');
    fire();
    requests[0].reject(new HttpError('Too Many Attempts.', 429));
    await settle();
    assert.equal(last().status, 'error');
    assert.match(last().error, /Demasiadas búsquedas/);

    search.update('dos');
    fire();
    search.update('tres');
    requests[1].reject(new DOMException('Aborted', 'AbortError'));
    await settle();
    assert.equal(last().status, 'loading');

    fire();
    requests[2].reject(new Error('red'));
    await settle();
    assert.match(last().error, /No se pudo buscar/);
});
