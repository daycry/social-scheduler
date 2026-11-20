import { computed, ref } from 'vue';
import { createHistory } from '../history.js';

/** Historial de edición local. El editor aplica la instantánea a su documento y al lienzo. */
export function useStudioHistory({ snapshot, applySnapshot }) {
    const history = createHistory(snapshot());
    const version = ref(0);
    const canUndo = computed(() => { version.value; return history.canUndo; });
    const canRedo = computed(() => { version.value; return history.canRedo; });

    function commitHistory() {
        history.commit(snapshot());
        version.value++;
    }

    function resetHistory() {
        history.reset(snapshot());
        version.value++;
    }

    async function restore(mode) {
        const state = history[mode]();
        if (!state) return;
        version.value++;
        await applySnapshot(state);
    }

    return { canUndo, canRedo, commitHistory, resetHistory, restore };
}
