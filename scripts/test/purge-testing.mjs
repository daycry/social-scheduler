#!/usr/bin/env node
// Purga las ejecuciones antiguas de storage/framework/testing (E-12: llegó a 7,6 GB).
// ALCANCE (lista explícita, nada más se toca): solo los hijos directos de testing/t43/ con estos nombres
//   - run-general-<uuid>   (runRoot de la batería general, tests/e2e/isolated/runtime.ts)
//   - run-<epoch ms>-<pid> (runRoot del runner de roadmap/T-43, playwright.roadmap.config.ts)
// Cualquier otro directorio o fichero (evidencia de iniciativas: t12-…, run-qa-…, *-peer-*, etc.) es intocable.
// Además se salta cualquier entrada cuyo nombre aparezca en algún fichero de texto bajo docs/ (md, json, txt, log…; evidencia citada). Sin docs legible, aborta sin borrar.
// Entre los candidatos se conserva una si es de las N más recientes O de menos de X días.
//
// Uso: node scripts/test/purge-testing.mjs [--dry-run] [--keep N] [--min-age-days X] [--root <dir>] [--docs <dir>]
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const RUN_PATTERNS = [/^run-general-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/, /^run-\d{10,}-\d+$/];
export const DEFAULT_KEEP = 5;
export const DEFAULT_MIN_AGE_DAYS = 2;
const DAY_MS = 86_400_000;

function listCandidates(root) {
    const out = [];
    const dir = path.join(root, 't43');
    let names = [];
    try { names = fs.readdirSync(dir); } catch { return out; }
    for (const name of names) {
        if (!RUN_PATTERNS.some(re => re.test(name))) continue;
        const full = path.join(dir, name);
        let stat;
        try { stat = fs.lstatSync(full); } catch { continue; }
        if (stat.isSymbolicLink() || !stat.isDirectory()) continue;
        out.push({ full, name, mtimeMs: stat.mtimeMs });
    }
    return out;
}

const BINARY_EXT = new Set(['.png', '.jpg', '.jpeg', '.gif', '.webp', '.avif', '.ico', '.bmp', '.mp4', '.webm', '.mov', '.mp3', '.wav', '.ogg', '.m4a', '.pdf', '.zip', '.gz', '.woff', '.woff2', '.ttf', '.otf', '.sqlite', '.db']);
export const DOCS_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', 'docs');

/**
 * Texto de TODOS los ficheros de texto bajo docs/ (md, json, txt, log, …; incluido docs/knowledge/journal): fuente de la
 * «evidencia citada». Se saltan los binarios por extensión o por un NUL en los primeros bytes. Fail-closed: si docs/ no
 * existe o algo no se puede leer, lanza (no se purga sin poder comprobar las citas).
 */
function readDocs(dir) {
    if (!fs.existsSync(dir) || !fs.statSync(dir).isDirectory()) throw new Error(`purge: no se encuentra el directorio docs (${dir}); se aborta sin borrar nada`);
    const chunks = [];
    const walk = d => {
        for (const e of fs.readdirSync(d, { withFileTypes: true })) {
            const full = path.join(d, e.name);
            if (e.isDirectory()) walk(full);
            else if (e.isFile() && !BINARY_EXT.has(path.extname(e.name).toLowerCase())) {
                const buffer = fs.readFileSync(full);
                if (!buffer.subarray(0, 8000).includes(0)) chunks.push(buffer.toString('utf8'));
            }
        }
    };
    try { walk(dir); } catch (error) { throw new Error(`purge: no se pudo leer docs (${error.message}); se aborta sin borrar nada`); }
    return chunks.join('\n');
}

/** Valida keep / minAgeDays: un valor inválido, vacío o por debajo del mínimo (1) pasa al por defecto con un aviso. */
export function resolveOptions({ keep, minAgeDays } = {}) {
    const warnings = [];
    const pick = (raw, name, fallback, integer) => {
        if (raw === undefined) return fallback;
        const text = typeof raw === 'string' ? raw.trim() : raw;
        const value = typeof text === 'string' ? (text === '' ? NaN : Number(text)) : text;
        if (typeof value !== 'number' || !Number.isFinite(value) || value < 1 || (integer && !Number.isInteger(value))) {
            warnings.push(`purge: ${name}=${JSON.stringify(raw)} no es válido (mínimo 1${integer ? ', entero' : ''}); se usa ${fallback}`);
            return fallback;
        }
        return value;
    };
    return { keep: pick(keep, 'keep', DEFAULT_KEEP, true), minAgeDays: pick(minAgeDays, 'minAgeDays', DEFAULT_MIN_AGE_DAYS, false), warnings };
}

/** Calcula y (salvo dryRun) aplica la purga. Devuelve { kept, removed, failed, warnings }. */
export function purge({ root, keep, minAgeDays, dryRun = false, now = Date.now(), protectedPaths = [], docsDir = DOCS_DIR } = {}) {
    if (!root) throw new Error('purge: root es obligatorio');
    const base = path.resolve(root);
    if (!fs.existsSync(base)) return { kept: [], removed: [], failed: [], warnings: [] };
    const options = resolveOptions({ keep, minAgeDays });
    ({ keep, minAgeDays } = options);
    const guard = protectedPaths.filter(Boolean).map(p => path.resolve(p).toLowerCase());
    const docs = readDocs(path.resolve(docsDir));
    const entries = listCandidates(base).filter(e => !docs.includes(e.name)).sort((a, b) => b.mtimeMs - a.mtimeMs);
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
    return { kept, removed, failed, warnings: options.warnings };
}

function parse(argv) {
    const opts = { dryRun: false, keep: undefined, minAgeDays: undefined, root: path.resolve('storage/framework/testing') };
    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i];
        const value = () => { const v = argv[++i]; if (v === undefined) throw new Error(`Falta el valor de ${arg}`); return v; };
        if (arg === '--dry-run') opts.dryRun = true;
        else if (arg === '--keep') opts.keep = value();
        else if (arg === '--min-age-days') opts.minAgeDays = value();
        else if (arg === '--root') opts.root = path.resolve(value());
        else if (arg === '--docs') opts.docsDir = path.resolve(value());
        else throw new Error(`Opción desconocida: ${arg}`);
    }
    return opts;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        const opts = parse(process.argv.slice(2));
        const result = purge({ ...opts, protectedPaths: [process.env.E2E_GENERAL_RUN_ROOT, process.env.T43_RUN_ROOT] });
        console.log(`${opts.dryRun ? '[dry-run] borraría' : 'borrado'}: ${result.removed.length} · conservado: ${result.kept.length} · fallos: ${result.failed.length}`);
        for (const w of result.warnings) console.warn(w);
        for (const p of result.removed) console.log(`  ${opts.dryRun ? '-' : 'x'} ${p}`);
        for (const f of result.failed) console.error(`  ! ${f.path}: ${f.error}`);
        process.exitCode = result.failed.length ? 1 : 0;
    } catch (error) { console.error(error.message); process.exitCode = 2; }
}
