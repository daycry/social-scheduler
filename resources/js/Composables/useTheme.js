import { computed, onScopeDispose, ref, watch } from 'vue';

/** @typedef {'system' | 'light' | 'dark'} ThemePreference */

export const themeKey = /** @type {import('vue').InjectionKey<ReturnType<typeof useTheme>>} */ (Symbol('theme'));
const storageKey = 'ss.theme';

/** @param {unknown} value @returns {ThemePreference} */
function normalize(value) {
    return value === 'light' || value === 'dark' ? value : 'system';
}

/**
 * Sincroniza el documento durante la vida de la app. La prop de Inertia es la fuente de la cuenta.
 * @param {() => unknown} accountPreference `null` o `undefined` cuando no hay sesión.
 * @param {Window} browser
 */
export function useTheme(accountPreference, browser = window) {
    const media = browser.matchMedia('(prefers-color-scheme: dark)');
    const systemDark = ref(media.matches);
    const guestPreference = ref(/** @type {ThemePreference | null} */ (null));
    const account = computed(accountPreference);
    const isGuest = computed(() => account.value == null);
    const preference = computed(() => {
        if (!isGuest.value) return normalize(account.value);
        if (guestPreference.value !== null) return guestPreference.value;
        try {
            return normalize(browser.localStorage.getItem(storageKey));
        } catch {
            return 'system';
        }
    });
    const dark = computed(() => preference.value === 'dark' || (preference.value === 'system' && systemDark.value));
    const root = browser.document.documentElement;
    const meta = /** @type {HTMLMetaElement | null} */ (browser.document.querySelector('meta[name="theme-color"]'));

    watch(
        dark,
        (enabled) => {
            const scheme = enabled ? 'dark' : 'light';
            root.classList.toggle('dark', enabled);
            root.style.colorScheme = scheme;
            if (meta) meta.content = meta.dataset[scheme] ?? meta.content;
        },
        { immediate: true, flush: 'sync' },
    );

    const syncSystem = () => {
        systemDark.value = media.matches;
    };
    media.addEventListener('change', syncSystem);
    onScopeDispose(() => media.removeEventListener('change', syncSystem));

    /** @param {ThemePreference} value */
    function setGuestPreference(value) {
        if (!isGuest.value) return;
        guestPreference.value = normalize(value);
        try {
            browser.localStorage.setItem(storageKey, guestPreference.value);
        } catch {
            // El tema permanece activo en el documento.
        }
    }

    return { isGuest, preference, dark, setGuestPreference };
}
