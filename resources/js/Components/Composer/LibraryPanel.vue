<script setup>
import { useComposerContext } from '@/Composables/useComposer';

const { post, libraryName, savingToLibrary, saveToLibrary, canSaveToLibrary } = useComposerContext();
</script>

<template>
    <div v-if="post" class="card p-4" data-testid="save-library">
        <h2 class="mb-2 font-medium text-slate-900">Biblioteca</h2>
        <label class="block text-sm">
            <span class="block text-xs font-medium text-slate-600">Nombre de la plantilla</span>
            <input v-model="libraryName" type="text" maxlength="120" class="field" />
        </label>
        <button type="button" class="btn-secondary mt-2" :disabled="savingToLibrary || !libraryName.trim() || !canSaveToLibrary" aria-describedby="library-hint" @click="saveToLibrary">Guardar en biblioteca</button>
        <p v-if="!canSaveToLibrary" id="library-hint" class="mt-1 text-xs text-amber-700" data-testid="library-hint">Guarda los cambios (borrador) antes de guardar en la biblioteca: se guarda el texto ya guardado.</p>
    </div>
</template>
