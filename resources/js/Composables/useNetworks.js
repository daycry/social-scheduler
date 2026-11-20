import { usePage } from '@inertiajs/vue3';

const FALLBACK_COLOR = '#64748b';

/**
 * Catalogo de redes del servidor (`shell.networks`, de `App\Connectors\Network`): etiqueta, nombre corto, color y proveedor.
 * Es la unica fuente de redes del cliente: ninguna pagina repite la lista ni los colores.
 */
export function useNetworks() {
    /** @type {ReturnType<typeof usePage<Pick<import('@/types/shell').ShellPageProps, 'shell'>>>} */
    const page = usePage();
    const list = () => page.props.shell?.networks ?? [];
    /** @param {string | null | undefined} value */
    const find = (value) => list().find((n) => n.value === value) ?? null;

    return {
        list,
        find,
        /** @param {string | null | undefined} value */
        color: (value) => find(value)?.color ?? FALLBACK_COLOR,
        /** @param {string} value */
        label: (value) => find(value)?.label ?? value,
        /** @param {string} value */
        shortLabel: (value) => find(value)?.short_label ?? value,
    };
}
