import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import * as purgeModule from '../../scripts/test/purge-testing.mjs';

const { purge } = purgeModule;
const uuid = n => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const DAY = 86_400_000;

function tree() {
    const base = fs.mkdtempSync(path.join(os.tmpdir(), 'scan-'));
    const root = path.join(base, 'testing'); const docs = path.join(base, 'docs');
    fs.mkdirSync(path.join(root, 't43'), { recursive: true }); fs.mkdirSync(docs, { recursive: true });
    const now = Date.now();
    const run = (name, ageDays = 30) => { const p = path.join(root, 't43', name); fs.mkdirSync(p); const t = new Date(now - ageDays * DAY); fs.utimesSync(p, t, t); return name; };
    return { base, root, docs, now, run };
}

test('una cita partida justo en el borde entre dos trozos se detecta (todos los desplazamientos)', () => {
    const { base, docs } = tree();
    try {
        const name = `run-general-${uuid(7)}`; const roadmap = 'run-1794467651506-26520';
        const chunk = 64;
        for (let shift = chunk - name.length - 1; shift <= chunk + 1; shift++) {
            const file = path.join(docs, `f${shift}.txt`);
            fs.writeFileSync(file, `${'x'.repeat(Math.max(0, shift))}${name} ${'y'.repeat(chunk * 2)}${' '.repeat(chunk - 14)}${roadmap} fin`);
            const found = purgeModule.citedRunNames(docs, { chunkBytes: chunk });
            assert.ok(found.has(name), `uuid con desplazamiento ${shift}`);
            assert.ok(found.has(roadmap), `roadmap con desplazamiento ${shift}`);
            fs.rmSync(file);
        }
        // un pid truncado por el borde no debe registrarse como nombre distinto
        fs.writeFileSync(path.join(docs, 'pid.txt'), `${'z'.repeat(chunk - 20)}run-1794467651506-26520123`);
        const found = purgeModule.citedRunNames(docs, { chunkBytes: chunk });
        assert.ok(found.has('run-1794467651506-26520123') && !found.has('run-1794467651506-26520'));
    } finally { fs.rmSync(base, { recursive: true, force: true }); }
});

test('un fichero grande de docs no se carga entero: el pico de RSS no crece con el tamaño (192 MB de texto, tope 200 MB; cargarlo entero superaría 400 MB)', () => {
    const { base, root, docs, run } = tree();
    try {
        const cited = run(`run-general-${uuid(1)}`); const free = run(`run-general-${uuid(2)}`); run(`run-general-${uuid(3)}`, 31);
        const fd = fs.openSync(path.join(docs, 'enorme.log'), 'w');
        const line = Buffer.from(`${'linea de log sin interes '.repeat(40)}\n`);
        for (let i = 0; i < 192 * 1024; i++) fs.writeSync(fd, line, 0, Math.min(line.length, 1024)); // ~192 MB
        fs.writeSync(fd, `cita final en ${cited}\n`); fs.closeSync(fd);
        const code = `import(${JSON.stringify(pathToFileURL(path.resolve('scripts/test/purge-testing.mjs')).href)}).then(m=>{const r=m.purge({root:${JSON.stringify(root)},keep:1,minAgeDays:1,dryRun:true,docsDir:${JSON.stringify(docs)}});console.log(JSON.stringify({removed:r.removed.map(p=>p.split(/[\\/]/).pop()),maxRssMb:process.resourceUsage().maxRSS/1024}))})`;
        const run1 = spawnSync(process.execPath, ['-e', code], { encoding: 'utf8' });
        assert.equal(run1.status, 0, run1.stderr.slice(0, 400));
        const { removed, maxRssMb } = JSON.parse(run1.stdout.trim());
        assert.ok(maxRssMb < 200, `pico de RSS ${maxRssMb.toFixed(0)} MB: el fichero se está cargando entero`);
        assert.ok(!removed.includes(cited), 'la entrada citada al final del fichero grande se protege');
        assert.ok(removed.includes(free) || removed.length >= 1);
    } finally { fs.rmSync(base, { recursive: true, force: true }); }
});

test('los logs UTF-16 (LE con BOM, LE sin BOM, BE con BOM) cuentan como texto y sus citas protegen', () => {
    const { base, root, docs, now, run } = tree();
    try {
        const names = [1, 2, 3, 4, 5].map(i => run(`run-general-${uuid(i)}`, 30 + i));
        fs.writeFileSync(path.join(docs, 'ps-bom.log'), Buffer.concat([Buffer.from([0xff, 0xfe]), Buffer.from(`ruta ${names[0]}\r\n`, 'utf16le')]));
        fs.writeFileSync(path.join(docs, 'ps-nobom.log'), Buffer.from(`ruta ${names[1]}\r\n`.repeat(20), 'utf16le'));
        const be = Buffer.from(`ruta ${names[2]}\r\n`, 'utf16le').swap16();
        fs.writeFileSync(path.join(docs, 'be.log'), Buffer.concat([Buffer.from([0xfe, 0xff]), be]));
        const result = purge({ root, keep: 1, minAgeDays: 1, dryRun: true, now, docsDir: docs });
        const removed = result.removed.map(p => path.basename(p));
        assert.deepEqual(removed, [names[4]], 'las 3 citadas en UTF-16 se protegen; de las no citadas se conserva la más reciente (keep=1)');
    } finally { fs.rmSync(base, { recursive: true, force: true }); }
});

test('un binario real (con NUL sueltos) sigue sin contar como texto', () => {
    const { base, docs } = tree();
    try {
        const name = `run-general-${uuid(9)}`;
        fs.writeFileSync(path.join(docs, 'datos.bin'), Buffer.concat([Buffer.from([0x89, 0x50, 0, 0x47, 0, 0, 1]), Buffer.from(name)]));
        assert.ok(!purgeModule.citedRunNames(docs).has(name));
    } finally { fs.rmSync(base, { recursive: true, force: true }); }
});
