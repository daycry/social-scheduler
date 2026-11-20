import { createHash, randomUUID } from 'node:crypto';
import { existsSync, linkSync, lstatSync, readFileSync, realpathSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

const archiveName = 'tasks.archive.md';
const digest = bytes => createHash('sha256').update(bytes).digest('hex');

function sections(lines) {
    const headers = [];
    let fence;
    const visible = lines.map((line, index) => {
        const delimiter = line.match(/^ {0,3}(`{3,}|~{3,})/)?.[1];
        if (delimiter && !fence) fence = delimiter;
        else if (delimiter && delimiter[0] === fence?.[0] && delimiter.length >= fence.length && line.trim() === delimiter) fence = undefined;
        if (delimiter || fence) return '';
        if (/^#{2,3} +\S/.test(line)) headers.push({ index, heading: line });
        return line;
    });
    return headers.map(({ index, heading }, position) => ({
        index,
        heading,
        body: visible.slice(index + 1, headers[position + 1]?.index),
    }));
}

function compactLedger(text, sha256) {
    const lines = text.split(/\r?\n/);
    const frontmatterEnd = lines.indexOf('---', 1);
    if (lines[0] !== '---' || frontmatterEnd < 1
        || !lines.slice(1, frontmatterEnd).some(line => /^estado:\s*completado\s*$/.test(line))) {
        throw new Error('Solo se archivan ledgers completados.');
    }

    const blocks = sections(lines).filter(block => /^## Fase\b|^### T-\d+\b/.test(block.heading));
    const tasks = blocks.filter(block => block.heading.startsWith('### T-'));
    if (!tasks.length || tasks.some(task => !task.body.some(line => /^- \*\*Estado\*\*:\s*(?:completado|cancelado)\s*$/.test(line)))) {
        throw new Error('El ledger debe tener tareas cerradas.');
    }

    const historicalLink = 'El [historial completo](tasks.archive.md) conserva la evidencia y los detalles de cierre.';
    const intro = lines.slice(frontmatterEnd + 1, blocks[0].index).join('\n').trim();
    const summary = blocks.map(({ heading, body }) => {
        if (!heading.startsWith('### T-')) return heading;
        const details = body.flatMap(line => {
            if (/^\*\*(?:Criterios de aceptación|Subtareas)\*\*\s*$/.test(line)) return ['', line, ''];
            return /^- \*\*[^*]+\*\*:|^\s*- \[[xX ]\] /.test(line) ? [line] : [];
        });
        const anchor = heading.slice(4).toLowerCase().replace(/[^\p{L}\p{N}\s_-]/gu, '').replace(/\s/g, '-');
        return [heading, details.join('\n'), `[Detalle histórico](${archiveName}#${anchor})`].join('\n\n');
    });
    const metadata = [
        ...lines.slice(0, frontmatterEnd),
        `archivo: ${archiveName}`,
        `archivo_sha256: ${sha256}`,
        '---',
    ].join('\n');
    return { text: [metadata, intro, historicalLink, ...summary].join('\n\n') + '\n', tasks: tasks.length };
}

function createArchive(path, bytes) {
    const temp = `${path}.${randomUUID()}.tmp`;
    try {
        writeFileSync(temp, bytes, { flag: 'wx' });
        linkSync(temp, path);
    } finally {
        rmSync(temp, { force: true });
    }
}

function archiveLedger(slug, apply) {
    const roadmapPath = join('docs', 'roadmap');
    if (!existsSync(roadmapPath)) throw new Error('Ejecuta el comando desde la raíz del repositorio.');
    const roadmap = realpathSync(roadmapPath);
    const initiative = realpathSync(join(roadmap, slug));
    if (dirname(initiative) !== roadmap) throw new Error('La iniciativa debe estar dentro del roadmap.');
    const active = join(initiative, 'tasks.md');
    const archived = join(initiative, archiveName);
    if (!lstatSync(active).isFile()) throw new Error('El ledger activo debe ser un fichero normal.');
    if (existsSync(archived) && !lstatSync(archived).isFile()) throw new Error('El archivo debe ser un fichero normal.');

    const original = readFileSync(active);
    const text = original.toString('utf8');
    const metadata = text.match(/^---\r?\n([\s\S]*?)\r?\n---/);
    const previousDigest = metadata?.[1].match(/^archivo_sha256: ([a-f0-9]{64})$/m)?.[1];
    if (previousDigest) {
        if (!existsSync(archived) || digest(readFileSync(archived)) !== previousDigest) {
            throw new Error('El archivo existente no coincide con la huella del ledger.');
        }
        return { status: 'already-archived', sha256: previousDigest };
    }

    const archiveExists = existsSync(archived);
    if (archiveExists && !readFileSync(archived).equals(original)) {
        throw new Error('Ya existe un archivo con contenido distinto.');
    }

    const sha256 = digest(original);
    const summary = compactLedger(text, sha256);
    if (apply) {
        if (!archiveExists) createArchive(archived, original);
        const temp = `${active}.${randomUUID()}.tmp`;
        try {
            writeFileSync(temp, summary.text, { flag: 'wx' });
            if (!readFileSync(active).equals(original)) throw new Error('El ledger cambió durante el archivado.');
            renameSync(temp, active);
        } finally {
            rmSync(temp, { force: true });
        }
    }
    return { status: apply ? 'archived' : 'preview', tasks: summary.tasks, sha256, bytes: original.length };
}

try {
    const [slug, option, ...rest] = process.argv.slice(2);
    if (!/^\d{4}-\d{2}-\d{2}-[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug ?? '')
        || (option !== undefined && option !== '--apply') || rest.length) {
        throw new Error('Uso: node scripts/roadmap/archive-ledger.mjs <iniciativa> [--apply]');
    }
    console.log(JSON.stringify(archiveLedger(slug, option === '--apply')));
} catch (error) {
    console.error(error.message);
    process.exitCode = 1;
}
