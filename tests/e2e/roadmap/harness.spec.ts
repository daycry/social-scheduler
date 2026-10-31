import { test, expect } from '@playwright/test';
import { execFileSync, spawnSync } from 'node:child_process';
import path from 'node:path';
import { fixture } from './support';

test('T43 harness rejects production environment before touching a database', () => {
    const result = spawnSync('php', ['tests/e2e/roadmap/cli.php', 'state'], {
        encoding: 'utf8', env: { ...process.env, APP_ENV: 'production' },
    });
    expect(result.status).not.toBe(0);
    expect(result.stdout + result.stderr).toContain('T43 refuses any environment');
});

test('T43 harness rejects a database outside its disposable run', () => {
    const result = spawnSync('php', ['tests/e2e/roadmap/cli.php', 'state'], {
        encoding: 'utf8', env: { ...process.env, DB_DATABASE: path.resolve('database/database.sqlite') },
    });
    expect(result.status).not.toBe(0);
    expect(result.stdout + result.stderr).toContain('T43 refuses any environment');
});

test('T43 harness creates only dedicated disposable users and begins without real accounts', ({}, info) => {
    const users = fixture('reset', info.project.name);
    expect(users.user.email).toBe(`e2e-sec-roadmap-${info.project.name}@example.test`);
    expect(users.other.email).toBe(`e2e-sec-roadmap-other-${info.project.name}@example.test`);
    expect(fixture('state', info.project.name).accounts).toEqual([]);
});

test('T43 cache stores and unique locks remain independent across two disposable runs', ({}, info) => {
    const root = process.env.T43_RUN_ROOT!;
    const peerRoot = `${root}-peer-${info.project.name}`;
    const peerEnv = {
        ...process.env,
        T43_RUN_ROOT: peerRoot,
        DB_DATABASE: path.join(peerRoot, 'db.sqlite'),
        APP_CONFIG_CACHE: path.join(peerRoot, 'config.php'),
        VIEW_COMPILED_PATH: path.join(peerRoot, 'views'),
    };
    execFileSync('php', ['tests/e2e/roadmap/cli.php', 'init'], { env: peerEnv, encoding: 'utf8', timeout: 90_000 });
    const peer = (args: Record<string, unknown>) => JSON.parse(execFileSync('php', [
        'tests/e2e/roadmap/cli.php', 'cache-isolation',
        Buffer.from(JSON.stringify({ project: info.project.name, ...args })).toString('base64'),
    ], { env: peerEnv, encoding: 'utf8', timeout: 90_000 }));

    const first = fixture('cache-isolation', info.project.name);
    const second = peer({});
    const normalize = (value: string) => value.replaceAll('\\', '/');
    expect(first.paths).toEqual({ cache: `${normalize(root)}/cache`, limiter: `${normalize(root)}/cache`, lock: `${normalize(root)}/cache` });
    expect(second.paths).toEqual({ cache: `${normalize(peerRoot)}/cache`, limiter: `${normalize(peerRoot)}/cache`, lock: `${normalize(peerRoot)}/cache` });

    const key = `t43-unique-publish-target-1-${info.project.name}`;
    const ownLock = fixture('cache-isolation', info.project.name, { key, acquire: true });
    expect(ownLock.acquired).toBe(true);
    expect(ownLock.attempts).toBe(1);
    const peerLock = peer({ key, acquire: true });
    expect(peerLock.acquired).toBe(true);
    expect(peerLock.attempts).toBe(1);
    // A second process in the SAME run sees the held lock and its own rate-limit counter.
    const ownAgain = fixture('cache-isolation', info.project.name, { key, acquire: true });
    expect(ownAgain.acquired).toBe(false);
    expect(ownAgain.attempts).toBe(2);
});
