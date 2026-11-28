/** Color del total agregado. El de cada red sale del catalogo del servidor (`useNetworks().color`). */
export const TOTAL_COLOR = 'rgb(var(--color-accent-fill))';

export const UNAVAILABLE = 'No disponible';

/** Numero legible; `null` -> «No disponible» (nunca 0).
 * @param {number | null | undefined} v
 */
export const fmt = (v, digits = 0) => {
    if (v === null || v === undefined) return UNAVAILABLE;
    return new Intl.NumberFormat('es-ES', { maximumFractionDigits: digits }).format(v);
};

/** @param {number | null | undefined} v */
export const fmtPct = (v) => {
    if (v === null || v === undefined) return null;
    return `${v > 0 ? '+' : ''}${new Intl.NumberFormat('es-ES', { maximumFractionDigits: 1 }).format(v)} %`;
};

/** @param {number | null | undefined} v */
export const deltaClass = (v) => {
    if (v === null || v === undefined || v === 0) return 'bg-surface-hover text-foreground-muted';
    return v > 0 ? 'bg-success-soft text-success-ink' : 'bg-danger-soft text-danger-ink';
};

/** @param {string} iso */
export const shortDate = (iso) => {
    const [, m, d] = iso.split('-');
    return `${d}/${m}`;
};
