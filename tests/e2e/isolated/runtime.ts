import crypto from 'node:crypto';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

// The port is E2E_PORT or, by default, a free port chosen once by the OS (never a fixed one: two simultaneous runs would race
// for 8080). Playwright re-evaluates this module in every worker, so the choice is written back to process.env and inherited
// instead of being drawn again. global-setup then verifies the server answering there belongs to THIS run (identity.ts).
function freePort(): number {
    const probe = "const s=require('node:net').createServer();s.listen(0,'127.0.0.1',()=>{console.log(s.address().port);s.close();});";
    return Number(execFileSync(process.execPath, ['-e', probe], { encoding: 'utf8' }).trim());
}
export const port = (() => {
    const requested = process.env.E2E_PORT;
    if (requested !== undefined && requested !== '') {
        const value = Number(requested);
        if (!Number.isInteger(value) || value < 1024 || value > 65535) throw new Error(`E2E_PORT must be an integer between 1024 and 65535 (got "${requested}").`);
        return value;
    }
    return freePort();
})();
process.env.E2E_PORT = String(port);
export const baseURL = `http://localhost:${port}`;
if (process.env.E2E_NO_SERVER || (process.env.E2E_BASE_URL && process.env.E2E_BASE_URL !== baseURL)) {
    throw new Error(`General E2E requires its managed disposable server at ${baseURL}.`);
}

const prefix = path.resolve('storage/framework/testing/t43');
export const runRoot = path.resolve(process.env.E2E_GENERAL_RUN_ROOT ?? path.join(prefix, `run-general-${crypto.randomUUID()}`));
if (path.dirname(runRoot).toLowerCase() !== prefix.toLowerCase() || !/^run-general-[a-f0-9-]{36}$/.test(path.basename(runRoot))) {
    throw new Error('General E2E refuses any root outside its disposable run-general UUID directory.');
}

// Pass only operating-system essentials and our explicit, fake application configuration to PHP.
export const environment: Record<string, string> = {};
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
// Playwright's server and worker processes inherit the same run identifier.
Object.assign(process.env, environment);

export const authFile = path.join(runRoot, 'auth/readonly.json');
export const usersFile = path.join(runRoot, 'auth/users.json');
export const startFile = path.join(runRoot, 'auth/start.txt');
export const fakeSocialFile = path.join(runRoot, 'framework/cache/e2e-social.json');
