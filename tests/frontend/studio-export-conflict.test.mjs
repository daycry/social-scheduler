import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { exportFailure } from '../../resources/js/Studio/export-conflict.js';

// A 409 while exporting or using the design in a post means the design moved on since it was opened: the editor opens the
// existing conflict dialog (reload / save as copy) instead of showing a generic error.
test('a 409 is a design conflict and keeps the server message', () => {
    const failure = exportFailure(Object.assign(new Error('El diseño ha cambiado desde que lo abriste'), { status: 409 }));
    assert.deepEqual(failure, { conflict: true, message: 'El diseño ha cambiado desde que lo abriste' });
});

test('other failures stay plain errors', () => {
    assert.deepEqual(exportFailure(Object.assign(new Error('boom'), { status: 422 })), { conflict: false, message: 'boom' });
    assert.deepEqual(exportFailure(new Error('')), { conflict: false, message: 'No se pudo completar la exportación.' });
    assert.deepEqual(exportFailure(null), { conflict: false, message: 'No se pudo completar la exportación.' });
});

test('the dialogs and the editor route the conflict to the existing conflict dialog', () => {
    const read = path => readFileSync(new URL(path, import.meta.url), 'utf8');
    for (const file of ['../../resources/js/Components/Studio/ExportDialog.vue', '../../resources/js/Components/Studio/NativeExportJobs.vue']) {
        const source = read(file);
        assert.match(source, /exportFailure\(/, file);
        assert.match(source, /emit\('conflict'/, file);
    }
    const editor = read('../../resources/js/Pages/Studio/Editor.vue');
    assert.match(editor, /@conflict="onExportConflict"/);
    assert.match(editor, /function onExportConflict\(/);
});
