<script setup>
import FormField from '@/Components/FormField.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { themeKey } from '@/Composables/useTheme';
import { useForm } from '@inertiajs/vue3';
import { computed, inject, watch } from 'vue';

const theme = inject(themeKey);
if (!theme) throw new Error('Falta el tema de la aplicación.');

const { isGuest, preference, setGuestPreference } = theme;
const form = useForm({ theme: preference.value });
watch(preference, (value) => form.defaults('theme', value).reset('theme'));

const selection = computed({
    get: () => (isGuest.value ? preference.value : form.theme),
    set: (value) => {
        if (isGuest.value) setGuestPreference(value);
        else form.theme = value;
    },
});

function save() {
    if (!isGuest.value && form.isDirty && !form.processing) {
        form.patch(route('profile.theme'), { preserveScroll: true });
    }
}
</script>

<template>
    <form class="space-y-3" @submit.prevent="save">
        <FormField :error="form.errors.theme" v-slot="{ id, control }">
            <InputLabel :for="id" value="Tema" />
            <select v-bind="control" v-model="selection" class="field mt-1" :disabled="form.processing">
                <option value="system">Sistema</option>
                <option value="light">Claro</option>
                <option value="dark">Oscuro</option>
            </select>
        </FormField>
        <div v-if="!isGuest" class="flex flex-wrap items-center gap-2">
            <button type="submit" class="btn-secondary" :disabled="form.processing || !form.isDirty"
                >Guardar tema</button
            >
        </div>
    </form>
</template>
