import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

function worker() {
    const handlers = new Map();
    const notices = [];
    const opened = [];
    const forbidden = new Proxy(
        {},
        {
            get() {
                throw new Error('Worker attempted private storage.');
            },
        },
    );
    const self = {
        addEventListener: (name, handler) => handlers.set(name, handler),
        skipWaiting: async () => {},
        clients: { claim: async () => {}, openWindow: async (url) => opened.push(url) },
        registration: { showNotification: async (title, options) => notices.push({ title, ...options }) },
        location: { origin: 'https://app.example.test' },
    };
    vm.runInNewContext(fs.readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8'), {
        self,
        URL,
        caches: forbidden,
        indexedDB: forbidden,
    });
    async function fire(name, extra = {}) {
        assert.ok(handlers.has(name), `Missing ${name} behavior.`);
        const pending = [];
        const event = Object.defineProperties(
            { waitUntil: (promise) => pending.push(promise) },
            Object.getOwnPropertyDescriptors(extra),
        );
        handlers.get(name)(event);
        await Promise.all(pending);
    }
    return { handlers, notices, opened, fire };
}

test('the worker never intercepts user requests or reads private push text into notifications', async () => {
    const context = worker();
    assert.equal(context.handlers.has('fetch'), false);
    await context.fire('install');
    await context.fire('activate');
    await context.fire('push', {
        get data() {
            throw new Error('Private push data read.');
        },
    });
    assert.equal(context.notices.length, 1);
    assert.equal(context.notices[0].title, 'Social Scheduler');
    assert.equal(context.notices[0].body, 'Tienes nuevos avisos.');
});

test('notification clicks ignore a supplied external URL and open only the local authenticated inbox', async () => {
    const context = worker();
    let closed = false;
    await context.fire('notificationclick', {
        notification: {
            data: { url: 'https://evil.example/private' },
            close() {
                closed = true;
            },
        },
    });
    assert.equal(closed, true);
    assert.deepEqual(context.opened, ['https://app.example.test/notifications']);
});
