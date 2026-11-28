import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { effectScope, ref } from 'vue';

const moduleUrl = new URL('../../resources/js/Composables/useTheme.js', import.meta.url);
const blade = readFileSync(new URL('../../resources/views/app.blade.php', import.meta.url), 'utf8');

function browser({ account, stored, dark = false, blocked = false } = {}) {
    const media = new EventTarget();
    media.matches = dark;
    const classes = new Set();
    const meta = { content: '#0f172a', dataset: { light: '#0f172a', dark: '#0a1020' } };
    const root = {
        dataset: account ? { accountTheme: account } : {},
        style: {},
        classList: { toggle: (name, enabled) => (enabled ? classes.add(name) : classes.delete(name)) },
    };
    let saved = stored;
    const window = {
        document: { documentElement: root, querySelector: () => meta },
        matchMedia(query) {
            assert.equal(query, '(prefers-color-scheme: dark)');
            return media;
        },
        get localStorage() {
            if (blocked) throw new Error('Storage denied');
            return {
                getItem(key) {
                    assert.equal(key, 'ss.theme');
                    return saved;
                },
                setItem(key, value) {
                    assert.equal(key, 'ss.theme');
                    saved = value;
                },
            };
        },
    };

    return {
        window,
        changeSystem(matches) {
            media.matches = matches;
            media.dispatchEvent(new Event('change'));
        },
        assertTheme(scheme) {
            assert.equal(classes.has('dark'), scheme === 'dark');
            assert.equal(root.style.colorScheme, scheme);
            assert.equal(meta.content, meta.dataset[scheme]);
        },
        saved: () => saved,
    };
}

async function startTheme(t, account, client) {
    assert.ok(existsSync(moduleUrl), 'The application has no theme composable yet');
    const { useTheme } = await import(moduleUrl);
    const scope = effectScope();
    t.after(() => scope.stop());
    return { theme: scope.run(() => useTheme(() => account.value, client.window)), stop: () => scope.stop() };
}

test('account selection overrides guest storage and follows system changes only in system mode', async (t) => {
    const account = ref('system');
    const client = browser({ stored: 'dark' });
    const { theme, stop } = await startTheme(t, account, client);
    client.assertTheme('light');
    client.changeSystem(true);
    client.assertTheme('dark');
    account.value = 'light';
    client.assertTheme('light');
    client.changeSystem(false);
    client.changeSystem(true);
    client.assertTheme('light');
    account.value = 'dark';
    client.changeSystem(false);
    client.assertTheme('dark');
    theme.setGuestPreference('light');
    assert.equal(client.saved(), 'dark');
    account.value = 'system';
    client.assertTheme('light');
    stop();
    client.changeSystem(true);
    client.assertTheme('light');
});

test('guest selection survives navigation and blocked storage keeps the current session usable', async (t) => {
    const account = ref(null);
    const available = browser({ stored: 'dark' });
    const { theme } = await startTheme(t, account, available);
    available.assertTheme('dark');
    theme.setGuestPreference('light');
    available.assertTheme('light');
    assert.equal(available.saved(), 'light');
    account.value = 'dark';
    available.assertTheme('dark');
    account.value = null;
    available.assertTheme('light');

    const denied = browser({ blocked: true, dark: true });
    const { theme: fallback } = await startTheme(t, account, denied);
    denied.assertTheme('dark');
    fallback.setGuestPreference('light');
    denied.assertTheme('light');
});

test('the nonce-bearing bootstrap applies the first theme before Vite loads', () => {
    const script = blade.match(/<script nonce="\{\{ Vite::cspNonce\(\) \}\}">([\s\S]+?)<\/script>/);
    assert.ok(script, 'No synchronous theme is applied in the root Blade template');
    assert.ok(script.index < blade.indexOf('@vite('));
    for (const [options, scheme] of [
        [{ account: 'light', stored: 'dark', dark: true }, 'light'],
        [{ account: 'dark', blocked: true }, 'dark'],
        [{ account: 'system', stored: 'dark' }, 'light'],
        [{ stored: 'dark' }, 'dark'],
        [{ stored: 'sepia', dark: true }, 'dark'],
        [{ blocked: true }, 'light'],
    ]) {
        const client = browser(options);
        runInNewContext(script[1], { window: client.window, document: client.window.document });
        client.assertTheme(scheme);
    }
});
