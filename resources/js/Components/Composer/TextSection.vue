<script setup>
import { ref } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextEditor from '@/Components/Composer/TextEditor.vue';
import EditorToolbar from '@/Components/Composer/EditorToolbar.vue';
import NetworkCounters from '@/Components/Composer/NetworkCounters.vue';
import { useComposerContext } from '@/Composables/useComposer';

const { form, editable, charCount } = useComposerContext();
const editor = ref(null);
</script>

<template>
    <!-- Texto -->
    <section class="card p-4">
        <InputLabel for="text" value="Texto" />
        <TextEditor ref="editor" v-model="form.text" :disabled="!editable" />
        <p class="text-right text-xs text-slate-500">{{ charCount }} caracteres</p>
        <EditorToolbar @emoji="(c) => editor?.insertAtCursor(c)" />
        <NetworkCounters />
    </section>
</template>
