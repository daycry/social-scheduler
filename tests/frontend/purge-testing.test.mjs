import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { purge } from '../../scripts/test/purge-testing.mjs';

const DAY = 86_400_000;
const uuid = n => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;

function fixture() {
    const base = fs.mkdtempSync(path.join(os.tmpdir(), 'purge-'));
    const root = path.join(base, 'testing'); const docs = path.join(base, 'docs');
    fs.mkdirSync(path.join(root, 't43'), { recursive: true }); fs.mkdirSync(path.join(docs, 'knowledge', 'journal'), { recursive: true });
    fs.writeFileSync(path.join(root, '.gitignore'), '*');
    const now = Date.now();
    const make = (rel, ageDays) => {
        const p = path.join(root, rel); fs.mkdirSync(p, { recursive: true }); fs.writeFileSync(path.join(p, 'f'), 'x');
        const t = new Date(now - ageDays * DAY); fs.utimesSync(p, t, t); return p;
    };
    return { base, root, docs, now, make };
}

test('solo purga runRoots antiguos no citados: respeta evidencia sin patrón, citada, joven, reciente y en curso', () => {
    const { base, root, docs, now, make } = fixture();
    try {
        for (let i = 0; i < 6; i++) make(`t43/run-general-${uuid(i)}`, 10 + i);     // runRoot general
        for (let i = 0; i < 3; i++) make(`t43/run-${1_700_000_000_000 + i}-${100 + i}`, 40 + i); // runRoot de roadmap
        make(`t43/run-general-${uuid(90)}`, 0.5);                                   // joven
        const current = make(`t43/run-general-${uuid(91)}`, 99);                     // en curso
        const cited = make(`t43/run-general-${uuid(92)}`, 99);                       // citado en docs
        make('t43/run-qa-host-1-2', 99); make('t43/run-1700000000000-9-peer-desktop', 99); // evidencia sin patrón exacto
        make('t12-evidence', 99); make('loose-dir', 99);
        fs.writeFileSync(path.join(docs, 'knowledge', 'journal', 'nota.md'), `evidencia en storage/framework/testing/t43/${path.basename(cited)}/results.json`);
        const dry = purge({ root, keep: 2, minAgeDays: 2, dryRun: true, now, protectedPaths: [current], docsDir: docs });
        assert.equal(dry.failed.length, 0);
        const before = fs.readdirSync(path.join(root, 't43')).length;
        const result = purge({ root, keep: 2, minAgeDays: 2, now, protectedPaths: [current], docsDir: docs });
        assert.deepEqual(result.removed.sort(), dry.removed.sort());
        const left = new Set(fs.readdirSync(path.join(root, 't43')));
        const gone = [...Array(before)].length - left.size;
        assert.equal(result.removed.length, gone);
        for (const name of result.removed.map(p => path.basename(p))) assert.match(name, /^run-(general-[a-f0-9-]{36}|\d{10,}-\d+)$/);
        for (const keep of [`run-general-${uuid(90)}`, `run-general-${uuid(91)}`, `run-general-${uuid(92)}`, 'run-qa-host-1-2', 'run-1700000000000-9-peer-desktop']) assert.ok(left.has(keep), keep);
        assert.ok(fs.existsSync(path.join(root, 't12-evidence')) && fs.existsSync(path.join(root, 'loose-dir')) && fs.existsSync(path.join(root, '.gitignore')));
        // candidatos no citados: joven + 6 general + 3 roadmap (+ la en curso, protegida); keep=2 conserva joven y la general más reciente: se borran 5 + 3
        assert.equal(result.removed.length, 8);
    } finally { fs.rmSync(base, { recursive: true, force: true }); }
});

test('--dry-run informa pero no borra nada', () => {
    const { base, root, docs, now, make } = fixture();
    try {
        for (let i = 0; i < 4; i++) make(`t43/run-general-${uuid(i)}`, 20 + i);
        const result = purge({ root, keep: 1, minAgeDays: 1, dryRun: true, now, docsDir: docs });
        assert.equal(result.removed.length, 3);
        assert.equal(fs.readdirSync(path.join(root, 't43')).length, 4);
    } finally { fs.rmSync(base, { recursive: true, force: true }); }
});

test('una raíz inexistente no falla', () => {
    assert.deepEqual(purge({ root: path.join(os.tmpdir(), 'no-existe-purge-xyz') }), { kept: [], removed: [], failed: [] });
});
