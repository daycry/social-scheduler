<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { useComposerContext } from '@/Composables/useComposer';

const { post, form, editable, submit, remove } = useComposerContext();
</script>

<template>
    <p v-if="form.errors.post" class="text-sm text-red-600" role="alert">{{ form.errors.post }}</p>

    <div v-if="editable" class="flex items-center gap-3">
        <PrimaryButton type="submit" :disabled="form.processing">Programar</PrimaryButton>
        <SecondaryButton type="button" :disabled="form.processing" @click="submit(false)">Guardar borrador</SecondaryButton>
        <DangerButton v-if="post" type="button" class="ml-auto" @click="remove">Eliminar</DangerButton>
    </div>
</template>
