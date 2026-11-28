import { getCurrentScope, onScopeDispose, ref } from 'vue';
import { validateDocument } from '../document.js';
import { nextSave } from '../save-chain.js';

/** Serializa el autoguardado y el guardado explícito sobre la revisión que confirma el servidor. */
export function useStudioSave({
    initialDesign, snapshot, persist, notesError, error, beforeExport, closeExport, operationBlocked, isActive = () => true,
}) {
    const revision = ref(initialDesign.revision);
    const templateFields = ref(initialDesign.template_fields ?? []);
    const dirty = ref(false);
    const status = ref('Guardado');
    const conflict = ref(false);
    const automationBusy = ref(false);
    const automationMessage = ref('');
    let timer;
    let changeVersion = 0;
    let pendingSave = null;
    let pendingAutosave = false;
    let disposed = false;

    const isSaving = () => pendingSave !== null;
    const cancelAutosave = () => clearTimeout(timer);
    if (getCurrentScope()) {
        onScopeDispose(() => {
            disposed = true;
            cancelAutosave();
        });
    }

    function scheduleSave() {
        cancelAutosave();
        if (!disposed && isActive() && !conflict.value) timer = setTimeout(() => { saveNow(true); }, 1200);
    }

    function markDirty(message = conflict.value ? 'Conflicto de revisión' : 'Cambios pendientes') {
        if (disposed || !isActive()) return;
        changeVersion++;
        dirty.value = true;
        status.value = message;
        scheduleSave();
    }

    // El servidor funde el autoguardado con la revisión reciente del mismo autor.
    async function saveNow(autosave = false, force = false) {
        cancelAutosave();
        if (disposed || !isActive()) return false;
        if (notesError.value) { error.value = notesError.value; return false; }
        if (conflict.value) return false;
        if (pendingSave) {
            const inflight = pendingAutosave;
            const success = await pendingSave;
            if (!success || !isActive() || conflict.value) return false;
            const next = nextSave({ requested: autosave === true, inflight, dirty: dirty.value });
            return next.send ? saveNow(next.autosave, next.force) : true;
        }
        if (!dirty.value && !force) return true;

        const version = changeVersion;
        const saved = snapshot();
        status.value = 'Guardando…';
        pendingAutosave = autosave === true;
        pendingSave = (async () => {
            try {
                const result = await persist({
                    name: saved.name.trim() || 'Diseño sin título',
                    document: validateDocument(saved.document),
                    revision: revision.value,
                    is_template: saved.is_template,
                    kit_revision: saved.kit_revision,
                    autosave: autosave === true,
                });
                if (disposed || !isActive()) return false;
                revision.value = result.design.revision;
                templateFields.value = result.design.template_fields ?? [];
                if (version === changeVersion) {
                    dirty.value = false;
                    status.value = 'Guardado';
                } else {
                    status.value = 'Cambios pendientes';
                    scheduleSave();
                }
                return true;
            } catch (e) {
                if (disposed || !isActive()) return false;
                if (e.status === 409) {
                    markConflict();
                } else {
                    status.value = 'No se ha podido guardar';
                }
                error.value = e.message;
                return false;
            }
        })().finally(() => { pendingSave = null; });

        const success = await pendingSave;
        if (success) {
            const next = nextSave({ requested: autosave === true, inflight: autosave === true, dirty: dirty.value });
            if (next.send) return saveNow(next.autosave, next.force);
        }
        return success;
    }

    function markConflict() {
        conflict.value = true;
        status.value = 'Conflicto de revisión';
    }

    function onExportConflict(message) {
        if (disposed || !isActive()) return;
        markConflict();
        error.value = message;
        closeExport();
    }

    async function prepareExport() {
        if (disposed || !isActive()) throw new Error('El diseño ya no está abierto.');
        beforeExport();
        if (!await saveNow()) throw new Error('Guarda o resuelve el conflicto del diseño antes de exportar.');
        return { ...snapshot(), revision: revision.value };
    }

    async function savedOperation(operation, targetError = error) {
        if (disposed || !isActive() || automationBusy.value || operationBlocked() || conflict.value) return;
        automationBusy.value = true;
        targetError.value = '';
        automationMessage.value = '';
        try {
            await prepareExport();
            if (!disposed && isActive()) return await operation();
        } catch (e) {
            targetError.value = e.message;
            if (e.status === 409) markConflict();
        } finally {
            automationBusy.value = false;
        }
    }

    return {
        revision, templateFields, dirty, status, conflict, automationBusy, automationMessage,
        markDirty, saveNow, isSaving, onExportConflict, prepareExport, savedOperation,
    };
}
