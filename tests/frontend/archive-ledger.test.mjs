import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const script = fileURLToPath(new URL('../../scripts/roadmap/archive-ledger.mjs', import.meta.url));
const slug = '2026-11-03-creative-social-suite';
const source = [
    '---', 'estado: completado', '---', '', '# Ledger', '', '## Fase 1', '',
    '### T-01 — Cerrar cambio', '- **Estado**: completado', '- **Changelog**: Cambio verificado.',
    '', '**Criterios de aceptación**', '- [x] Cubierto.', '',
    '```md', '```php', '### T-99 — Un ejemplo de código', '- **Estado**: en-progreso', '```',
    '', '### Cierre T-01', '',
    'Detalle histórico y [evidencia](testing/results.json).', '',
].join('\n').replaceAll('\n', '\r\n');

function fixture(t, ledger = source) {
    const temp = realpathSync(tmpdir());
    const root = mkdtempSync(join(temp, 'metricool-ledger-test-'));
    t.after(() => rmSync(root, { recursive: true, force: true }));
    const active = join(root, 'docs', 'roadmap', slug, 'tasks.md');
    const archive = join(dirname(active), 'tasks.archive.md');
    mkdirSync(dirname(active), { recursive: true });
    writeFileSync(active, ledger);
    const run = (...args) => spawnSync(process.execPath, [script, ...args], { cwd: root, encoding: 'utf8' });
    return { active, archive, run };
}

test('archive keeps exact original bytes, a compact ledger and its changelog metadata', t => {
    const { active, archive, run } = fixture(t);
    const preview = run(slug);
    assert.equal(preview.status, 0, preview.stderr);
    assert.equal(existsSync(archive), false);
    assert.equal(readFileSync(active, 'utf8'), source);

    const first = run(slug, '--apply');
    assert.equal(first.status, 0, first.stderr);
    assert.equal(readFileSync(archive, 'utf8'), source);
    const index = readFileSync(active, 'utf8');
    assert.match(index, /\[historial completo\]\(tasks\.archive\.md\)/);
    assert.match(index, /### T-01 — Cerrar cambio/);
    assert.match(index, /\[Detalle histórico\]\(tasks\.archive\.md#t-01--cerrar-cambio\)/);
    assert.match(index, /\*\*Changelog\*\*: Cambio verificado\./);
    assert.ok(!index.includes('Detalle histórico y [evidencia]'));
    assert.ok(!index.includes('T-99'));

    const again = run(slug, '--apply');
    assert.equal(again.status, 0, again.stderr);
    assert.equal(readFileSync(archive, 'utf8'), source);
    assert.equal(readFileSync(active, 'utf8'), index);
});

test('archive rejects an open ledger and a conflicting archive without changing either file', t => {
    const open = source.replace('- **Estado**: completado', '- **Estado**: en-progreso');
    const one = fixture(t, open);
    const rejected = one.run(slug, '--apply');
    assert.notEqual(rejected.status, 0);
    assert.match(rejected.stderr, /tareas cerradas/);
    assert.equal(existsSync(one.archive), false);
    assert.equal(readFileSync(one.active, 'utf8'), open);

    const two = fixture(t);
    writeFileSync(two.archive, 'otro historial');
    const conflict = two.run(slug, '--apply');
    assert.notEqual(conflict.status, 0);
    assert.match(conflict.stderr, /contenido distinto/);
    assert.equal(readFileSync(two.archive, 'utf8'), 'otro historial');
    assert.equal(readFileSync(two.active, 'utf8'), source);
});


test('archive resumes after recording the original and rejects a changed archive after completion', t => {
    const { active, archive, run } = fixture(t);
    writeFileSync(archive, source);
    const resumed = run(slug, '--apply');
    assert.equal(resumed.status, 0, resumed.stderr);
    const index = readFileSync(active, 'utf8');
    assert.match(index, /archivo_sha256: [a-f0-9]{64}/);

    writeFileSync(archive, 'contenido alterado');
    const rejected = run(slug, '--apply');
    assert.notEqual(rejected.status, 0);
    assert.match(rejected.stderr, /no coincide con la huella/);
    assert.equal(readFileSync(active, 'utf8'), index);
    assert.equal(readFileSync(archive, 'utf8'), 'contenido alterado');
});
