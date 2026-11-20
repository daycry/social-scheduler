/** @typedef {string | number | Date | null | undefined} DateValue */
/** @typedef {(value: DateValue) => string} DateFormatter */

/**
 * Formatea el instante en la zona de la marca o en la del navegador si no hay marca.
 * @param {string | null | undefined} timeZone
 * @param {Intl.DateTimeFormatOptions} options
 * @param {string} [fallback]
 * @returns {DateFormatter}
 */
export function createDateFormatter(timeZone, options, fallback = '') {
    let formatter;
    try {
        formatter = new Intl.DateTimeFormat('es-ES', { ...options, timeZone: timeZone || undefined });
    } catch (error) {
        if (!(error instanceof RangeError) || !timeZone) throw error;
        formatter = new Intl.DateTimeFormat('es-ES', { ...options, timeZone: 'UTC' });
    }

    return value => {
        if (!value) return fallback;
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? fallback : formatter.format(date);
    };
}

/**
 * @param {Intl.DateTimeFormatOptions} options
 * @param {string} [fallback]
 * @returns {(value: DateValue, timeZone: string | null | undefined) => string}
 */
export function createZonedDateFormatter(options, fallback = '') {
    /** @type {Map<string | null | undefined, DateFormatter>} */
    const formatters = new Map();
    return (value, timeZone) => {
        if (!formatters.has(timeZone)) formatters.set(timeZone, createDateFormatter(timeZone, options, fallback));
        return (/** @type {DateFormatter} */ (formatters.get(timeZone)))(value);
    };
}
