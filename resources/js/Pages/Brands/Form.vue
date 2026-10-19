<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ brand: Object });

const form = useForm({
    name: props.brand?.name ?? '',
    description: props.brand?.description ?? '',
    timezone: props.brand?.timezone ?? 'Europe/Madrid',
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
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ brand ? 'Editar marca' : 'Nueva marca' }}
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl bg-white p-6 shadow-sm sm:rounded-lg">
                <form class="space-y-6" @submit.prevent="submit">
                    <div>
                        <InputLabel for="name" value="Nombre" />
                        <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>
                    <div>
                        <InputLabel for="description" value="Descripcion" />
                        <TextInput id="description" v-model="form.description" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.description" />
                    </div>
                    <div>
                        <InputLabel for="timezone" value="Zona horaria" />
                        <TextInput id="timezone" v-model="form.timezone" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.timezone" />
                    </div>
                    <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
