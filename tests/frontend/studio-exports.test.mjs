import test from 'node:test';
import assert from 'node:assert/strict';
import { blankDocument } from '../../resources/js/Studio/document.js';
import { exportPage } from '../../resources/js/Studio/exports.js';

test('bitmap exports reject oversized sides before rendering or uploading', async () => {
    const document = blankDocument(8000, 40);
    await assert.rejects(exportPage(document, document.pages[0].id, [], { format: 'png', scale: 3 }), /16000/);
});
