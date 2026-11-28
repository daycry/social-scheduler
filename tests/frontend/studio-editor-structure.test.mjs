import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse } from '@babel/parser';
import { parse as parseVue } from '@vue/compiler-sfc';
import test from 'node:test';

const editorPath = new URL('../../resources/js/Pages/Studio/Editor.vue', import.meta.url);

test('the Studio editor page delegates workflows and has fewer than 40 top-level implementation declarations', () => {
    const { descriptor } = parseVue(readFileSync(editorPath, 'utf8'));
    const { program } = parse(descriptor.scriptSetup.content, { sourceType: 'module' });
    // Count each top-level binding declaration once, including destructured composable results.
    const declarations = program.body.reduce((count, node) => {
        if (node.type === 'VariableDeclaration') return count + node.declarations.length;
        if (node.type === 'FunctionDeclaration' || node.type === 'ClassDeclaration') return count + 1;
        return count;
    }, 0);
    assert.ok(declarations < 40, `Editor.vue has ${declarations} implementation declarations`);
});
