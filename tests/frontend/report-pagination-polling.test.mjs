import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { babelParse, parse } from '@vue/compiler-sfc';

const filename = new URL('../../resources/js/Components/Reports/ReportsRecent.vue', import.meta.url);
const script = parse(readFileSync(filename, 'utf8')).descriptor.scriptSetup.content;
const handler = babelParse(script, { sourceType: 'module' }).program.body
    .find(node => node.type === 'FunctionDeclaration' && node.id.name === 'refreshReports');

test('report polling refreshes rows and their cursor and total in the same visit', async () => {
    let fields;
    const router = { reload(options) {
        fields = options.only;
        options.onFinish();
    } };
    // Only the router is injected. Exercise the polling callback and its onFinish hook.
    assert.ok(handler, 'ReportsRecent must define the polling callback');
    const refresh = new Function('router', 'return ' + script.slice(handler.start, handler.end))(router);
    await refresh(new AbortController().signal);
    assert.deepEqual(new Set(fields), new Set(['reports', 'polling', 'pagination']));
});
