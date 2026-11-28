import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { test } from 'node:test';

const js = new URL('../../resources/js/', import.meta.url);
const utilities =
    /(?<![\w-])(?:text|bg|border(?:-[trblxyse])?|divide|ring(?:-offset)?|outline|placeholder|fill|stroke|accent|caret|decoration|shadow|from|via|to)-(?:white|black|(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|night)-\d+|\[([^\]\r\n]+)\])(?=$|[\s/"'`,;)}\]])/gi;
const hexOrFunction =
    /#(?:[\da-f]{8}|[\da-f]{6}|[\da-f]{4}|[\da-f]{3})\b|\b(?:(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(\s*|color\(\s*[\w-]+\s+)[+\d.-]/gi;

// Palabras clave <named-color> de CSS. Los valores relativos al tema se permiten.
const colorNames = new Set(
    `aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue blueviolet brown burlywood
    cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue darkcyan darkgoldenrod darkgray
    darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon darkseagreen
    darkslateblue darkslategray darkslategrey darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue
    firebrick floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew
    hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon lightblue lightcoral lightcyan
    lightgoldenrodyellow lightgray lightgreen lightgrey lightpink lightsalmon lightseagreen lightskyblue lightslategray
    lightslategrey lightsteelblue lightyellow lime limegreen linen magenta maroon mediumaquamarine mediumblue mediumorchid
    mediumpurple mediumseagreen mediumslateblue mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream
    mistyrose moccasin navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen
    paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple rebeccapurple red rosybrown royalblue
    saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue slategray slategrey snow springgreen
    steelblue tan teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen`.split(/\s+/),
);

const quoted = /"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|`(?:\\.|[^`\\])*`/.source;
const colorProperty =
    /[\w-]*color|background(?:-?image)?|border(?:-?(?:top|right|bottom|left|block|inline)(?:-?(?:start|end))?)?|outline|(?:box|text)-?shadow|text-?decoration|(?:fill|stroke)(?:Style)?/.source;

// Consume los atributos y textos ajenos al estilo antes de buscar declaraciones dentro de ellos.
const declarations = new RegExp(
    [
        /(?<![\w-])(?:v-bind:|:)?style\s*=\s*(?<inline>"[^"]*"|'[^']*')/.source,
        String.raw`(?<![\w$-])(?<property>["']?(?:${colorProperty})["']?\s*[:=]\s*)`,
        String.raw`\b(?<setProperty>setProperty\(\s*(?:${quoted})\s*,\s*)`,
        quoted,
        /(?<=>)[^<>{}]+(?=<)/.source,
    ].join('|'),
    'gi',
);

const styleValue = new RegExp(
    `^(?:${[
        String.raw`(?:${quoted}|[^?;{},"'\x60])+?\?\s*(?<ifTrue>${quoted})\s*:\s*(?<ifFalse>${quoted})`,
        String.raw`(?<array>\[\s*(?:${quoted})(?:\s*,\s*(?:${quoted}))*\s*,?\s*\])`,
        String.raw`(?<literal>${quoted})`,
        /(?<css>[^;:"'`{}\[\]=?]+)/.source,
    ].join('|')})`,
);

function withoutUrls(value) {
    return value.replace(/url\([^)]*\)/gi, '');
}

function namedColors(value) {
    const identifiers =
        withoutUrls(value)
            .replace(/\$\{[^}]*\}/g, '')
            .match(/(?<![\w.-])[\w-]+(?![\w.-])/g) ?? [];
    return identifiers.filter((word) => colorNames.has(word.toLowerCase()));
}

function declaredColors(source) {
    return [...source.matchAll(declarations)].flatMap(({ 0: match, index, groups }) => {
        if (groups.inline) return declaredColors(groups.inline.slice(1, -1));
        if (!groups.property && !groups.setProperty) return [];

        const value = source.slice(index + match.length).match(styleValue);
        return value ? Object.values(value.groups).filter(Boolean).flatMap(namedColors) : [];
    });
}

// Match the specific brand artwork or content line, including when it lives in scoped CSS.
const contentColors = {
    // Provider marks and their prescribed sign-in buttons, and application/network logos.
    'Pages/Auth/Login.vue': [
        /^\s*@apply border-slate-300 bg-white text-slate-800 hover:bg-slate-50;/,
        /^\s*@apply border-transparent bg-\[#0866ff\] text-white hover:bg-\[#0657db\];/,
    ],
    'Components/ApplicationLogo.vue': [/stop-color=|(?:fill|stroke)="#fff"/],
    'Components/SocialProviderIcon.vue': [/\bfill=/],
    'Components/NetworkIcon.vue': [
        /^\s*:class="\[dim, brandColor \? 'text-white' : 'bg-accent-fill text-on-accent'\]"$/,
    ],
    // The authentication QR requires a white quiet zone.
    'Pages/Profile/Partials/TwoFactorForm.vue': [/^\s*background: white;/],
    // User-owned design defaults, swatches, canvas and output previews.
    'Pages/Campaigns/Index.vue': [/color: '#4f46e5'/],
    'Composables/composer/useComposerCampaigns.js': [/^const DEFAULT_CAMPAIGN_COLOR = '#4f46e5';$/],
    'Pages/Studio/Index.vue': [/const paletteText =|placeholder="#7c3aed, #111827, #ffffff"/],
    'Pages/Studio/Editor.vue': [/class="m-auto shrink-0 bg-white shadow/],
    'Pages/Studio/Review.vue': [/class="mx-auto h-auto max-w-full bg-white shadow-lg"/],
    'Components/Studio/CaptionsPanel.vue': [/:value="style\.(fill|stroke|background) \?\?/],
    'Components/Studio/Inspector.vue': [
        /const (solid|existing|shadow) =|:value="(?:\(item\.stroke|item\.stroke)|@change="patch\('(fill|shadow)'/,
    ],
    'Components/Studio/StudioChartsDialog.vue': [/placeholder="#/],
    'Components/Studio/PresentationDialog.vue': [/state\.value\.blackout|(?:aspect-video).*\bbg-black\b/],
    'Components/Sites/SiteSavedPreview.vue': [/class="mx-auto block h-\[28rem\] max-w-full border-0 bg-white"/],
    'Components/Composer/PreviewPanel.vue': [/class="max-h-72 w-full rounded bg-black object-contain"/],
    // The preview shows the SmartLink's published appearance and selected accent.
    'Components/SmartLinks/SmartLinkPreview.vue': [
        /:style="\{ (?:background|color): dark \?|class="mx-auto inline-flex h-20|class="my-1 border-slate-400"|class="block rounded-xl (?:bg-slate-900|px-3 py-2\.5 text-sm font-semibold text-white)/,
    ],
};

function fixedColors(line) {
    const classes = [...line.matchAll(utilities)].flatMap(([utility, arbitrary]) => {
        if (!arbitrary) return [utility];
        // Tailwind convierte los guiones bajos en espacios y conserva los de las variables y las URL.
        const value = withoutUrls(arbitrary).replace(/--[\w-]+|_/g, (part) => (part === '_' ? ' ' : part));
        return value.match(hexOrFunction) || namedColors(value).length ? [utility] : [];
    });
    return [...new Set([...classes, ...(withoutUrls(line).match(hexOrFunction) ?? []), ...declaredColors(line)])];
}

function uiFiles() {
    return readdirSync(js, { recursive: true })
        .map((file) => file.replaceAll('\\', '/'))
        .filter((file) => /\.(?:vue|js|ts)$/.test(file) && !file.startsWith('Studio/'))
        .sort();
}

for (const [source, color] of [
    ['background: `color-mix(in srgb, ${c} 12%, white)`', 'white'],
    ['border: 1px solid white', 'white'],
    ['box-shadow: 0 0 2px black', 'black'],
    ['color: slategray', 'slategray'],
    ['backgroundColor: "RebeccaPurple"', 'RebeccaPurple'],
    ["borderColor: on ? 'green' : 'gray'", 'green'],
    ["borderColor: on ? 'green' : 'gray'", 'gray'],
    ["backgroundColor: ['red', 'blue']", 'red'],
    ["backgroundColor: ['red', 'blue']", 'blue'],
    ["el.style.background = 'white'", 'white'],
    ["ctx.fillStyle = 'white'", 'white'],
    ["ctx.strokeStyle = 'green'", 'green'],
    ["el.style.setProperty('--x', 'white')", 'white'],
    [`:style="{ borderColor: on ? 'green' : 'gray' }"`, 'green'],
    ['background-image: linear-gradient(tomato, white)', 'tomato'],
    ['stroke="darkslategrey"', 'darkslategrey'],
    ['const TOTAL_COLOR = "tomato";', 'tomato'],
    ['class="focus:placeholder-slate-400"', 'placeholder-slate-400'],
    ['class="focus:ring-offset-white"', 'ring-offset-white'],
    ['class="divide-gray-200"', 'divide-gray-200'],
    ['class="from-red-500"', 'from-red-500'],
    ['class="via-blue-600"', 'via-blue-600'],
    ['class="to-white"', 'to-white'],
    ['class="hover:text-[red]"', 'text-[red]'],
    ['class="bg-[#fff]"', 'bg-[#fff]'],
    ['class="border-[color:oklch(70%_0.2_80)]"', 'border-[color:oklch(70%_0.2_80)]'],
]) {
    test(`detects ${source}`, () => assert.ok(fixedColors(source).includes(color), `Missing ${color}`));
}

test('allows semantic colors and non-color arbitrary values', () => {
    for (const source of [
        'class="text-accent-secondary-ink bg-transparent border-current fill-inherit"',
        'class="text-[11px] border-[3px] bg-[length:100%]"',
        'class="text-[color:var(--brand-red)] bg-[currentColor] from-[transparent] to-[inherit]"',
        'class="text-[var(--brand_red)] bg-[url(/img/red.svg)]"',
        'color: currentColor; background: transparent; border-color: inherit;',
        'backgroundColor: `color-mix(in srgb, ${c} 12%, rgb(var(--color-surface)))`',
        'fill="var(--brand-red)" stroke="currentColor"',
        'fill="url(#abc)"',
        'name: "AliceBlue", color: network.color',
    ]) {
        assert.deepEqual(fixedColors(source), [], source);
    }
});

test('allows interface text that mentions color names', () => {
    for (const source of [
        `const label = 'Elige red o white';`,
        `const label = "Ejemplo: borderColor: 'green'";`,
        `const label = "Ejemplo: el.style.setProperty('--x', 'white')";`,
        `const text = { label: on ? 'green' : 'gray', options: ['red', 'blue'] };`,
        `borderColor: palette.white, label: 'white'`,
        `<span title="Ejemplo: color: white">Elige green o gray</span>`,
        `<span>Ejemplo de código: color: white</span>`,
    ]) {
        assert.deepEqual(fixedColors(source), [], source);
    }
});

test('scans UI helpers and layouts outside the canvas engine', () => {
    const files = uiFiles();
    for (const file of [
        'Pages/Dashboard/Partials/format.js',
        'Layouts/AuthenticatedLayout.vue',
        'Composables/useNetworks.js',
        'lib/format.js',
    ]) {
        assert.ok(files.includes(file), `Missing ${file}`);
    }
    assert.ok(files.every((file) => !file.startsWith('Studio/')));
});

test('application UI uses semantic colors', () => {
    const findings = uiFiles().flatMap((file) => {
        const source = readFileSync(new URL(file, js), 'utf8');
        return source.split(/\r?\n/).flatMap((line, index) => {
            if (contentColors[file]?.some((allowed) => allowed.test(line))) return [];
            return fixedColors(line).map((color) => ({ file, line: index + 1, color }));
        });
    });
    const files = new Set(findings.map(({ file }) => file));
    const sample = findings.slice(0, 20).map(({ file, line, color }) => `${file}:${line} ${color}`);
    assert.equal(findings.length, 0, `${findings.length} fixed colors in ${files.size} files\n${sample.join('\n')}`);
});
