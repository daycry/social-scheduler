import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Contexto de `navigation.js` (marca, ruta actual, rutas registradas...) y el shell compartido por Inertia. Lo usan el menú del layout
 * principal y la paleta de comandos (que se monta también en el compositor), para que ambos resuelvan las mismas páginas.
 */
export function useNavigationContext() {
    /** @type {ReturnType<typeof usePage<import('@/types/shell').ShellPageProps>>} */
    const page = usePage();
    const shell = computed(() => page.props.shell ?? { brands: [], currentBrand: null, accounts: {}, networks: [] });
    const brand = computed(() => shell.value.currentBrand);

    /** @param {...string} names */
    const is = (...names) => names.some((n) => route().current(n));

    /** @type {import('vue').ComputedRef<import('@/navigation').NavigationContext>} */
    const ctx = computed(() => ({
        brand: brand.value,
        q: brand.value ? { brand_id: brand.value.id } : {},
        shell: shell.value,
        page,
        route,
        is,
        has: (name) => route().has(name),
    }));

    return { page, shell, brand, ctx };
}
