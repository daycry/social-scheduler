import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

function client(permission = 'granted', existing = null) {
    const calls = [];
    const deadlines = new Map();
    let timerId = 0;
    const subscription = {
        toJSON: () => ({ endpoint: 'https://push.example.test/device', keys: { p256dh: 'public', auth: 'auth' } }),
    };
    const registration = {
        pushManager: {
            getSubscription: async () => {
                calls.push(['get']);
                return existing;
            },
            subscribe: async (options) => {
                calls.push(['subscribe', options]);
                return subscription;
            },
        },
    };
    const sandbox = {
        window: { isSecureContext: true, PushManager: function () {} },
        navigator: {
            serviceWorker: {
                register: async (...args) => {
                    calls.push(['register', ...args]);
                    return registration;
                },
                ready: Promise.resolve(registration),
            },
        },
        Notification: {
            requestPermission: () => {
                calls.push(['permission']);
                return Promise.resolve(permission);
            },
        },
        Uint8Array,
        setTimeout: (callback) => {
            deadlines.set(++timerId, callback);
            return timerId;
        },
        clearTimeout: (id) => deadlines.delete(id),
        atob: (value) => Buffer.from(value, 'base64').toString('binary'),
    };
    const source = fs
        .readFileSync(new URL('../../resources/js/lib/push.js', import.meta.url), 'utf8')
        .replace(/^export /gm, '');
    vm.runInNewContext(
        source + '\nglobalThis.api = { registerPushWorker, activatePush, revokeBrowserPush, pushSupported };',
        sandbox,
    );
    return {
        calls,
        api: sandbox.api,
        sandbox,
        expire: () => [...deadlines.values()].forEach((callback) => callback()),
    };
}

async function assertBounded(context, operation) {
    await new Promise(setImmediate);
    let timer;
    const watchdog = new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error('La operación quedó esperando sin límite.')), 30);
    });
    const assertion = assert.rejects(Promise.race([operation, watchdog]), /no ha respondido a tiempo/);
    context.expire();
    try {
        await assertion;
    } finally {
        clearTimeout(timer);
    }
}

test('activation settles with a useful error when the worker never becomes ready', async () => {
    const context = client();
    context.sandbox.navigator.serviceWorker.ready = new Promise(() => {});
    await assertBounded(context, context.api.activatePush('BA'));
});

test('browser revocation is bounded even when unsubscribe never settles', async () => {
    const context = client('granted', { unsubscribe: () => new Promise(() => {}) });
    await assertBounded(context, context.api.revokeBrowserPush());
});

test('push client registers the static worker without requesting notification permission', async () => {
    const { calls, api } = client();
    await api.registerPushWorker();
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], 'register');
    assert.equal(calls[0][1], '/sw.js');
    assert.equal(calls[0][2].scope, '/');
    assert.equal(calls[0][2].updateViaCache, 'none');
});

test('push permission is requested synchronously from the action before registration and subscription', async () => {
    const { calls, api } = client();
    const promise = api.activatePush('BA');
    assert.equal(calls[0][0], 'permission');
    const result = await promise;
    assert.equal(result.endpoint, 'https://push.example.test/device');
    const options = calls.find(([name]) => name === 'subscribe')[1];
    assert.equal(options.userVisibleOnly, true);
    assert.deepEqual([...options.applicationServerKey], [4]);
});

test('denied permission does not register or subscribe a device', async () => {
    const { calls, api } = client('denied');
    await assert.rejects(api.activatePush('BA'), /permiso/);
    assert.deepEqual(
        calls.map(([name]) => name),
        ['permission'],
    );
});

test('revocation unsubscribes the current browser without requesting permission', async () => {
    let revoked = 0;
    const { calls, api } = client('granted', {
        unsubscribe: async () => {
            revoked++;
            return true;
        },
    });
    await api.revokeBrowserPush();
    assert.equal(revoked, 1);
    assert.ok(!calls.some(([name]) => name === 'permission'));
});
