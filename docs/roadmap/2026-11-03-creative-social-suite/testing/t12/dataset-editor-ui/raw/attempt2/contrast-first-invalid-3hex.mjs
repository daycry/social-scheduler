import colors from 'tailwindcss/colors.js';
import defaultTheme from 'tailwindcss/defaultTheme.js';
import { writeFileSync } from 'node:fs';
const luminance = color => {
    const channels = color.slice(1).match(/../g).map(value => parseInt(value, 16) / 255).map(value => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4);
    return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
};
const contrast = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);
const backgrounds = { white: colors.white, violet50: colors.violet[50] };
const result = { method: 'WCAG relative sRGB luminance, installed Tailwind colors; static, not rendered axe acceptance',
    before: { color: colors.slate[400], sizePx: 9, ratios: Object.fromEntries(Object.entries(backgrounds).map(([name, color]) => [name, contrast(colors.slate[400], color)])) },
    after: { color: colors.slate[600], fontSize: defaultTheme.fontSize.xs, nominalDefaultRootSizePx: 12, ratios: Object.fromEntries(Object.entries(backgrounds).map(([name, color]) => [name, contrast(colors.slate[600], color)])) }, backgrounds,
    threshold: 4.5, pass: Object.values(backgrounds).every(color => contrast(colors.slate[600], color) >= 4.5) };
writeFileSync(new URL('./contrast.json', import.meta.url), JSON.stringify(result, null, 2) + '\n');
console.log(JSON.stringify(result));
if (!result.pass) process.exitCode = 1;
