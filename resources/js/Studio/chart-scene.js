import { normalizeChartSpec } from './chart-spec.js';
import { createPreparedBarChartGeometry } from './bar-geometry.js';

const abort = signal => { if (signal?.aborted) throw new DOMException('Chart operation aborted.', 'AbortError'); };
const freeze = value => { if (value && typeof value === 'object') { Object.values(value).forEach(freeze); Object.freeze(value); } return value; };

// Axis annotations are approximate display text; geometry and source lexemes stay exact.
function tickLabels(ticks) {
    const values = ticks.map(tick => tick.value);
    const tolerances = values.map((value, i) => Math.min(
        i ? value - values[i - 1] : Infinity,
        i + 1 < values.length ? values[i + 1] - value : Infinity,
    ) * 0.005);
    const shortest = value => {
        if (value === 0) return '0';
        const decimal = String(value), scientific = value.toExponential().replace('e+', 'e');
        return scientific.length < decimal.length ? scientific : decimal;
    };
    for (let precision = 3; precision <= 17; precision++) {
        const rounded = values.map(value => Number(value.toPrecision(precision)));
        if (rounded.every((value, i) => Number.isFinite(value)
            && (values[i] === 0 ? value === 0 : Math.sign(value) === Math.sign(values[i]))
            && Math.abs(value - values[i]) <= tolerances[i]
            && (i === 0 || rounded[i - 1] < value))) return rounded.map(shortest);
    }
    return values.map(shortest); // Shortest round-trip for extreme representable spacing.
}

// Percentages are derived display annotations; the full ratio stays in the table.
function percentageLabel(value) {
    const rounded = Number((value ?? 0).toPrecision(3));
    if (rounded === 0) return '0%';
    const decimal = String(rounded), scientific = rounded.toExponential().replace('e+', 'e');
    return `${scientific.length < decimal.length ? scientific : decimal}%`;
}

function outsideLabelLayout(geometry, style, horizontal, stacked, span) {
    const rows = new Map();
    let positiveSlots = 0, negativeSlots = 0, longest = 1;
    for (const bar of geometry.bars) {
        const row = rows.get(bar.row_id) ?? { positive: 0, negative: 0 };
        rows.set(bar.row_id, row);
        const sign = bar.value < 0 ? 'negative' : 'positive';
        row[sign]++;
        const label = style.value_labels === 'percentage' ? percentageLabel(bar.percentage) : bar.raw;
        longest = Math.max(longest, label.length);
        if (sign === 'negative') negativeSlots = Math.max(negativeSlots, stacked ? row.negative : 1);
        else positiveSlots = Math.max(positiveSlots, stacked ? row.positive : 1);
    }
    const desiredSize = horizontal ? Math.max(style.fontSize * 1.5, longest * style.fontSize * 0.75 + 2) : style.fontSize * 1.5;
    const desiredGap = Math.max(2, Math.min(6, style.fontSize * 0.2));
    const desired = (positiveSlots + negativeSlots) * (desiredSize + desiredGap);
    const factor = desired ? Math.min(1, Math.max(0, span - 40) / desired) : 1;
    return { size: desiredSize * factor, gap: desiredGap * factor,
        positive: positiveSlots * (desiredSize + desiredGap) * factor,
        negative: negativeSlots * (desiredSize + desiredGap) * factor, crowded: factor < 1 };
}

/** Work quotas use verified lightweight metadata, independently of resource-cache LRU. */
export async function assertChartDocumentBudget(document, chartResources, { signal } = {}) {
    abort(signal);
    const counts = new Map();
    const pageTotals = document.pages.map((page, pageIndex) => {
        let base = 0;
        function walk(elements) {
            for (const element of elements) {
                if (element.type === 'chart') {
                    const spec = normalizeChartSpec(element.chart), ref = spec.binding.dataset;
                    const key = `${ref.id}:${ref.revision}:${ref.sha256}`;
                    if (!counts.has(key)) counts.set(key, { ref, uses: new Map() });
                    const uses = counts.get(key).uses;
                    uses.set(pageIndex, (uses.get(pageIndex) ?? 0) + 1 + 2 * spec.binding.series_columns.length);
                    base += 100;
                } else { base++; if (element.type === 'group') walk(element.elements); }
            }
        }
        walk(page.elements); return base;
    });
    if (counts.size > 32) throw new TypeError('Chart work exceeds 32 unique references.');
    const checkObjects = () => {
        if (pageTotals.some(page => page > 50000)) throw new TypeError('Chart page primitive budget exceeds 50,000.');
        const total = pageTotals.reduce((sum, page) => sum + page, 0);
        if (total > 200000) throw new TypeError('Chart document primitive budget exceeds 200,000.');
        return total;
    };
    checkObjects();
    if (!counts.size) return checkObjects();
    if (typeof chartResources?.describe !== 'function') throw new TypeError('Chart resources.describe is required for work budgets.');
    const pending = [...counts.values()], controller = new AbortController();
    const cancelled = () => controller.abort();
    signal?.addEventListener('abort', cancelled, { once: true });
    let cursor = 0, bytes = 0, failure;
    async function worker() {
        while (!failure && !controller.signal.aborted && cursor < pending.length) {
            const entry = pending[cursor++];
            try {
                const info = await chartResources.describe(entry.ref, { signal: controller.signal });
                abort(controller.signal);
                if (info.id !== entry.ref.id || info.revision !== entry.ref.revision || info.sha256 !== entry.ref.sha256
                    || !Number.isSafeInteger(info.bytes) || info.bytes < 1 || info.bytes > 52000000
                    || !Number.isSafeInteger(info.row_count) || info.row_count < 0 || info.row_count > 1000
                    || !Number.isSafeInteger(info.column_count) || info.column_count < 1) throw new TypeError('Chart verified resource description is invalid.');
                bytes += info.bytes;
                if (bytes > 64000000) throw new TypeError('Chart work exceeds 64,000,000 canonical bytes.');
                for (const [page, coefficient] of entry.uses) pageTotals[page] += info.row_count * coefficient;
                checkObjects();
            } catch (error) { if (!failure) failure = error; controller.abort(); }
        }
    }
    try {
        await Promise.all(Array.from({ length: Math.min(4, pending.length) }, () => worker()));
        if (failure) throw failure;
        abort(signal);
        return checkObjects();
    } finally { signal?.removeEventListener('abort', cancelled); }
}

/** Own, bounded display primitives. Original cell lexemes remain in the accessible table. */
export async function createBarChartScene(chart, chartResources, { width = 800, height = 500, signal } = {}) {
    abort(signal);
    const spec = normalizeChartSpec(chart), style = spec.style;
    if (![width, height].every(n => Number.isFinite(n) && n >= 160 && n <= 8000)) throw new TypeError('Chart dimensions must be between 160 and 8000.');
    if (typeof chartResources?.prepare !== 'function') throw new TypeError('Chart resources are required.');
    const handle = await chartResources.prepare(spec.binding, { signal });
    abort(signal);
    const warnings = [], primitives = [];
    const warn = code => { if (!warnings.some(w => w.code === code)) warnings.push({ code }); };
    const horizontal = spec.options.kind.startsWith('bar');
    // Reservations scale with the layer rather than the requested font. A large font
    // is uniformly fitted into its box by the renderer, never allowed to erase plot.
    const pad = Math.min(16, width * 0.025, height * 0.025);
    const headerCount = Number(Boolean(style.title)) + Number(Boolean(style.subtitle));
    const header = headerCount ? Math.min(height * 0.16, headerCount * (style.fontSize * 1.5)) : 0;
    const footer = style.source ? Math.min(height * 0.06, style.fontSize * 1.4) : 0;
    const legendBottom = style.legend && style.legend_position === 'bottom' ? Math.min(height * 0.18, 60) : 0;
    const legendRight = style.legend && style.legend_position === 'right' ? Math.min(width * 0.25, 180) : 0;
    const left = Math.min(width * 0.22, 100) + (style.y_title ? Math.min(width * 0.07, 24) : 0);
    const bottom = Math.min(height * 0.18, 65) + (style.x_title ? Math.min(height * 0.06, 22) : 0);
    const plot = { x: pad + left, y: pad + header, width: width - 2 * pad - left - legendRight, height: height - 2 * pad - header - footer - legendBottom - bottom };
    const annotationFrame = { ...plot };
    const stacked = /-(?:stacked|proportional)$/.test(spec.options.kind);
    let geometry = createPreparedBarChartGeometry(handle, { ...spec.options, width: plot.width, height: plot.height });
    let valueLayout = null;
    if (style.value_labels !== 'none' && style.label_position === 'outside') {
        valueLayout = outsideLabelLayout(geometry, style, horizontal, stacked, horizontal ? plot.width : plot.height);
        if (valueLayout.crowded) warn('VALUE_LABELS_CROWDED');
        if (valueLayout.positive || valueLayout.negative) {
            // The reservation is capped at span - 40; floating-point subtraction
            // can still land one ulp below 40. Preserve that local layout minimum.
            if (horizontal) { plot.x += valueLayout.negative; plot.width = Math.max(40, plot.width - (valueLayout.positive + valueLayout.negative)); }
            else { plot.y += valueLayout.positive; plot.height = Math.max(40, plot.height - (valueLayout.positive + valueLayout.negative)); }
            // Same prepared handle, no new read/projection. The preliminary geometry is
            // released; at most one further linear pass computes the reserved layout.
            geometry = createPreparedBarChartGeometry(handle, { ...spec.options, width: plot.width, height: plot.height });
        }
    }
    warnings.push(...geometry.warnings);
    let sequence = 0;
    function add(type, role, box, props = {}) {
        const x = Math.max(0, Math.min(width, box.x)), y = Math.max(0, Math.min(height, box.y));
        const id = role === 'bar' || role === 'value' ? `${role}:${props.row_id}:${props.column_id}`
            : role === 'category' ? `category:${props.row_id}` : role === 'zero' ? 'axis:zero'
                : role === 'legend' || role === 'legend-swatch' ? `${role}:${props.legend_key}` : props.semantic_id ?? `${role}:${sequence}`;
        sequence++;
        primitives.push({ id, type, role, ...props, x, y, width: Math.max(0, Math.min(box.width, width - x)), height: Math.max(0, Math.min(box.height, height - y)) });
    }
    function text(role, value, box, props = {}) {
        const label = String(value);
        if (!label) return;
        const vertical = props.rotation === -90;
        if ((vertical ? box.width : box.height) < style.fontSize * 1.2 || [...label].length * style.fontSize * 0.6 > (vertical ? box.height : box.width)) {
            warn('TEXT_FITTED');
            if (role === 'value') warn('VALUE_LABELS_CROWDED');
        }
        add('text', role, box, { text: label, fontFamily: style.fontFamily, ...(style.font_id == null ? {} : { font_id: style.font_id }), fontSize: style.fontSize, fontWeight: style.fontWeight, fontStyle: style.fontStyle, fill: style.text_color, ...props });
    }
    const shortened = label => {
        const chars = [...label];
        if (chars.length <= style.category_label_limit) return label;
        warn('CATEGORY_LABEL_SHORTENED');
        return chars.slice(0, Math.max(0, style.category_label_limit - 1)).join('') + '…';
    };
    add('rect', 'background', { x: 0, y: 0, width, height }, { fill: style.background });
    let titleY = pad;
    for (const role of ['title', 'subtitle']) if (style[role]) {
        text(role, style[role], { x: pad, y: titleY, width: width - 2 * pad, height: header / headerCount }); titleY += header / headerCount;
    }
    if (style.source) text('source', style.source, { x: pad, y: height - pad - footer, width: width - 2 * pad, height: footer });
    if (style.x_title) text('x-title', style.x_title, { x: plot.x, y: annotationFrame.y + annotationFrame.height + bottom * 0.65, width: plot.width, height: bottom * 0.35 });
    if (style.y_title) text('y-title', style.y_title, { x: pad, y: plot.y, width: Math.max(1, left * 0.24), height: plot.height }, { rotation: -90 });
    const labels = tickLabels(geometry.ticks);
    for (const [index, tick] of geometry.ticks.entries()) {
        const position = horizontal ? { x: plot.x + tick.position, y: plot.y, width: 0, height: plot.height } : { x: plot.x, y: plot.y + tick.position, width: plot.width, height: 0 };
        if (style.show_grid) add('line', 'grid', position, { stroke: style.grid_color, semantic_id: `grid:${index}` });
        if (style.show_axis) text('tick', labels[index], horizontal
            ? { x: Math.max(0, position.x - 22), y: plot.y + plot.height, width: 44, height: bottom * 0.35 }
            : { x: pad + left * 0.25, y: Math.max(plot.y, position.y - 8), width: left * 0.7, height: 16 }, { fill: style.axis_color, semantic_id: `tick:${index}` });
    }
    if (style.show_axis) {
        add('line', 'axis', { x: plot.x, y: plot.y + plot.height, width: plot.width, height: 0 }, { stroke: style.axis_color, semantic_id: 'axis:x' });
        add('line', 'axis', { x: plot.x, y: plot.y, width: 0, height: plot.height }, { stroke: style.axis_color, semantic_id: 'axis:y' });
        add('line', 'zero', horizontal ? { x: plot.x + geometry.baseline, y: plot.y, width: 0, height: plot.height } : { x: plot.x, y: plot.y + geometry.baseline, width: plot.width, height: 0 }, { stroke: style.axis_color });
    }
    const cells = new Map();
    for (const bar of geometry.bars) {
        cells.set(bar.key, bar);
        add('rect', 'bar', { x: plot.x + bar.x, y: plot.y + bar.y, width: bar.width, height: bar.height }, { fill: bar.fill, row_id: bar.row_id, column_id: bar.column_id, value: bar.value, raw: bar.raw, percentage: bar.percentage });
    }
    const table = { categories: geometry.categories.map(({ row_id, type, label }) => ({ row_id, type, label })), series: geometry.series.map(s => ({ ...s })), rows: geometry.categories.map(category => ({ row_id: category.row_id, category: { type: category.type, label: category.label }, values: geometry.series.map(series => {
        const cell = cells.get(`${category.row_id}:${series.column_id}`);
        return { column_id: series.column_id, value: cell?.value ?? null, raw: cell?.raw ?? null, percentage: cell?.percentage ?? null };
    }) })) };
    if (style.category_labels) {
        const span = horizontal ? plot.height : plot.width;
        const stride = Math.max(1, Math.ceil(geometry.categories.length / Math.max(1, Math.floor(span / (style.fontSize * 1.5)))));
        if (stride > 1) warn('CATEGORY_LABELS_SAMPLED');
        geometry.categories.forEach((category, index) => {
            if (index % stride) return;
            const band = category.band_end - category.band_start;
            text('category', shortened(category.label), horizontal
                ? { x: pad + left * 0.25, y: plot.y + category.band_start, width: left * 0.7, height: band }
                : { x: plot.x + category.band_start, y: annotationFrame.y + annotationFrame.height + bottom * 0.35, width: band, height: bottom * 0.3 }, { row_id: category.row_id });
        });
    }
    const multicolor = spec.options.kind.endsWith('multicolor');
    const legend = (multicolor ? geometry.categories : geometry.series).map((item, index) => ({ key: multicolor ? item.row_id : item.column_id, label: item.label, fill: spec.options.palette[index % spec.options.palette.length] }));
    if (style.legend) {
        const entries = legend.slice(0, 12), right = style.legend_position === 'right';
        const box = right ? { x: annotationFrame.x + annotationFrame.width + pad, y: annotationFrame.y, width: legendRight - pad, height: annotationFrame.height } : { x: pad, y: height - pad - footer - legendBottom, width: width - 2 * pad, height: legendBottom };
        const count = entries.length + Number(legend.length > 12);
        entries.forEach((entry, i) => {
            const x = right ? box.x : box.x + box.width * i / Math.max(1, count);
            const y = right ? box.y + box.height * i / count : box.y;
            const w = right ? box.width : box.width / count, h = right ? box.height / count : box.height;
            add('rect', 'legend-swatch', { x, y, width: Math.min(8, w * 0.2), height: Math.min(8, h) }, { fill: entry.fill, legend_key: entry.key });
            text('legend', shortened(entry.label), { x: x + Math.min(10, w * 0.25), y, width: Math.max(0, w - Math.min(10, w * 0.25)), height: h }, { legend_key: entry.key });
        });
        if (legend.length > 12) {
            warn('LEGEND_SUMMARIZED');
            text('legend-summary', `+${legend.length - 12}`, { x: right ? box.x : box.x + box.width * 12 / count, y: right ? box.y + box.height * 12 / count : box.y, width: right ? box.width : box.width / count, height: right ? box.height / count : box.height });
        }
    }
    if (style.value_labels !== 'none') {
        // Per-row sign lanes prevent segments (including repeated zeros) sharing a
        // label slot. There is no comparison against every other chart annotation.
        const rows = new Map();
        for (const bar of geometry.bars) {
            const row = rows.get(bar.row_id) ?? { positiveEnd: geometry.baseline,
                negativeEnd: geometry.baseline, positive: 0, negative: 0, zeros: 0, zero: 0 };
            rows.set(bar.row_id, row);
            if (bar.value < 0) row.negativeEnd = horizontal ? Math.min(row.negativeEnd, bar.x) : Math.max(row.negativeEnd, bar.y + bar.height);
            else row.positiveEnd = horizontal ? Math.max(row.positiveEnd, bar.x + bar.width) : Math.min(row.positiveEnd, bar.y);
            if (bar.value === 0) row.zeros++;
        }
        for (const bar of geometry.bars) {
            const row = rows.get(bar.row_id), negative = bar.value < 0;
            const label = style.value_labels === 'percentage' ? percentageLabel(bar.percentage) : bar.raw;
            let box;
            if (valueLayout) {
                const lane = stacked ? row[negative ? 'negative' : 'positive']++ : 0;
                const outer = stacked ? row[negative ? 'negativeEnd' : 'positiveEnd']
                    : horizontal ? bar.x + (negative ? 0 : bar.width) : bar.y + (negative ? bar.height : 0);
                const distance = lane * (valueLayout.size + valueLayout.gap);
                box = horizontal ? { x: plot.x + outer + (negative ? -distance - valueLayout.gap - valueLayout.size : distance + valueLayout.gap),
                    y: plot.y + bar.y, width: valueLayout.size, height: bar.height }
                    : { x: plot.x + bar.x, y: plot.y + outer + (negative ? distance + valueLayout.gap : -distance - valueLayout.gap - valueLayout.size),
                        width: bar.width, height: valueLayout.size };
            } else if (bar.value === 0) {
                const count = stacked ? row.zeros : 1, lane = stacked ? row.zero++ : 0;
                const nominalWidth = Math.min(plot.width, Math.max(style.fontSize * 1.5, label.length * style.fontSize * 0.75 + 2));
                const nominalHeight = Math.min(plot.height, style.fontSize * 1.5);
                box = horizontal ? { x: Math.min(plot.x + geometry.baseline, plot.x + plot.width - nominalWidth), y: plot.y + bar.y + lane * bar.height / count,
                    width: nominalWidth, height: bar.height / count }
                    : { x: plot.x + bar.x + lane * bar.width / count, y: Math.max(plot.y, plot.y + geometry.baseline - nominalHeight), width: bar.width / count, height: nominalHeight };
                warn('ZERO_VALUE_LABEL_ANCHORED');
            } else box = { x: plot.x + bar.x, y: plot.y + bar.y, width: bar.width, height: bar.height };
            text('value', label, box, { row_id: bar.row_id, column_id: bar.column_id, raw: bar.raw, percentage: bar.percentage });
        }
    }
    if (primitives.length > 100 + geometry.categories.length + 2 * geometry.categories.length * geometry.series.length) throw new TypeError('Chart primitive budget exceeded.');
    abort(signal);
    return freeze({ width, height, plot, primitives, table, legend, warnings });
}
