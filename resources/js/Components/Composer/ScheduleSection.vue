<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import DateTimePicker from '@/Components/Composer/DateTimePicker.vue';
import { useComposerContext } from '@/Composables/useComposer';

const { brand, form, editable } = useComposerContext();
</script>

<template>
    <!-- Fecha -->
    <section class="card p-4">
        <InputLabel for="scheduled_at" :value="`Fecha y hora (${brand.timezone})`" />
        <DateTimePicker id="scheduled_at" v-model="form.scheduled_at" :timezone="brand.timezone" :disabled="!editable" />
    </section>
</template>
