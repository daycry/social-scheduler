<script setup>
import FormField from '@/Components/FormField.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ brand: Object, images: { type: Array, default: () => [] }, avatar_url: { type: String, default: null } });

const form = useForm({
    name: props.brand?.name ?? '',
    description: props.brand?.description ?? '',
    timezone: props.brand?.timezone ?? 'Europe/Madrid',
    avatar_media_id: props.brand?.avatar_media_id ?? null,
});

const submit = () => {
    if (props.brand) {
        form.put(route('brands.update', props.brand.id));
    } else {
        form.post(route('brands.store'));
    }
};
</script>

<template>
    <Head :title="brand ? 'Editar marca' : 'Nueva marca'" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">
                {{ brand ? 'Editar marca' : 'Nueva marca' }}
            </h1>
        </template>

        <div>
            <div class="max-w-xl card p-6">
                <form class="space-y-6" @submit.prevent="submit">
                    <div>
                        <FormField id="name" :error="form.errors.name" error-class="mt-2" v-slot="{ control }">
                            <InputLabel for="name" value="Nombre" />
                            <TextInput v-bind="control" v-model="form.name" class="mt-1 block w-full" required />
                        </FormField>
                    </div>
                    <div>
                        <FormField
                            id="description"
                            :error="form.errors.description"
                            error-class="mt-2"
                            v-slot="{ control }"
                        >
                            <InputLabel for="description" value="Descripción" />
                            <TextInput v-bind="control" v-model="form.description" class="mt-1 block w-full" />
                        </FormField>
                    </div>
                    <div>
                        <FormField id="timezone" :error="form.errors.timezone" error-class="mt-2" v-slot="{ control }">
                            <InputLabel for="timezone" value="Zona horaria" />
                            <TextInput v-bind="control" v-model="form.timezone" class="mt-1 block w-full" required />
                        </FormField>
                    </div>
                    <div>
                        <FormField
                            id="avatar_media_id"
                            :error="form.errors.avatar_media_id"
                            error-class="mt-2"
                            v-slot="{ control }"
                        >
                            <InputLabel for="avatar_media_id" value="Avatar (imagen de tu biblioteca de medios)" />
                            <div class="mt-1 flex items-center gap-3">
                                <img
                                    loading="lazy"
                                    v-if="avatar_url && form.avatar_media_id === brand?.avatar_media_id"
                                    :src="avatar_url"
                                    alt="Avatar actual de la marca"
                                    class="h-10 w-10 rounded-lg object-cover"
                                />
                                <select v-bind="control" v-model="form.avatar_media_id" class="field">
                                    <option :value="null">Sin avatar (inicial del nombre)</option>
                                    <option v-for="m in images" :key="m.id" :value="m.id">{{ m.name }}</option>
                                </select>
                            </div>
                            <p v-if="images.length === 0" class="mt-1 text-xs text-slate-600"
                                >Aún no has subido imágenes; súbelas desde el editor de publicaciones.</p
                            >
                        </FormField>
                    </div>
                    <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
