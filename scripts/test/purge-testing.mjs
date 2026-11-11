#!/usr/bin/env node
// Purga las ejecuciones antiguas de storage/framework/testing (E-12: llegó a 7,6 GB).
// Se conserva una entrada si es de las N más recientes O si tiene menos de X días; el resto se borra.
// Cubre los hijos directos de testing/ y los de testing/t43/ (run-*). Nunca sigue enlaces simbólicos ni
// toca ficheros ocultos ni la ejecución en curso (E2E_GENERAL_RUN_ROOT / T43_RUN_ROOT).
//
// Uso: node scripts/test/purge-testing.mjs [--dry-run] [--keep N] [--min-age-days X] [--root <dir>]
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const DEFAULT_KEEP = 5;
export const DEFAULT_MIN_AGE_DAYS = 2;
const DAY_MS = 86_400_000;

function listCandidates(root) {
    const out = [];
    const scan = (dir, skipNames = []) => {
        let names = [];
        try { names = fs.readdirSync(dir); } catch { return; }
        for (const name of names) {
            if (name.startsWith('.') || skipNames.includes(name)) continue;
            const full = path.join(dir, name);
            let stat;
            try { stat = fs.lstatSync(full); } catch { continue; }
            out.push({ full, name, mtimeMs: stat.mtimeMs, symlink: stat.isSymbolicLink() });
        }
    };
    scan(root, ['t43']);
    scan(path.join(root, 't43'));
    return out;
}

/** Calcula y (salvo dryRun) aplica la purga. Devuelve { kept, removed, failed, bytes? }. */
export function purge({ root, keep = DEFAULT_KEEP, minAgeDays = DEFAULT_MIN_AGE_DAYS, dryRun = false, now = Date.now(), protectedPaths = [] } = {}) {
    if (!root) throw new Error('purge: root es obligatorio');
    const base = path.resolve(root);
    if (!fs.existsSync(base)) return { kept: [], removed: [], failed: [] };
    const guard = protectedPaths.filter(Boolean).map(p => path.resolve(p).toLowerCase());
    const entries = listCandidates(base).sort((a, b) => b.mtimeMs - a.mtimeMs);
    const kept = []; const removed = []; const failed = [];
    entries.forEach((entry, index) => {
        const young = now - entry.mtimeMs < minAgeDays * DAY_MS;
        const lower = entry.full.toLowerCase();
        const current = guard.some(p => p === lower || p.startsWith(lower + path.sep));
        if (index < keep || young || current) { kept.push(entry.full); return; }
        if (!dryRun) {
            try { fs.rmSync(entry.full, { recursive: true, force: true, maxRetries: 3, retryDelay: 100 }); } catch (error) { failed.push({ path: entry.full, error: String(error.message ?? error) }); return; }
        }
        removed.push(entry.full);
    });
    return { kept, removed, failed };
}

function parse(argv) {
    const opts = { dryRun: false, keep: DEFAULT_KEEP, minAgeDays: DEFAULT_MIN_AGE_DAYS, root: path.resolve('storage/framework/testing') };
    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i];
        const value = () => { const v = argv[++i]; if (v === undefined) throw new Error(`Falta el valor de ${arg}`); return v; };
        if (arg === '--dry-run') opts.dryRun = true;
        else if (arg === '--keep') opts.keep = Number(value());
        else if (arg === '--min-age-days') opts.minAgeDays = Number(value());
        else if (arg === '--root') opts.root = path.resolve(value());
        else throw new Error(`Opción desconocida: ${arg}`);
    }
    if (!Number.isInteger(opts.keep) || opts.keep < 0 || !Number.isFinite(opts.minAgeDays) || opts.minAgeDays < 0) throw new Error('--keep y --min-age-days deben ser números no negativos');
    return opts;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        const opts = parse(process.argv.slice(2));
        const result = purge({ ...opts, protectedPaths: [process.env.E2E_GENERAL_RUN_ROOT, process.env.T43_RUN_ROOT] });
        console.log(`${opts.dryRun ? '[dry-run] borraría' : 'borrado'}: ${result.removed.length} · conservado: ${result.kept.length} · fallos: ${result.failed.length} (keep=${opts.keep}, min-age-days=${opts.minAgeDays})`);
        for (const p of result.removed) console.log(`  ${opts.dryRun ? '-' : 'x'} ${p}`);
        for (const f of result.failed) console.error(`  ! ${f.path}: ${f.error}`);
        process.exitCode = result.failed.length ? 1 : 0;
    } catch (error) { console.error(error.message); process.exitCode = 2; }
}
