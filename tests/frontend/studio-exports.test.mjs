import test from 'node:test';
import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import { blankDocument, StudioDocumentError } from '../../resources/js/Studio/document.js';
import { exportPage, exportPdf } from '../../resources/js/Studio/exports.js';

test('bitmap exports reject oversized sides before rendering or uploading', async () => {
    const document = blankDocument(8000, 40);
    await assert.rejects(exportPage(document, document.pages[0].id, [], { format: 'png', scale: 3 }), /16000/);
});


test('PDF validates the request first and reports a readable error when its chunk fails to load', async (t) => {
    const parentURL = new URL('../../resources/js/Studio/exports.js', import.meta.url).href;
    const cause = new Error('Could not fetch PDF chunk');
    let attempts = 0;
    const hook = registerHooks({
        resolve(specifier, context, nextResolve) {
            if (specifier === 'pdf-lib' && context.parentURL === parentURL) {
                attempts++;
                throw cause;
            }
            return nextResolve(specifier, context);
        },
    });
    t.after(() => hook.deregister());

    await assert.rejects(exportPdf({}, []), StudioDocumentError);
    assert.equal(attempts, 0);
    const document = blankDocument(40, 40);
    await assert.rejects(exportPdf(document, [document.pages[0].id]), error =>
        error instanceof StudioDocumentError && /No se pudo cargar el generador de PDF/.test(error.message) && error.cause === cause);
    assert.equal(attempts, 1);
});
