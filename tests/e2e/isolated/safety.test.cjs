const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

test('default harness never loads real environment or starts unisolated artisan', () => {
    const config = fs.readFileSync('playwright.config.ts', 'utf8');
    assert.doesNotMatch(config, /dotenv|php artisan serve/, 'The default runner can reach the real environment');
    const helpers = fs.readFileSync('tests/e2e/helpers.ts', 'utf8');
    assert.doesNotMatch(helpers, /process\.env\.DEMO_USER_|\['artisan', 'tinker'/, 'Helpers can reach a real identity/database');
    assert.doesNotMatch(helpers, /tests\/e2e\/\.auth\/|storage\/framework\/cache\/e2e-social/, 'Auth/fake state must belong to its disposable run');
});
