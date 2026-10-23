<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import { useComposerContext } from '@/Composables/useComposer';

const { brand, form, editable } = useComposerContext();
</script>

<template>
    <!-- Fecha -->
    <section class="card p-4">
        <InputLabel for="scheduled_at" :value="`Fecha y hora (${brand.timezone})`" />
        <input id="scheduled_at" v-model="form.scheduled_at" :disabled="!editable" type="datetime-local" class="field mt-1 w-auto" />
        <p v-if="form.errors.scheduled_at" class="mt-1 text-sm text-red-600">{{ form.errors.scheduled_at }}</p>
    </section>
</template>
