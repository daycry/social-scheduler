import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { purge } from '../../scripts/test/purge-testing.mjs';

const DAY = 86_400_000;
function fixture() {
    const root = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'purge-')), 'testing');
    fs.mkdirSync(path.join(root, 't43'), { recursive: true });
    fs.writeFileSync(path.join(root, '.gitignore'), '*');
    const now = Date.now();
    const make = (rel, ageDays) => {
        const p = path.join(root, rel); fs.mkdirSync(p, { recursive: true }); fs.writeFileSync(path.join(p, 'f'), 'x');
        const t = new Date(now - ageDays * DAY); fs.utimesSync(p, t, t); return p;
    };
    return { root, now, make };
}

test('conserva las N más recientes y las jóvenes; borra el resto, ocultos y la ejecución en curso a salvo', () => {
    const { root, now, make } = fixture();
    try {
        for (let i = 0; i < 8; i++) make(`t43/run-old-${i}`, 10 + i);
        make('t43/run-young', 0.5); make('loose-dir', 30);
        const current = make('t43/run-current', 99);
        const result = purge({ root, keep: 3, minAgeDays: 2, now, protectedPaths: [current] });
        assert.equal(result.failed.length, 0);
        const left = fs.readdirSync(path.join(root, 't43')).sort();
        // young + 3 más recientes por mtime (young, old-0, old-1) + current protegida
        assert.deepEqual(left, ['run-current', 'run-old-0', 'run-old-1', 'run-young']);
        assert.equal(fs.existsSync(path.join(root, 'loose-dir')), false);
        assert.equal(fs.existsSync(path.join(root, '.gitignore')), true);
    } finally { fs.rmSync(path.dirname(root), { recursive: true, force: true }); }
});

test('--dry-run informa pero no borra nada', () => {
    const { root, now, make } = fixture();
    try {
        for (let i = 0; i < 4; i++) make(`t43/run-${i}`, 20 + i);
        const result = purge({ root, keep: 1, minAgeDays: 1, dryRun: true, now });
        assert.equal(result.removed.length, 3);
        assert.equal(fs.readdirSync(path.join(root, 't43')).length, 4);
    } finally { fs.rmSync(path.dirname(root), { recursive: true, force: true }); }
});

test('una raíz inexistente no falla', () => {
    assert.deepEqual(purge({ root: path.join(os.tmpdir(), 'no-existe-purge-xyz') }), { kept: [], removed: [], failed: [] });
});
