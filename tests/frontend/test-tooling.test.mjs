import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { launchesBrowser, listTests } from '../../scripts/test/run-node-tests.mjs';

const read = f => fs.readFileSync(f, 'utf8');

test('test:unit incluye los tests de Node de tests/e2e/isolated (sin navegador)', () => {
    const files = listTests('unit').map(f => f.replaceAll('\\', '/'));
    assert.ok(files.includes('tests/e2e/isolated/safety.test.cjs'));
    assert.ok(files.includes('tests/e2e/isolated/http-server.test.cjs'));
    assert.ok(!files.some(f => f.includes('.browser.test.')));
    assert.ok(!listTests('browser').some(f => f.includes('isolated')));
});

test('el puerto E2E por defecto es libre y no el 8080; E2E_PORT inválido falla', () => {
    const probe = 'import("./tests/e2e/isolated/runtime.ts").then(m=>console.log(m.port),e=>{console.log("ERR "+e.message)})';
    const run = env => {
        const clean = { ...process.env, ...env };
        for (const key of ['E2E_GENERAL_RUN_ROOT', 'T43_RUN_ROOT', 'E2E_BASE_URL', 'E2E_NO_SERVER', ...(env.E2E_PORT ? [] : ['E2E_PORT'])]) if (!(key in env)) delete clean[key];
        return execFileSync(process.execPath, ['--input-type=module', '-e', probe], { env: clean, encoding: 'utf8' }).trim();
    };
    assert.notEqual(Number(run({})), 8080);
    assert.equal(run({ E2E_PORT: '18123' }), '18123');
    assert.match(run({ E2E_PORT: 'abc' }), /^ERR /);
});

test('roadmap.spec no fija ningún puerto 808x', () => {
    for (const f of fs.readdirSync('tests/e2e/roadmap').filter(n => /\.(ts|php)$/.test(n))) assert.doesNotMatch(read(`tests/e2e/roadmap/${f}`), /localhost:808\d/, f);
});

test('el teardown borra usuarios y purga en bloques independientes', () => {
    const src = read('tests/e2e/global-teardown.ts');
    assert.match(src, /try\s*\{\s*deleteE2eUsers\(slot\)/);
    assert.match(src, /E2E_PURGE_KEEP/);
    assert.doesNotMatch(src, /Number\(process\.env\.E2E_PURGE/);
});

test('verifyRunIdentity acepta el servidor de SU ejecución y rechaza el de otra', async () => {
    const { verifyRunIdentity } = await import('../e2e/isolated/identity.ts');
    const reply = storage => async () => ({ ok: true, json: async () => ({ storage }) });
    // Portable: a runRoot resolved on this OS (backslashes on Windows, slashes elsewhere) and the storage the server reports in posix form.
    const root = path.resolve('run', 'run-general-a'), posix = p => p.split(path.sep).join('/');
    await verifyRunIdentity('http://localhost:1', root, reply(posix(root)));
    await assert.rejects(() => verifyRunIdentity('http://localhost:1', root, reply(posix(root).replace(/-a$/, '-b'))), /otra ejecuci|another run/i);
    await assert.rejects(() => verifyRunIdentity('http://localhost:1', '/r', async () => ({ ok: false, status: 500, json: async () => ({}) })), /500/);
});

test('test:unit nunca lanza un navegador: lo que importa playwright (directa o transitivamente) va a test:browser', () => {
    const unit = listTests('unit'), browser = listTests('browser').map(f => f.replaceAll('\\', '/'));
    for (const file of unit) {
        assert.equal(launchesBrowser(file), false, `${file} lanza un navegador y no puede estar en test:unit`);
        // Independent of the classifier: no direct driver import or launch in a unit test.
        assert.doesNotMatch(read(file), /(?:from|require\()\s*['"](?:playwright|playwright-core|puppeteer)['"]|chromium\.launch\(/, `${file} importa un navegador`);
    }
    // Real cases: direct import, and through scripts/studio/av-video.mjs (renderVisualSegment launches Chromium).
    assert.ok(browser.includes('tests/frontend/studio-advanced-rendering.test.mjs'));
    assert.ok(browser.includes('tests/frontend/studio-audiovisual-render.test.mjs'));
    assert.ok(browser.every(f => !f.startsWith('tests/e2e/')));
    assert.ok(listTests('unit').length > 0 && unit.every(f => !browser.includes(f.replaceAll('\\', '/'))));
});

test('launchesBrowser sigue la cadena de imports y no confunde @playwright/test con un navegador', () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'launch-'));
    try {
        const spec = name => `'${name}'`; // keeps these fixtures from being read as imports of THIS file
        fs.writeFileSync(path.join(dir, 'driver.mjs'), `import { chromium } from ${spec('playwright')};
export const x = chromium;
`);
        fs.writeFileSync(path.join(dir, 'middle.mjs'), `export * from ${spec('./driver.mjs')};
`);
        fs.writeFileSync(path.join(dir, 'indirect.test.mjs'), `import ${spec('./middle.mjs')};
`);
        fs.writeFileSync(path.join(dir, 'runner.test.mjs'), `import { test } from ${spec('@playwright/test')};
import fs from ${spec('node:fs')};
`);
        assert.equal(launchesBrowser(path.join(dir, 'indirect.test.mjs')), true);
        assert.equal(launchesBrowser(path.join(dir, 'runner.test.mjs')), false);
    } finally { fs.rmSync(dir, { recursive: true, force: true }); }
});

test('la guía de T-43 describe el puerto actual del adaptador general', () => {
    assert.doesNotMatch(read('docs/guias/pruebas-t43.md'), /URL diferente de `http:\/\/localhost:8080`/);
});

test('la guía de desarrollo local ya no propone arrancar el servidor a mano con E2E_NO_SERVER', () => {
    const doc = read('docs/guias/desarrollo-local.md');
    assert.doesNotMatch(doc, /y usar `E2E_NO_SERVER=1`/);
    assert.doesNotMatch(doc, /\nE2E_NO_SERVER=1 npm run test:e2e/);
});

test('E2E paralelo: un slot (puerto, runRoot y SQLite) por worker, distintos entre sí; E2E_WORKERS inválido falla', () => {
    const probe = 'import("./tests/e2e/isolated/runtime.ts").then(m=>console.log(JSON.stringify(m.slots.map(s=>[s.port,s.runRoot,s.environment.DB_DATABASE]))),e=>{console.log("ERR "+e.message)})';
    const run = env => {
        const clean = { ...process.env, ...env };
        for (const key of ['E2E_GENERAL_RUN_ROOT', 'T43_RUN_ROOT', 'E2E_BASE_URL', 'E2E_NO_SERVER', 'E2E_SLOTS', 'E2E_PORT', 'E2E_WORKERS']) if (!(key in env)) delete clean[key];
        return execFileSync(process.execPath, ['--input-type=module', '-e', probe], { env: clean, encoding: 'utf8' }).trim();
    };
    const slots = JSON.parse(run({ E2E_WORKERS: '3' }));
    assert.equal(slots.length, 3);
    for (const column of [0, 1, 2]) assert.equal(new Set(slots.map(s => s[column])).size, 3, `columna ${column} duplicada`);
    assert.equal(JSON.parse(run({})).length, 2, 'por defecto 2 workers');
    assert.equal(JSON.parse(run({ E2E_WORKERS: '2', E2E_PORT: '18124' }))[0][0], 18124);
    for (const bad of ['0', '17', 'abc', '1.5']) assert.match(run({ E2E_WORKERS: bad }), /^ERR .*E2E_WORKERS/);
});

test('verifyRunIdentitySync acepta el servidor de su slot y rechaza el de otro slot', async () => {
    const { verifyRunIdentitySync } = await import('../e2e/isolated/runtime.ts');
    const ask = storage => () => JSON.stringify({ storage });
    verifyRunIdentitySync('http://localhost:1', 'C:/run/run-general-a', ask('C:/run/run-general-a'));
    assert.throws(() => verifyRunIdentitySync('http://localhost:1', 'C:/run/run-general-a', ask('C:/run/run-general-b')), /otra ejecuci|otro worker/);
});

// Salvaguarda de cobertura móvil (T-14): los proyectos mobile y mobile-360 solo ejecutan lo etiquetado @responsive, así que un test
// que mira desbordamiento, auditoría axe, el viewport o ramas por proyecto no puede quedarse solo en desktop sin justificarlo aquí.
const RESPONSIVE_MARKERS = /expectNoOverflow|overflowX|scrollWidth|project\.name\s*(?:===|!==|==|\.startsWith|\.includes)|project\.use|isMobile|viewportSize|\.viewport|innerWidth|\baxe\w*\(|AxeBuilder|setViewportSize/;
// «fichero: inicio del título» -> motivo. Falsos positivos de la detección por helpers; un test nuevo solo entra aquí con motivo.
const RESPONSIVE_EXCEPTIONS = {
    'gif-picker.spec.ts: E2E-09 · GIF: Esc cierra el modal': 'solo lógica de foco, vacío y errores 429; los helpers `catalog`/`newSeen` no miran layout (falso positivo de la detección)',
};
const indentOf = line => line.length - line.trimStart().length;
function blockEnd(lines, i) {
    const ind = indentOf(lines[i]);
    let j = i + 1;
    while (j < lines.length && !(indentOf(lines[j]) === ind && lines[j].trimStart().startsWith('}'))) j++;
    return j;
}
export function untaggedResponsiveTests(file, source = read(file)) {
    const lines = source.split('\n');
    const helpers = new Set();
    lines.forEach((line, i) => {
        const m = line.match(/^\s*(?:export )?(?:async )?function (\w+)/) || line.match(/^\s*(?:export )?const (\w+) = (?:async )?\(/);
        if (m && RESPONSIVE_MARKERS.test(lines.slice(i, blockEnd(lines, i) + 1).join('\n'))) helpers.add(m[1]);
    });
    const tagged = [];
    lines.forEach((line, i) => { if (/test\.describe\(/.test(line) && line.includes('@responsive')) tagged.push([i, blockEnd(lines, i)]); });
    const found = [];
    lines.forEach((line, i) => {
        if (!/^\s*test(\.only)?\(/.test(line) || line.includes('@responsive') || tagged.some(([a, b]) => i >= a && i <= b)) return;
        const single = line.trimEnd().endsWith(');') && line.split('(').length === line.split(')').length;
        const body = lines.slice(i, (single ? i : blockEnd(lines, i)) + 1).join('\n');
        if (RESPONSIVE_MARKERS.test(body) || [...helpers].some(h => new RegExp('(^|[^A-Za-z0-9_])' + h + '[(]').test(body))) found.push(line.trim());
    });
    return found;
}

test('todo test que mira desbordamiento, axe, viewport o ramas por proyecto lleva @responsive (o excepcion justificada)', () => {
    const offenders = [];
    for (const name of fs.readdirSync('tests/e2e').filter(f => f.endsWith('.spec.ts'))) {
        for (const title of untaggedResponsiveTests(`tests/e2e/${name}`)) {
            if (!Object.keys(RESPONSIVE_EXCEPTIONS).some(key => key.startsWith(`${name}: `) && title.includes(key.slice(name.length + 2)))) offenders.push(`${name}: ${title.slice(0, 90)}`);
        }
    }
    assert.deepEqual(offenders, [], 'etiqueta con { tag: \'@responsive\' } o justifica la excepcion en RESPONSIVE_EXCEPTIONS');
});

test('la deteccion de @responsive distingue tests etiquetados, de grupo y sin etiquetar', () => {
    const src = [
        "test('a', async ({ page }) => {", '    await expectNoOverflow(page);', '});',
        "test('b', { tag: '@responsive' }, async ({ page }) => {", '    await expectNoOverflow(page);', '});',
        "test.describe('g', { tag: '@responsive' }, () => {", "    test('c', async ({ page }) => {", '        await axeNow(page);', '    });', '});',
        "test('d', async ({ page }) => {", '    await page.goto("/x");', '});',
    ].join('\n');
    assert.deepEqual(untaggedResponsiveTests('x.spec.ts', src), ["test('a', async ({ page }) => {"]);
});

// E-16: dormir un tiempo fijo hace los E2E lentos y frágiles; se espera a un evento (expect.poll, toBeVisible, animationsDone).
// Excepciones justificadas: fichero -> cuántas llamadas admite y por qué.
const WAIT_FOR_TIMEOUT_EXCEPTIONS = {
    'tests/e2e/helpers.ts': { max: 1, why: 'quietFor(): aserciones negativas en el tiempo (que algo NO ocurra durante una ventana), sin evento que esperar' },
};

function e2eSources(dir = 'tests/e2e') {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
        const full = `${dir}/${entry.name}`;
        if (entry.isDirectory()) return entry.name === 'node_modules' ? [] : e2eSources(full);
        return /\.(ts|mts|cts|mjs|cjs|js)$/.test(entry.name) ? [full] : [];
    });
}

function waitForTimeoutCalls(src) {
    return src.split('\n').filter(line => /\.waitForTimeout\s*\(/.test(line) && !/^\s*(\/\/|\*|\/\*)/.test(line)).length;
}

test('los E2E no usan waitForTimeout salvo las excepciones justificadas', () => {
    const offenders = [];
    for (const file of e2eSources()) {
        const calls = waitForTimeoutCalls(read(file));
        const allowed = WAIT_FOR_TIMEOUT_EXCEPTIONS[file]?.max ?? 0;
        if (calls > allowed) offenders.push(`${file}: ${calls} (admite ${allowed})`);
    }
    assert.deepEqual(offenders, [], 'espera un evento (expect.poll, toBeVisible, animationsDone); si es una ausencia en el tiempo usa quietFor() de helpers.ts');
    for (const [file, { why }] of Object.entries(WAIT_FOR_TIMEOUT_EXCEPTIONS)) assert.ok(why.length > 20 && fs.existsSync(file), `${file}: la excepcion necesita motivo`);
});

test('la deteccion de waitForTimeout ve las llamadas y no los comentarios', () => {
    assert.equal(waitForTimeoutCalls("await page.waitForTimeout(400);\n// page.waitForTimeout(1)\n * waitForTimeout\nawait pub.waitForTimeout (5);"), 2);
    assert.equal(waitForTimeoutCalls('await quietFor(page, 400);'), 0);
});
