import { ref, shallowRef, watch } from 'vue';
import { cloneDocument, findPage, validateDocument } from '../document.js';

export function usePresentation({
    page,
    documentData,
    error,
    isDisposed,
    invalidatePreview,
    suspendPlayback,
    waitForPreviewCreation,
    waitForPreviewCleanup,
    saveNow,
    loadPresentationDialog,
    patchPage,
}) {
    const presentationOpen = ref(false),
        presentationOpening = ref(false),
        presentationDocument = shallowRef(null),
        presentationDialog = ref(null);
    const notesValue = ref(page.value.notes ?? ''),
        notesError = ref('');
    const syncNotes = () => {
        notesValue.value = page.value.notes ?? '';
        notesError.value = '';
    };
    watch(() => page.value.id, syncNotes, { flush: 'sync' });
    watch(() => page.value.notes, syncNotes, { flush: 'sync' });
    let presentationGeneration = 0;
    function closePresentation() {
        ++presentationGeneration;
        presentationDialog.value?.close();
        presentationOpen.value = false;
        presentationOpening.value = false;
    }
    async function openPresentation() {
        if (presentationOpening.value || presentationOpen.value) return;
        const token = ++presentationGeneration;
        presentationOpening.value = true;
        error.value = '';
        invalidatePreview();
        try {
            await suspendPlayback();
            if (isDisposed() || token !== presentationGeneration) return;
            await waitForPreviewCreation();
            if (isDisposed() || token !== presentationGeneration) return;
            await waitForPreviewCleanup();
            if (isDisposed() || token !== presentationGeneration) return;
            if (!(await saveNow()))
                throw new Error(notesError.value || 'Guarda o resuelve el conflicto antes de presentar.');
            if (isDisposed() || token !== presentationGeneration) return;
            try {
                await loadPresentationDialog();
            } catch {
                throw new Error('No se pudo cargar la presentación. Recarga la página.');
            }
            if (isDisposed() || token !== presentationGeneration) return;
            presentationDocument.value = cloneDocument(documentData.value);
            presentationOpen.value = true;
        } catch (cause) {
            if (!isDisposed() && token === presentationGeneration) error.value = cause.message;
        } finally {
            if (token === presentationGeneration) presentationOpening.value = false;
        }
    }

    function patchNotes(event, ownerId) {
        const value = event.target.value;
        notesValue.value = value;
        try {
            if (Array.from(value).length > 20000)
                throw new Error(
                    'Las notas admiten hasta 20.000 caracteres Unicode. Reduce el texto antes de guardar o cambiar de página.',
                );
            const next = cloneDocument(documentData.value);
            Object.assign(findPage(next, ownerId), { notes: value });
            validateDocument(next);
            notesError.value = '';
            patchPage({ notes: value }, ownerId);
        } catch (cause) {
            notesError.value = cause.message;
            error.value = cause.message;
        }
    }

    return {
        presentationOpen,
        presentationOpening,
        presentationDocument,
        presentationDialog,
        notesValue,
        notesError,
        openPresentation,
        closePresentation,
        patchNotes,
    };
}
