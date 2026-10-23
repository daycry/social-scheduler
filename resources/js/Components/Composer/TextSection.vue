<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import AiSuggest from '@/Components/AiSuggest.vue';
import { useComposerContext } from '@/Composables/useComposer';

const { brand, form, editable, charCount, selectedNetwork } = useComposerContext();
</script>

<template>
    <!-- Texto -->
    <section class="card p-4">
        <InputLabel for="text" value="Texto" />
        <textarea id="text" v-model="form.text" :disabled="!editable" rows="5" class="field mt-1"></textarea>
        <p class="text-right text-xs text-slate-500">{{ charCount }} caracteres</p>
        <AiSuggest v-if="editable" :brand-id="brand.id" :network="selectedNetwork" @use="(t) => (form.text = t)" />
    </section>
</template>
