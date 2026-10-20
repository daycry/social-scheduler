<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

/** Biblioteca (PA-06): plantillas reutilizables de texto + media + redes. «Usar» crea un borrador y abre el editor. */
const props = defineProps({
    brand: Object,
    templates: Array,
    flash: Object,
});

const form = useForm({ name: '', text: '', brand_id: props.brand?.id ?? '' });

const submit = () => form.post(route('library.store'), { preserveScroll: true, onSuccess: () => form.reset('name', 'text') });
const use = (t) => router.post(route('library.use', t.id));
const remove = (t) => {
    if (confirm(`¿Eliminar la plantilla «${t.name}»?`)) router.delete(route('library.destroy', t.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Biblioteca" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Biblioteca</h2>
        </template>

        <div class="space-y-5">
            <p v-if="flash?.success" class="text-sm text-emerald-700" role="status">{{ flash.success }}</p>
            <p v-if="flash?.error" class="text-sm text-red-600" role="alert">{{ flash.error }}</p>

            <form v-if="brand" class="card space-y-3 p-5" @submit.prevent="submit">
                <h3 class="text-sm font-medium text-slate-700">Nueva plantilla de texto</h3>
                <label class="block text-sm">
                    <span class="block text-xs font-medium text-slate-500">Nombre</span>
                    <input v-model="form.name" type="text" maxlength="120" required class="field" />
                    <InputError :message="form.errors.name" />
                </label>
                <label class="block text-sm">
                    <span class="block text-xs font-medium text-slate-500">Texto</span>
                    <textarea v-model="form.text" rows="3" maxlength="10000" class="field"></textarea>
                    <InputError :message="form.errors.text" />
                </label>
                <InputError :message="form.errors.brand_id" />
                <PrimaryButton :disabled="form.processing">Guardar plantilla</PrimaryButton>
                <p class="text-xs text-slate-500">También puedes guardar una publicación ya creada desde su editor o calendario.</p>
            </form>

            <section class="card">
                <h3 class="border-b px-5 py-3 text-sm font-medium text-slate-700">Plantillas guardadas</h3>
                <p v-if="templates.length === 0" class="p-5 text-sm text-slate-500">Aún no tienes plantillas.</p>
                <ul v-else class="divide-y">
                    <li v-for="t in templates" :key="t.id" class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 text-sm">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900">{{ t.name }}</p>
                            <p v-if="t.text" class="line-clamp-2 text-slate-600">{{ t.text }}</p>
                            <p class="text-xs text-slate-500">{{ t.brand }} · {{ t.media.length }} archivos · {{ t.targets }} redes</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="button" class="btn btn-secondary" @click="use(t)">Usar</button>
                            <button type="button" class="text-xs text-red-600 underline" @click="remove(t)">Eliminar</button>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
