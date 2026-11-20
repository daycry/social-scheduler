import test from 'node:test';
import assert from 'node:assert/strict';
import { effectScope, ref } from 'vue';
import { useEditorLibrary } from '../../resources/js/Studio/composables/useEditorLibrary.js';

function fixture(t, options) {
    const busy = ref(true);
    const active = ref(true);
    const brand = ref(4);
    const error = ref('');
    const scope = effectScope();
    t.after(() => scope.stop());
    const library = scope.run(() =>
        useEditorLibrary(
            {
                initialAssets: [],
                initialFonts: [],
                brandId: () => brand.value,
                busy,
                error,
                leftPanel: ref('assets'),
                getAdapter: () => null,
                run: (action) => action(),
                isActive: () => active.value,
            },
            options,
        ),
    );
    return { library, busy, active, brand, error };
}

const input = (name, type = 'image/png') => ({ target: { files: [{ name, type }], value: name } });

test('a file selected during canvas work is uploaded once when the editor is free', async (t) => {
    const calls = [];
    const { library, busy } = fixture(t, {
        uploadFile: async (file) => {
            calls.push(['upload', file.name]);
            return { id: 8 };
        },
        attachAsset: async (brandId, assetId) => {
            calls.push(['attach', brandId, assetId]);
            return { id: assetId };
        },
    });
    await library.upload(input('next.png'));
    assert.deepEqual(calls, []);
    busy.value = false;
    await new Promise(setImmediate);
    assert.deepEqual(calls, [
        ['upload', 'next.png'],
        ['attach', 4, 8],
    ]);
    assert.deepEqual(library.localAssets.value, [{ id: 8 }]);
    assert.equal(busy.value, false);
});

test('closing the editor during upload drops the queued selection and skips attachment', async (t) => {
    let acknowledge;
    const uploaded = new Promise((resolve) => {
        acknowledge = resolve;
    });
    const calls = [];
    const { library, busy, active } = fixture(t, {
        uploadFile: (file) => {
            calls.push(['upload', file.name]);
            return uploaded;
        },
        attachAsset: () => {
            calls.push(['attach']);
        },
    });
    busy.value = false;
    const first = library.upload(input('first.png'));
    await library.upload(input('later.png'));
    active.value = false;
    acknowledge({ id: 9 });
    await first;
    await new Promise(setImmediate);
    assert.deepEqual(calls, [['upload', 'first.png']]);
    assert.deepEqual(library.localAssets.value, []);
    assert.equal(busy.value, false);
    assert.equal(library.uploadStatus.value, '');
});

test('video and audio selections both complete in selection order', async (t) => {
    let finishVideo;
    const calls = [];
    const { library, busy } = fixture(t, {
        uploadFile: (file) => {
            calls.push(['video', file.name]);
            return new Promise((resolve) => {
                finishVideo = resolve;
            });
        },
        attachAsset: async (brand, id) => {
            calls.push(['attach', brand, id]);
            return { id };
        },
        uploadAudio: async (brand, file) => {
            calls.push(['audio', brand, file.name]);
            return { id: 9 };
        },
    });
    busy.value = false;
    const video = library.upload(input('own-video.mp4', 'video/mp4'));
    await library.upload(input('own-audio.wav', 'audio/wav'));
    assert.deepEqual(calls, [['video', 'own-video.mp4']]);
    assert.match(library.uploadStatus.value, /pendientes/);
    finishVideo({ id: 8 });
    await video;
    await new Promise(setImmediate);
    assert.deepEqual(calls, [
        ['video', 'own-video.mp4'],
        ['attach', 4, 8],
        ['audio', 4, 'own-audio.wav'],
    ]);
    assert.deepEqual(library.localAssets.value, [{ id: 9 }, { id: 8 }]);
});

test('a failed queued selection reports its error and the next selection still uploads', async (t) => {
    const uploaded = [];
    const { library, busy, error } = fixture(t, {
        uploadFile: async (file) => {
            if (file.name === 'broken.png') throw Error('No se pudo subir el recurso');
            uploaded.push(file.name);
            return { id: 10 };
        },
        attachAsset: async (_brand, id) => ({ id }),
    });
    await library.upload(input('broken.png'));
    await library.upload(input('good.png'));
    busy.value = false;
    await new Promise(setImmediate);
    assert.equal(error.value, 'No se pudo subir el recurso');
    assert.deepEqual(uploaded, ['good.png']);
    assert.deepEqual(library.localAssets.value, [{ id: 10 }]);
});

test('queued selections from a previous brand or inactive context never upload', async (t) => {
    const uploaded = [];
    const { library, busy, active, brand } = fixture(t, {
        uploadFile: async (file) => {
            uploaded.push(file.name);
            return { id: 10 };
        },
        attachAsset: async (_brand, id) => ({ id }),
    });
    await library.upload(input('old-brand.png'));
    brand.value = 5;
    await library.upload(input('old-view.png'));
    active.value = false;
    active.value = true;
    await library.upload(input('current.png'));
    busy.value = false;
    await new Promise(setImmediate);
    assert.deepEqual(uploaded, ['current.png']);
    assert.deepEqual(library.localAssets.value, [{ id: 10 }]);
});

test('recoverable audio failures retain both tickets and retry them in order', async (t) => {
    const retried = [];
    const firstTicket = { id: 'first-audio' };
    const secondTicket = { id: 'second-audio' };
    const { library, busy } = fixture(t, {
        uploadAudio: async (_brand, file) => {
            const ticket = file.name === 'first.wav' ? firstTicket : secondTicket;
            throw Object.assign(Error('Validación de audio pendiente'), { audioTicket: ticket });
        },
        completeAudio: async (ticket) => {
            retried.push(ticket.id);
            return { id: ticket.id };
        },
    });
    busy.value = false;
    await library.upload(input('first.wav', 'audio/wav'));
    await library.upload(input('second.wav', 'audio/wav'));
    assert.deepEqual(library.pendingAudio.value, firstTicket);
    await library.retryAudioValidation();
    assert.deepEqual(library.pendingAudio.value, secondTicket);
    await library.retryAudioValidation();
    assert.equal(library.pendingAudio.value, null);
    assert.deepEqual(retried, ['first-audio', 'second-audio']);
    assert.equal(busy.value, false);
});
