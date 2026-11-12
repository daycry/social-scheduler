#!/usr/bin/env node
// Ejecuta los tests de Node de tests/frontend con `node --test --expose-gc`.
//   unit    -> *.test.mjs sin navegador (excluye *.browser.test.mjs) y tests/e2e/isolated/*.test.cjs
//   browser -> *.browser.test.mjs (Chromium de Playwright, ffmpeg y vite)
// `studio-captions-render-entry.mjs` no es un test (no termina en .test.mjs) y nunca se lista.
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export function listTests(mode, dir = 'tests/frontend') {
    const found = fs.readdirSync(dir).filter(f => f.endsWith('.test.mjs') && f.endsWith('.browser.test.mjs') === (mode === 'browser')).sort().map(f => path.join(dir, f));
    // Node tests of the E2E harness (no browser): static checks and the keep-alive HTTP frontend.
    const harness = 'tests/e2e/isolated';
    if (mode === 'unit' && dir === 'tests/frontend') found.push(...fs.readdirSync(harness).filter(f => f.endsWith('.test.cjs')).sort().map(f => path.join(harness, f)));
    return found;
}

const mode = process.argv[2];
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    if (!['unit', 'browser'].includes(mode)) { console.error('Uso: run-node-tests.mjs <unit|browser> [--list]'); process.exit(2); }
    const files = listTests(mode);
    if (process.argv.includes('--list')) { console.log(files.join('\n')); process.exit(0); }
    const run = spawnSync(process.execPath, ['--expose-gc', '--test', ...files], { stdio: 'inherit' });
    process.exit(run.status ?? 1);
}
