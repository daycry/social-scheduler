import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
import crypto from 'node:crypto';
import path from 'node:path';
import setup from '../global-setup';
import teardown from '../global-teardown';
import { artisan, AUTH_FILE, READONLY } from '../helpers';
import { environment, runRoot } from './runtime';

test('setup and teardown preserve a protected fake DEMO and foreign prefixed user', async ({ browser }, info) => {
    const sentinel = 'protected-fake-demo@example.test';
    process.env.DEMO_USER_EMAIL = sentinel;
    process.env.DEMO_USER_PASSWORD = 'E2e-Protected-Fake-Aa1!';
    artisan(`$u = App\\Models\\User::factory()->create(['email' => '${sentinel}', 'password' => Illuminate\\Support\\Facades\\Hash::make('E2e-Protected-Fake-Aa1!')]); App\\Models\\Brand::factory()->for($u)->create(['name' => 'e2e-protected-sentinel']); App\\Models\\User::factory()->create(['email' => 'e2e-sec-foreign@example.test']);`);
    await setup(info.config);
    expect(JSON.parse(artisan(`echo json_encode(App\\Models\\User::pluck('email')->all());`))).toHaveLength(6);
    const context = await browser.newContext({ baseURL: 'http://localhost:8080', storageState: AUTH_FILE });
    const page = await context.newPage();
    await page.goto('/profile');
    await expect(page.getByLabel('Correo electrónico', { exact: true }).first()).toHaveValue(READONLY.email);
    await context.close();
    await teardown();
    expect(JSON.parse(artisan(`echo json_encode(App\\Models\\User::orderBy('email')->pluck('email')->all());`))).toEqual(['e2e-sec-foreign@example.test', sentinel]);
    expect(artisan(`echo App\\Models\\Brand::withoutUserScope()->where('name', 'e2e-protected-sentinel')->count();`)).toBe('1');
});

test('effective database, cache, limiter, locks and storage stay inside this run', async ({ request }) => {
    const proof = JSON.parse(artisan(String.raw`
        $cache = app('cache')->store('file')->getStore();
        $limiter = app(Illuminate\Cache\RateLimiter::class);
        $limiterCache = (new ReflectionProperty($limiter, 'cache'))->getValue($limiter);
        $lock = app('cache')->store('file')->lock('general-isolation-proof', 10);
        $lockStore = (new ReflectionProperty($lock, 'store'))->getValue($lock);
        $blocked = false;
        try { Illuminate\Support\Facades\Http::get('https://example.test/blocked'); }
        catch (Illuminate\Http\Client\StrayRequestException $e) { $blocked = true; }
        echo json_encode(['database' => config('database.connections.sqlite.database'), 'environment' => app()->environmentPath(),
            'storage' => storage_path(), 'cache' => $cache->getDirectory(), 'limiter' => $limiterCache->getStore()->getDirectory(), 'locks' => $lockStore->getDirectory(),
            'config' => app()->getCachedConfigPath(), 'packages' => app()->getCachedPackagesPath(), 'services' => app()->getCachedServicesPath(),
            'views' => config('view.compiled'), 'media' => Illuminate\Support\Facades\Storage::disk('local')->path(''), 'session' => config('session.files'),
            'psysh' => (new Psy\Configuration)->getHistoryFile(), 'blocked' => $blocked, 'queue' => config('queue.connections.database.connection'), 'mail' => config('mail.default')]);
    `));
    const normalize = (value: string) => value.replace(/\\/g, '/').toLowerCase().replace(/\/$/, '');
    for (const key of ['database', 'environment', 'storage', 'cache', 'limiter', 'locks', 'config', 'packages', 'services', 'views', 'media', 'session', 'psysh']) {
        const actual = normalize(proof[key]);
        const root = normalize(runRoot);
        expect(actual === root || actual.startsWith(`${root}/`), `${key}: ${actual}`).toBe(true);
    }
    expect(proof.blocked).toBe(true);
    expect(proof.queue).toBe('sqlite');
    expect(proof.mail).toBe('array');
    const httpProof = await (await request.get('/__general-isolation-proof')).json();
    for (const [key, value] of Object.entries(httpProof)) {
        expect(normalize(value as string), `HTTP/CLI ${key}`).toBe(normalize(proof[key]));
    }
    fs.writeFileSync(`${runRoot}/isolation-proof.json`, JSON.stringify(proof, null, 2));
    fs.writeFileSync(`${runRoot}/http-isolation-proof.json`, JSON.stringify(httpProof, null, 2));
});

test('bootstrap rejects foreign settings and separates cache, limiter and locks between runs', () => {
    for (const override of [
        { DB_DATABASE: `${runRoot}/foreign.sqlite` },
        { DB_URL: 'mysql://fake:fake@invalid.example/fake' },
        { APP_URL: 'https://invalid.example' },
        { APP_CONFIG_CACHE: `${runRoot}/foreign-config.php` },
        { PSYSH_CONFIG: `${runRoot}/foreign-psysh.php` },
        { E2E_GENERAL_RUN_ROOT: `${runRoot}-foreign` },
    ]) {
        const result = spawnSync('php', ['tests/e2e/isolated/cli.php', 'init'], { env: { ...environment, ...override }, encoding: 'utf8' });
        expect(result.error).toBeUndefined();
        expect(result.status).not.toBe(0);
        expect(`${result.stdout}\n${result.stderr}`).toMatch(/disposable local|disposable general|outside its disposable/);
    }
    expect(fs.existsSync(`${runRoot}/foreign.sqlite`)).toBe(false);
    const key = `general-${crypto.randomUUID()}`;
    artisan(`app('cache')->put('${key}:value', 'first-run', 120); Illuminate\\Support\\Facades\\RateLimiter::hit('${key}:rate', 120); app('cache')->lock('${key}', 120)->get();`);
    const inspect = `echo json_encode(['value' => app('cache')->get('${key}:value'), 'attempts' => Illuminate\\Support\\Facades\\RateLimiter::attempts('${key}:rate'), 'acquired' => app('cache')->lock('${key}', 120)->get()]);`;
    const first = JSON.parse(artisan(inspect));
    expect(first).toEqual({ value: 'first-run', attempts: 1, acquired: false });
    const secondRoot = path.join(path.dirname(runRoot), `run-general-${crypto.randomUUID()}`);
    const secondEnvironment = Object.fromEntries(Object.entries(environment).map(([key, value]) => [key, value.replaceAll(runRoot, secondRoot)]));
    const init = spawnSync('php', ['tests/e2e/isolated/cli.php', 'init'], { env: secondEnvironment, encoding: 'utf8' });
    expect(init.status, `${init.stdout}\n${init.stderr}`).toBe(0);
    const result = spawnSync('php', ['tests/e2e/isolated/cli.php', 'tinker', inspect], { env: secondEnvironment, encoding: 'utf8' });
    expect(result.status, `${result.stdout}\n${result.stderr}`).toBe(0);
    const second = JSON.parse(result.stdout.trim());
    expect(second).toEqual({ value: null, attempts: 0, acquired: true });
    fs.writeFileSync(`${runRoot}/two-run-isolation.json`, JSON.stringify({ firstRoot: runRoot, secondRoot, first, second }, null, 2));
});
