import crypto from 'node:crypto';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

// Un servidor php -S, una SQLite y un runRoot POR SLOT (un slot por worker de Playwright; el slot es el `parallelIndex`).
// El nº de slots es E2E_WORKERS (por defecto 2: cada slot son un php -S, un frontend Node, Chromium y su SQLite). El proceso principal
// los decide UNA vez (puertos libres elegidos por el SO, salvo E2E_PORT para el slot 0, y un run-general-<uuid> por slot) y los publica
// en E2E_SLOTS; Playwright reevalua este modulo en cada worker, que hereda esa lista en lugar de sortear otra y escoge SU slot.
// global-setup comprueba que cada servidor es el de su slot (identity.ts) y el worker lo vuelve a comprobar al arrancar.
function freePort(): number {
    const probe = "const s=require('node:net').createServer();s.listen(0,'127.0.0.1',()=>{console.log(s.address().port);s.close();});";
    return Number(execFileSync(process.execPath, ['-e', probe], { encoding: 'utf8' }).trim());
}
/**
 * Synchronous variant of identity.ts#verifyRunIdentity for the module load of a Playwright worker (no async hook exists before its
 * first test; kept here because runtime.ts must not import siblings without extension under Node's type stripping): a child Node
 * process fetches the proof from the server on `baseURL`; its storage path must be this slot's runRoot, else another run or
 * another worker's slot owns the port.
 */
export function verifyRunIdentitySync(baseURL: string, expectedRoot: string, ask: (url: string) => string = askServer): void {
    const norm = (p: string) => path.resolve(p).split(path.sep).join('/').toLowerCase();
    const proof = JSON.parse(ask(`${baseURL}/__general-isolation-proof`));
    if (typeof proof?.storage !== 'string' || norm(proof.storage) !== norm(expectedRoot)) {
        throw new Error(`El servidor de ${baseURL} pertenece a otra ejecución o a otro worker (storage=${proof?.storage}); se esperaba ${expectedRoot}. Relanza la batería.`);
    }
}
function askServer(url: string): string {
    const code = "fetch(process.argv[1]).then(async r=>{if(!r.ok){console.error('HTTP '+r.status);process.exit(3)}process.stdout.write(await r.text())}).catch(e=>{console.error(String(e));process.exit(4)})";
    try {
        return execFileSync(process.execPath, ['-e', code, url], { encoding: 'utf8', timeout: 20_000, stdio: ['ignore', 'pipe', 'pipe'] });
    } catch (error) {
        throw new Error(`El servidor E2E de ${url} no respondió a la comprobación de identidad: ${(error as Error).message}`);
    }
}

const prefix = path.resolve('storage/framework/testing/t43');
const checkRoot = (root: string) => {
    if (path.dirname(root).toLowerCase() !== prefix.toLowerCase() || !/^run-general-[a-f0-9-]{36}$/.test(path.basename(root))) {
        throw new Error('General E2E refuses any root outside its disposable run-general UUID directory.');
    }
    return root;
};
const newRoot = () => path.join(prefix, `run-general-${crypto.randomUUID()}`);

function parseWorkers(raw: string | undefined): number {
    if (raw === undefined || raw === '') return 2;
    const value = Number(raw);
    if (!Number.isInteger(value) || value < 1 || value > 16) throw new Error(`E2E_WORKERS must be an integer between 1 and 16 (got "${raw}").`);
    return value;
}
export const workerCount = parseWorkers(process.env.E2E_WORKERS);

type SlotSeed = { port: number; root: string };
function chooseSlots(): SlotSeed[] {
    const inherited = process.env.E2E_SLOTS;
    if (inherited) {
        const seeds = JSON.parse(inherited) as SlotSeed[];
        if (!Array.isArray(seeds) || seeds.length !== workerCount) throw new Error('E2E_SLOTS does not match E2E_WORKERS: relaunch the suite.');
        return seeds.map(seed => ({ port: Number(seed.port), root: checkRoot(path.resolve(seed.root)) }));
    }
    const requested = process.env.E2E_PORT;
    let first: number;
    if (requested !== undefined && requested !== '') {
        first = Number(requested);
        if (!Number.isInteger(first) || first < 1024 || first > 65535) throw new Error(`E2E_PORT must be an integer between 1024 and 65535 (got "${requested}").`);
    } else first = freePort();
    const used = new Set([first]);
    const seeds: SlotSeed[] = [{ port: first, root: checkRoot(path.resolve(process.env.E2E_GENERAL_RUN_ROOT ?? newRoot())) }];
    for (let i = 1; i < workerCount; i++) {
        let next = freePort();
        for (let tries = 0; used.has(next) && tries < 20; tries++) next = freePort();
        if (used.has(next)) throw new Error('Could not reserve distinct free ports for the E2E workers.');
        used.add(next);
        seeds.push({ port: next, root: newRoot() });
    }
    process.env.E2E_SLOTS = JSON.stringify(seeds);
    return seeds;
}
const seeds = chooseSlots();

// Pass only operating-system essentials and our explicit, fake application configuration to PHP.
function buildEnvironment(port: number, runRoot: string): Record<string, string> {
    const baseURL = `http://localhost:${port}`;
    const environment: Record<string, string> = {};
    for (const key of ['PATH', 'Path', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'COMSPEC', 'PATHEXT']) {
        if (process.env[key]) environment[key] = process.env[key]!;
    }
    Object.assign(environment, {
        E2E_GENERAL_RUN_ROOT: runRoot, T43_RUN_ROOT: runRoot,
        APP_ENV: 'e2e', APP_DEBUG: 'true', APP_LOCALE: 'es', APP_FALLBACK_LOCALE: 'es', APP_URL: baseURL,
        APP_KEY: `base64:${Buffer.alloc(32, 77).toString('base64')}`,
        APP_CONFIG_CACHE: path.join(runRoot, 'config.php'),
        APP_SERVICES_CACHE: path.join(runRoot, 'services.php'), APP_PACKAGES_CACHE: path.join(runRoot, 'packages.php'),
        APP_EVENTS_CACHE: path.join(runRoot, 'events.php'), APP_ROUTES_CACHE: path.join(runRoot, 'routes.php'),
        LARAVEL_STORAGE_PATH: runRoot,
        DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(runRoot, 'db.sqlite'), DB_URL: '',
        CACHE_STORE: 'file', CACHE_LIMITER_STORE: 'file', SESSION_DRIVER: 'database', SESSION_CONNECTION: 'sqlite',
        QUEUE_CONNECTION: 'database', DB_QUEUE_CONNECTION: 'sqlite',
        SESSION_SECURE_COOKIE: 'false', MAIL_MAILER: 'array', MEDIA_DISK: 'local',
        SOCIAL_BACKFILL_ON_CONNECT: 'false', TRUSTED_PROXIES: '',
        GOOGLE_CLIENT_ID: 'e2e-fake-google', GOOGLE_CLIENT_SECRET: 'e2e-fake-secret',
        META_APP_ID: 'e2e-fake-meta', META_APP_SECRET: 'e2e-fake-secret',
        TIKTOK_CLIENT_KEY: 'e2e-fake-tiktok', TIKTOK_CLIENT_SECRET: 'e2e-fake-secret',
        GOOGLE_LOGIN_CLIENT_ID: 'e2e-fake-google-login', GOOGLE_LOGIN_CLIENT_SECRET: 'e2e-fake-secret',
        META_LOGIN_APP_ID: 'e2e-fake-meta-login', META_LOGIN_APP_SECRET: 'e2e-fake-secret',
        THREADS_APP_ID: 'e2e-fake-threads', THREADS_APP_SECRET: 'e2e-fake-secret',
        LINKEDIN_CLIENT_ID: 'e2e-fake-linkedin', LINKEDIN_CLIENT_SECRET: 'e2e-fake-secret',
        PINTEREST_APP_ID: 'e2e-fake-pinterest', PINTEREST_APP_SECRET: 'e2e-fake-secret',
        // The GIF negative case requires no key; its positive cases enable props and intercept every request.
        GIPHY_API_KEY: '', AI_ASSISTANT_ENABLED: 'true', ANTHROPIC_API_KEY: 'e2e-fake-anthropic',
        MCP_OAUTH_REGISTER_GLOBAL_PER_HOUR: '100000',
        WEBAUTHN_RP_ID: 'localhost', WEBAUTHN_ORIGINS: baseURL,
        VIEW_COMPILED_PATH: path.join(runRoot, 'views'), PSYSH_CONFIG: path.join(runRoot, 'psysh.php'),
    });
    return environment;
}

export type Slot = {
    index: number; port: number; baseURL: string; runRoot: string; environment: Record<string, string>;
    authFile: string; usersFile: string; startFile: string; fakeSocialFile: string;
};
export function slotRuntime(index: number): Slot {
    const { port, root } = seeds[index];
    return {
        index, port, baseURL: `http://localhost:${port}`, runRoot: root, environment: buildEnvironment(port, root),
        authFile: path.join(root, 'auth/readonly.json'), usersFile: path.join(root, 'auth/users.json'),
        startFile: path.join(root, 'auth/start.txt'), fakeSocialFile: path.join(root, 'framework/cache/e2e-social.json'),
    };
}
export const slots: Slot[] = seeds.map((_, index) => slotRuntime(index));

// In the main process (config, global setup/teardown) the "current" slot is 0; a worker is slot `parallelIndex`.
const parallelIndex = process.env.TEST_PARALLEL_INDEX;
export const isWorker = parallelIndex !== undefined && parallelIndex !== '';
export const slotIndex = isWorker ? Number(parallelIndex) : 0;
if (!Number.isInteger(slotIndex) || slotIndex < 0 || slotIndex >= slots.length) {
    throw new Error(`Worker ${parallelIndex} has no E2E server: raise E2E_WORKERS (${workerCount}) to at least the --workers you pass.`);
}
const current = slots[slotIndex];
export const { port, baseURL, runRoot, environment, authFile, usersFile, startFile, fakeSocialFile } = current;
if (process.env.E2E_NO_SERVER || (process.env.E2E_BASE_URL && process.env.E2E_BASE_URL !== baseURL)) {
    throw new Error(`General E2E requires its managed disposable server at ${baseURL}.`);
}
// Playwright's server and worker processes inherit the same run identifier.
Object.assign(process.env, environment);
process.env.E2E_PORT = String(port);
// A worker proves, before running any test, that the server on ITS port belongs to ITS slot (and not to another run).
if (isWorker) verifyRunIdentitySync(baseURL, runRoot);
