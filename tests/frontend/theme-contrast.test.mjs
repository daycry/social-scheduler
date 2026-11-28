import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { test } from 'node:test';

const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const networkCatalog = readFileSync(new URL('../../app/Connectors/Network.php', import.meta.url), 'utf8');
const networkColors = [...networkCatalog.matchAll(/self::\w+ => '#([\da-f]{6})'/gi)].map(([, hex]) => [
    hex,
    [0, 2, 4].map((offset) => Number.parseInt(hex.slice(offset, offset + 2), 16)),
]);
const surfaces = ['canvas', 'surface', 'surface-raised', 'surface-hover', 'accent-soft'];
const secondarySurfaces = ['accent-secondary-soft', 'accent-secondary-marker', 'surface-pressed'];
const vue = new URL('../../resources/js/', import.meta.url);

test('Auth, Profile, Pagination and EmptyState use semantic colors for their UI', () => {
    const pages = ['Auth', 'Profile'].flatMap((area) => {
        const directory = new URL(`Pages/${area}/`, vue);
        return readdirSync(directory, { recursive: true })
            .filter((file) => file.endsWith('.vue'))
            .map((file) => `Pages/${area}/${file.replaceAll('\\', '/')}`);
    });
    const files = [...pages, 'Components/Pagination.vue', 'Components/EmptyState.vue'];
    const fixedPalette =
        /\b(?:text|bg|border|divide|ring|outline|fill|stroke|from|via|to)-(?:white|black|(?:slate|gray|zinc|neutral|stone|red|rose|amber|yellow|emerald|green|sky|blue|indigo|violet|teal|orange)-\d+|\[#[\da-f]+\])(?=[\s/"'`;,]|$)/gi;
    const violations = files.flatMap((file) => {
        const source = readFileSync(new URL(file, vue), 'utf8').split('<style')[0];
        return [...new Set(source.match(fixedPalette))].map((utility) => `${file}: ${utility}`);
    });
    assert.deepEqual(violations, []);
});

const pairs = [
    ...surfaces.flatMap((background) =>
        [
            'foreground',
            'foreground-strong',
            'foreground-secondary',
            'foreground-muted',
            'foreground-subtle',
            'accent',
            'accent-strong',
            'danger',
        ].map((foreground) => [foreground, background, 4.5]),
    ),
    ...['canvas', 'surface', 'surface-raised', 'surface-hover', 'accent-soft'].flatMap((background) =>
        ['control-border', 'focus'].map((foreground) => [foreground, background, 3]),
    ),
    ...['accent-fill', 'accent-hover', 'accent-active'].map((background) => ['on-accent', background, 4.5]),
    ...['danger-fill', 'danger-hover', 'danger-active', 'danger-badge'].map((background) => [
        'on-danger',
        background,
        4.5,
    ]),
    ...['success', 'danger', 'warning', 'info'].map((status) => [`${status}-ink`, `${status}-soft`, 4.5]),
    ...['surface', 'surface-raised'].flatMap((background) =>
        ['success-ink', 'warning-ink'].map((foreground) => [foreground, background, 4.5]),
    ),
    ...['warning-soft', 'info-soft'].map((background) => ['accent', background, 4.5]),
    ...['canvas', 'surface', 'surface-raised'].flatMap((background) =>
        ['accent-fill', 'danger-fill', 'check'].map((foreground) => [foreground, background, 3]),
    ),
    ['chrome-strong', 'check', 3], // Marca blanca del plugin forms.
    ['accent-strong', 'accent-marker', 4.5],
    ...[...surfaces, ...secondarySurfaces].map((background) => ['accent-secondary-ink', background, 4.5]),
    ...secondarySurfaces.flatMap((background) =>
        ['foreground-strong', 'foreground-secondary', 'foreground-muted'].map((foreground) => [
            foreground,
            background,
            4.5,
        ]),
    ),
    ...['accent-secondary-fill', 'accent-secondary-hover'].map((background) => [
        'on-accent-secondary',
        background,
        4.5,
    ]),
    ...['surface', 'surface-raised', 'canvas'].flatMap((background) =>
        ['accent-secondary-fill', 'success-fill'].map((foreground) => [foreground, background, 3]),
    ),
    ['on-success', 'success-fill', 4.5],
    ['calendar-event-ink', 'calendar-event-soft', 4.5],
    ['chrome-strong', 'control-border', 3], // Pomo del interruptor de presets apagado.
    ...['chrome', 'chrome-panel', 'chrome-hover'].flatMap((background) =>
        ['chrome-text', 'chrome-muted', 'chrome-strong', 'chrome-accent'].map((foreground) => [
            foreground,
            background,
            4.5,
        ]),
    ),
    ['chrome-border', 'chrome', 3],
    ['chrome-active', 'chrome-hover', 3],
    ['chrome-strong', 'chrome-avatar-start', 4.5],
    ['chrome-strong', 'chrome-avatar-end', 4.5],
    ['focus', 'chrome', 3],
    ['focus', 'chrome-panel', 3],
    ['focus', 'chrome-hover', 3],
];

function luminance(rgb) {
    const [red, green, blue] = rgb.map((channel) => {
        const srgb = channel / 255;
        return srgb <= 0.04045 ? srgb / 12.92 : ((srgb + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
}

function contrast(first, second) {
    const a = luminance(first);
    const b = luminance(second);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

function mix(base, color, share) {
    return base.map((channel, index) => channel * (1 - share) + color[index] * share);
}

for (const [theme, selector] of [
    ['light', ':root'],
    ['dark', ':root.dark'],
]) {
    test(`semantic tokens meet WCAG AA in ${theme}`, () => {
        const block = css.match(new RegExp(`${selector.replace('.', '\\.')}\\s*\\{([^}]+)\\}`));
        assert.ok(block, `Missing ${selector} theme tokens`);
        const tokens = Object.fromEntries(
            [...block[1].matchAll(/--color-([\w-]+):\s*(\d+) (\d+) (\d+);/g)].map(([, name, ...rgb]) => [
                name,
                rgb.map(Number),
            ]),
        );

        for (const [foreground, background, minimum] of pairs) {
            assert.ok(tokens[foreground], `Missing --color-${foreground} in ${theme}`);
            assert.ok(tokens[background], `Missing --color-${background} in ${theme}`);
            const ratio = contrast(tokens[foreground], tokens[background]);
            assert.ok(ratio >= minimum, `${foreground} / ${background}: ${ratio.toFixed(2)} < ${minimum} in ${theme}`);
        }

        for (const token of ['accent-secondary-border', 'calendar-event-border', 'editor-stage', 'editor-stage-dot']) {
            assert.ok(tokens[token], `Missing decorative --color-${token} in ${theme}`);
        }
        assert.ok(contrast(tokens['editor-stage'], tokens['editor-stage-dot']) > 1.25);

        const timeline = readFileSync(new URL('Components/Studio/TimelinePanel.vue', vue), 'utf8');
        const clip = timeline.match(/data-testid="timeline-clip"[\s\S]*?:class="([^"]+)"/)?.[1];
        const states = [...(clip?.matchAll(/\bbg-([\w-]+) text-([\w-]+)'/g) ?? [])];
        assert.equal(states.length, 2, 'Missing normal or selected clip colors');
        const stripes = [
            ...timeline.matchAll(/data-testid="timeline-animation-(?:in|out)"\s+class="[^"]*\bbg-([\w-]+)"/g),
        ];
        assert.equal(stripes.length, 2, 'Missing animation stripe colors');
        const lowContrast = stripes.flatMap(([, stripe]) =>
            states.flatMap(([, background, label]) => {
                const foreground = stripe === 'current' ? label : stripe;
                assert.ok(tokens[foreground] && tokens[background], `Missing timeline tokens in ${theme}`);
                const ratio = contrast(tokens[foreground], tokens[background]);
                return ratio >= 3 ? [] : [`${foreground} / ${background}: ${ratio.toFixed(2)} < 3`];
            }),
        );
        assert.deepEqual([...new Set(lowContrast)], [], `Animation stripe contrast in ${theme}`);

        const chartShare = Number(block[1].match(/--chart-brand-share:\s*(\d+)%/)?.[1]) / 100;
        assert.ok(chartShare > 0 && chartShare <= 1 && networkColors.length > 0);
        for (const [name, brand] of [...networkColors, ['accent-fill', tokens['accent-fill']]]) {
            const series = mix(tokens['foreground-strong'], brand, chartShare);
            for (const surface of ['surface', 'surface-raised']) {
                assert.ok(contrast(series, tokens[surface]) >= 3, `Chart series ${name} on ${surface} in ${theme}`);
            }
            const calendarText = mix(tokens['foreground-strong'], brand, 0.35);
            const calendarFill = mix(tokens.surface, brand, 0.12);
            assert.ok(contrast(calendarText, calendarFill) >= 4.5, `Calendar label ${name} in ${theme}`);
        }

        // Tonos del mapa de calor sobre la superficie y texto usado en cada nivel.
        for (const [intensity, label] of [
            [0.15, 'foreground-strong'],
            [0.32, 'foreground-strong'],
            [0.48, 'foreground-strong'],
            [0.85, 'on-accent'],
            [1, 'on-accent'],
        ]) {
            const shade = mix(tokens.surface, tokens['accent-fill'], intensity);
            assert.ok(contrast(shade, tokens[label]) >= 4.5, `Heatmap intensity ${intensity} in ${theme}`);
        }

        const pendingConnection = readFileSync(new URL('Layouts/AuthenticatedLayout.vue', vue), 'utf8').match(
            /<div\b[^>]*\bclass="([^"]+)"[^>]*\bdata-pending-connection\b/,
        );
        assert.ok(pendingConnection, 'Missing pending connection notice');
        const outline = pendingConnection[1].match(/\bborder-(warning-[\w-]+)\b/)?.[1];
        assert.ok(tokens[outline], `Missing pending connection outline token in ${theme}`);
        for (const background of ['canvas', 'surface', 'warning-soft']) {
            const ratio = contrast(tokens[outline], tokens[background]);
            assert.ok(ratio >= 3, `${outline} / ${background}: ${ratio.toFixed(2)} < 3 in ${theme}`);
        }
    });
}
