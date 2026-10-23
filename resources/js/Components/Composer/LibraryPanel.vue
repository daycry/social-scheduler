<script setup>
import { useComposerContext } from '@/Composables/useComposer';

const { post, libraryName, savingToLibrary, saveToLibrary } = useComposerContext();
</script>

<template>
    <div v-if="post" class="card p-4" data-testid="save-library">
        <h2 class="mb-2 font-medium text-slate-900">Biblioteca</h2>
        <label class="block text-sm">
            <span class="block text-xs font-medium text-slate-600">Nombre de la plantilla</span>
            <input v-model="libraryName" type="text" maxlength="120" class="field" />
        </label>
        <button type="button" class="btn-secondary mt-2" :disabled="savingToLibrary || !libraryName.trim()" @click="saveToLibrary">Guardar en biblioteca</button>
    </div>
</template>
