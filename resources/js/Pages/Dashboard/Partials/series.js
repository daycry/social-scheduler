/**
 * @param {import('@/types/analytics').SummaryMetric} card
 * @param {import('@/types/analytics').ConnectedNetwork[]} networks
 * @param {(network: string) => string} color
 * @returns {import('@/types/analytics').ChartSeries[]}
 */
export function seriesByNetwork(card, networks, color) {
    return networks
        .filter((network) => card.by_network.find((entry) => entry.network === network.value)?.available)
        .map(({ value, label }) => ({
            name: label,
            color: color(value),
            points: card.series.map((point) => ({
                date: point.date,
                value: Array.isArray(point.values) ? null : (point.values[value] ?? null),
            })),
        }));
}
