import { usePage } from '@inertiajs/vue3';

const FALLBACK_COLOR = '#64748b';

/**
 * Catalogo de redes del servidor (`shell.networks`, de `App\Connectors\Network`): etiqueta, nombre corto, color y proveedor.
 * Es la unica fuente de redes del cliente: ninguna pagina repite la lista ni los colores.
 */
export function useNetworks() {
    const page = usePage();
    const list = () => page.props.shell?.networks ?? [];
    const find = (value) => list().find((n) => n.value === value) ?? null;

    return {
        list,
        find,
        color: (value) => find(value)?.color ?? FALLBACK_COLOR,
        label: (value) => find(value)?.label ?? value,
        shortLabel: (value) => find(value)?.short_label ?? value,
    };
}
