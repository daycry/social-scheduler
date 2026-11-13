const labels = {
    complete: 'Cobertura completa', partial: 'Datos parciales', no_data: 'Sin datos recogidos',
    not_due: 'Periodo aún abierto', unsupported: 'No disponible en esta red',
    unavailable_sandbox: 'No disponible en el entorno de pruebas', unavailable_access: 'No disponible: sin acceso a la analítica',
};
const date = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value ?? '') ? value.split('-').reverse().join('/') : null;

export function metricQuality(coverage, freshness = {}) {
    if (!coverage || !labels[coverage.status]) return null;
    const parts = [];
    if (coverage.expected_account_days > 0) {
        parts.push(`${coverage.observed_account_days ?? 0} de ${coverage.expected_account_days} observaciones de cuenta y día`);
        if (coverage.missing_account_days > 0) parts.push(`${coverage.missing_account_days} sin dato`);
    }
    if (date(freshness?.latest_data_date)) parts.push(`Último día con datos: ${date(freshness.latest_data_date)}`);
    if (freshness?.is_stale && date(freshness.latest_expected_date)) parts.push(`Faltan datos hasta el ${date(freshness.latest_expected_date)}`);
    if (coverage.status === 'no_data') parts.push('No se han recogido valores para este periodo; no equivale a cero actividad');
    if (coverage.status === 'not_due') parts.push('Este periodo todavía no contiene días cerrados para la red');
    if (coverage.status === 'unsupported') parts.push('La API de esta red no ofrece esta métrica');
    if (coverage.status === 'unavailable_sandbox') parts.push('Pinterest no ofrece analítica en el entorno de pruebas; conecta la app con acceso de producción');
    if (coverage.status === 'unavailable_access') parts.push('Pinterest rechazó la analítica: reconecta la cuenta con los permisos pedidos o pide a Pinterest el acceso estándar a la app');
    return { label: labels[coverage.status], detail: parts.join(' · '), warning: ['partial', 'no_data', 'unavailable_sandbox', 'unavailable_access'].includes(coverage.status) || freshness?.is_stale === true };
}
