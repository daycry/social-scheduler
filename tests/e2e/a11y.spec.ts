import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { AUTH_FILE, AUTH_PAGES, PUBLIC_PAGES, settle } from './helpers';

/** (4) Accesibilidad: ninguna violación `critical` ni `serious` (reglas WCAG 2 A/AA). */
async function axe(page: Page, path: string) {
    await page.goto(path);
    await settle(page);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const bad = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    const detail = bad.map((v) => `${v.id} [${v.impact}] ${v.help}\n   ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join('\n   ')}`).join('\n');
    expect(bad, `violaciones de accesibilidad en ${path}:\n${detail}`).toEqual([]);
}

test.describe('axe · públicas', () => {
    for (const [name, path] of PUBLIC_PAGES) test(name, async ({ page }) => axe(page, path));
});

test.describe('axe · autenticadas', () => {
    test.use({ storageState: AUTH_FILE });
    for (const [name, path] of AUTH_PAGES) test(name, async ({ page }) => axe(page, path));
});
