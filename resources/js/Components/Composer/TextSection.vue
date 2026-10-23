<script setup>
import { ref } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import NetworkTabs from '@/Components/Composer/NetworkTabs.vue';
import EditorToolbar from '@/Components/Composer/EditorToolbar.vue';
import NetworkCounters from '@/Components/Composer/NetworkCounters.vue';

const editor = ref(null);
</script>

<template>
    <!-- Texto: el texto común y, con «Editar por red», una pestaña por red (NetworkTabs) -->
    <section class="card min-w-0 p-4">
        <InputLabel for="text" value="Texto" />
        <NetworkTabs ref="editor" />
        <EditorToolbar @emoji="(c) => editor?.insertAtCursor(c)" @insert="(t) => editor?.insertAtCursor(t)" />
        <NetworkCounters />
    </section>
</template>
