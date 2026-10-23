import { defineConfig } from '@playwright/test';
import dotenv from 'dotenv';

// Credenciales del usuario demo (DEMO_USER_*) del .env; nunca se versionan ni se imprimen.
dotenv.config({ path: '.env' });
// dotenv no expande `${VAR}` y PHP no pisa variables que ya estan en el entorno: se expanden aqui para que el servidor
// E2E y tinker vean lo mismo que con el .env (p. ej. MAIL_FROM_NAME="${APP_NAME}").
for (const [key, value] of Object.entries(process.env)) {
    if (value?.includes('${')) {
        process.env[key] = value.replace(/\$\{(\w+)\}/g, (_, name: string) => process.env[name] ?? '');
    }
}

const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:8080';
const { hostname, port } = new URL(baseURL);
// WebAuthn no admite una IP como RP ID y el servidor necesita un puerto explicito: mejor un error claro que fallos raros.
if (/^[\d.]+$|^\[/.test(hostname)) {
    throw new Error(`E2E_BASE_URL (${baseURL}) usa una IP: WebAuthn exige un dominio como RP ID; usa http://localhost:<puerto>.`);
}
if (!process.env.E2E_NO_SERVER && !port) {
    throw new Error(`E2E_BASE_URL (${baseURL}) necesita un puerto explicito para el servidor de pruebas.`);
}

/**
 * Batería E2E (requiere `npm run build`). Playwright arranca solo el servidor de pruebas (`webServer`) en localhost:8080
 * con variables DEL PROCESO (el .env real no se toca): WebAuthn no admite una IP como RP ID, así que el RP ID es
 * `localhost` y el origen `http://localhost:8080`; `McpGuard` acepta ese Origin porque coincide con APP_URL.
 *   npm run test:e2e
 *   E2E_NO_SERVER=1 npm run test:e2e   # el servidor ya está en marcha (arrancado a mano con las mismas variables)
 *   E2E_JSON_REPORT=ruta/results.json npm run test:e2e   # además escribe el informe json
 */
export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    globalTeardown: './tests/e2e/global-teardown.ts',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: process.env.E2E_JSON_REPORT ? [['list'], ['json', { outputFile: process.env.E2E_JSON_REPORT }]] : [['list']],
    webServer: process.env.E2E_NO_SERVER
        ? undefined
        : {
              // --no-reload: sin él `artisan serve` descarta las variables del proceso en cuanto existe un .env.
              command: `php artisan serve --host=${new URL(baseURL).hostname} --port=${port} --no-reload`,
              url: `${new URL(baseURL).origin}/login`,
              reuseExistingServer: false,
              timeout: 60_000,
              env: {
                  // Proveedor social FALSO (T-23): solo se registra con APP_ENV=e2e y un APP_URL local (FakeSocialiteProvider).
                  // Las credenciales son inventadas: activan los botones sin tocar las reales del .env.
                  APP_ENV: 'e2e',
                  // Tope GLOBAL de registros DCR/hora: la batería registra muchos clientes y no reinicia el contador (helpers.ts).
                  MCP_OAUTH_REGISTER_GLOBAL_PER_HOUR: '100000',
                  GOOGLE_LOGIN_CLIENT_ID: 'e2e-google-id',
                  GOOGLE_LOGIN_CLIENT_SECRET: 'e2e-google-secret',
                  META_LOGIN_APP_ID: 'e2e-meta-id',
                  META_LOGIN_APP_SECRET: 'e2e-meta-secret',
                  APP_URL: new URL(baseURL).origin,
                  WEBAUTHN_RP_ID: new URL(baseURL).hostname,
                  WEBAUTHN_ORIGINS: new URL(baseURL).origin,
                  SESSION_SECURE_COOKIE: 'false',
                  TRUSTED_PROXIES: '',
              },
          },
    use: {
        baseURL,
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 900 } } },
        { name: 'mobile', use: { browserName: 'chromium', viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } },
        // Movil estrecho (360 x 740: gama media de Android): mismo recorrido, 0 px de desbordamiento horizontal.
        { name: 'mobile-360', use: { browserName: 'chromium', viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true } },
    ],
    outputDir: 'test-results',
});
