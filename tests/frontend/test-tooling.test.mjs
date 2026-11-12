import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { listTests } from '../../scripts/test/run-node-tests.mjs';

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
    assert.match(src, /try\s*\{\s*deleteE2eUsers\(\)/);
    assert.match(src, /E2E_PURGE_KEEP/);
    assert.doesNotMatch(src, /Number\(process\.env\.E2E_PURGE/);
});

test('verifyRunIdentity acepta el servidor de SU ejecución y rechaza el de otra', async () => {
    const { verifyRunIdentity } = await import('../e2e/isolated/identity.ts');
    const reply = storage => async () => ({ ok: true, json: async () => ({ storage }) });
    await verifyRunIdentity('http://localhost:1', 'C:\\run\\run-general-a', reply('C:/run/run-general-a'));
    await assert.rejects(() => verifyRunIdentity('http://localhost:1', 'C:\\run\\run-general-a', reply('C:/run/run-general-b')), /otra ejecuci|another run/i);
    await assert.rejects(() => verifyRunIdentity('http://localhost:1', '/r', async () => ({ ok: false, status: 500, json: async () => ({}) })), /500/);
});

test('la guía de T-43 describe el puerto actual del adaptador general', () => {
    assert.doesNotMatch(read('docs/guias/pruebas-t43.md'), /URL diferente de `http:\/\/localhost:8080`/);
});
