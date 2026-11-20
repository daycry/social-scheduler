#!/usr/bin/env node
// Ejecuta los tests de Node de tests/frontend con `node --test --expose-gc`.
//   unit    -> tests sin navegador: ni *.browser.test.mjs ni ningun test que (transitivamente) importe playwright; mas tests/e2e/isolated/*.test.cjs
//   browser -> *.browser.test.mjs y los que importan playwright (Chromium de Playwright, ffmpeg y vite)
// `studio-captions-render-entry.mjs` no es un test (no termina en .test.mjs) y nunca se lista.
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// A test needs a browser when it (or any repo file it imports, transitively) imports a browser driver. Decided by static analysis
// of the import graph, not by file name: `renderVisualSegment` launches Chromium through scripts/studio/av-video.mjs, so every test that
// imports it is a browser test even if it is called *.test.mjs. `@playwright/test` is the runner, not a launcher, and does not count.
const DRIVERS = /^(playwright|playwright-core|puppeteer|puppeteer-core)(\/|$)/;
const SPECIFIER = /(?:\bfrom\s*|\bimport\s*\(?\s*|\brequire\s*\(\s*)['"]([^'"]+)['"]/g;
const EXTENSIONS = ['', '.mjs', '.js', '.cjs', '.ts', '/index.js', '/index.mjs'];
const verdicts = new Map();

function resolveLocal(from, specifier) {
    const base = path.resolve(path.dirname(from), specifier);
    return EXTENSIONS.map(ext => base + ext).find(candidate => fs.existsSync(candidate) && fs.statSync(candidate).isFile());
}

export function launchesBrowser(file, seen = new Set()) {
    const key = path.resolve(file);
    if (verdicts.has(key)) return verdicts.get(key);
    if (seen.has(key)) return false;
    seen.add(key);
    let found = false;
    const source = fs.readFileSync(key, 'utf8');
    for (const [, specifier] of source.matchAll(SPECIFIER)) {
        if (DRIVERS.test(specifier)) { found = true; break; }
        if (specifier.startsWith('.')) {
            const target = resolveLocal(key, specifier);
            if (target && launchesBrowser(target, seen)) { found = true; break; }
        }
    }
    if (seen.size === 1 || found) verdicts.set(key, found); // only cache complete answers (the root of a walk, or a positive)
    return found;
}

export function listTests(mode, dir = 'tests/frontend') {
    const candidates = fs.readdirSync(dir).filter(f => f.endsWith('.test.mjs')).sort().map(f => path.join(dir, f));
    // Node tests of the E2E harness (no browser): static checks and the keep-alive HTTP frontend.
    const harness = 'tests/e2e/isolated';
    if (dir === 'tests/frontend') candidates.push(...fs.readdirSync(harness).filter(f => f.endsWith('.test.cjs')).sort().map(f => path.join(harness, f)));
    return candidates.filter(file => (file.endsWith('.browser.test.mjs') || launchesBrowser(file)) === (mode === 'browser'));
}

const mode = process.argv[2];
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    if (!['unit', 'browser'].includes(mode)) { console.error('Uso: run-node-tests.mjs <unit|browser> [--list]'); process.exit(2); }
    const files = listTests(mode);
    if (process.argv.includes('--list')) { console.log(files.join('\n')); process.exit(0); }
    const run = spawnSync(process.execPath, ['--expose-gc', '--test', ...files], { stdio: 'inherit' });
    process.exit(run.status ?? 1);
}
