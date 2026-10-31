import { execFileSync } from 'node:child_process';
import { expect, type Page, type TestInfo } from '@playwright/test';

export type User = { id: number; email: string; password: string };
export type Seed = { brandId: number; brandName: string; accounts: Record<string, number>; imageId: number };
export function fixture<T = any>(action: string, project: string, args: Record<string, unknown> = {}): T {
    const output = execFileSync('php', ['tests/e2e/roadmap/cli.php', action, Buffer.from(JSON.stringify({ project, ...args })).toString('base64')], { encoding: 'utf8', env: process.env, timeout: 90_000 });
    return JSON.parse(output.trim());
}
export async function login(page: Page, user: User) {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(user.email);
    await page.getByLabel('Contraseña', { exact: true }).fill(user.password);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await page.waitForURL(/\/dashboard/);
}
export async function shot(page: Page, info: TestInfo, name: string) {
    await page.screenshot({ path: info.outputPath(`${name}.png`), fullPage: true });
    await info.attach(name, { path: info.outputPath(`${name}.png`), contentType: 'image/png' });
}
export async function noOverflow(page: Page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
}
