export const NETWORK_COLORS = {
    instagram: '#db2777',
    facebook: '#2563eb',
    youtube: '#dc2626',
    tiktok: '#111827',
    total: '#4f46e5',
};

export const UNAVAILABLE = 'No disponible';

/** Numero legible; `null` -> «No disponible» (nunca 0). */
export const fmt = (v, digits = 0) => {
    if (v === null || v === undefined) return UNAVAILABLE;
    return new Intl.NumberFormat('es-ES', { maximumFractionDigits: digits }).format(v);
};

export const fmtPct = (v) => {
    if (v === null || v === undefined) return null;
    return `${v > 0 ? '+' : ''}${new Intl.NumberFormat('es-ES', { maximumFractionDigits: 1 }).format(v)} %`;
};

export const deltaClass = (v) => {
    if (v === null || v === undefined || v === 0) return 'bg-gray-100 text-gray-600';
    return v > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
};

export const shortDate = (iso) => {
    const [, m, d] = iso.split('-');
    return `${d}/${m}`;
};
