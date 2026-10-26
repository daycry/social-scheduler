<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import NetworkChip from '@/Components/NetworkChip.vue';
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

/** Biblioteca (PA-06): plantillas reutilizables de texto + media + redes. «Usar» crea un borrador y abre el editor. */
const props = defineProps({
    brand: Object,
    templates: Array,
    flash: Object,
});

const form = useForm({ name: '', text: '', brand_id: props.brand?.id ?? '' });

const submit = () => form.post(route('library.store'), { preserveScroll: true, onSuccess: () => form.reset('name', 'text') });
// «Usar» crea el borrador en el servidor: si no se puede (plantilla ajena, datos que ya no valen), el motivo se muestra aquí.
const useError = ref('');
const use = (t) => {
    useError.value = '';
    router.post(route('library.use', t.id), {}, {
        onError: (errors) => {
            useError.value = Object.values(errors).flat()[0] || 'No se pudo crear el borrador desde la plantilla.';
        },
    });
};
const remove = (t) => {
    if (confirm(`¿Eliminar la plantilla «${t.name}»?`)) router.delete(route('library.destroy', t.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Biblioteca" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Biblioteca</h1>
        </template>

        <div class="space-y-5">
            <p v-if="flash?.success" class="text-sm text-emerald-700" role="status">{{ flash.success }}</p>
            <p v-if="flash?.error" class="text-sm text-red-600" role="alert">{{ flash.error }}</p>
            <p v-if="useError" class="text-sm text-red-600" role="alert" data-testid="library-use-error">{{ useError }}</p>

            <FeatureEmpty
                v-if="!brand"
                title="Crea una marca para usar la biblioteca"
                description="La biblioteca guarda plantillas de texto, archivos y redes para reutilizarlas en nuevas publicaciones."
                :cards="[
                    { title: 'Plantillas reutilizables', text: 'Guarda tus mejores textos y vuelve a publicarlos en un clic.', kind: 'report' },
                    { title: 'Archivos incluidos', text: 'Las plantillas conservan imágenes y vídeos de tu biblioteca de medios.', kind: 'link' },
                    { title: 'Borradores al instante', text: '«Usar» crea un borrador listo para programar.', kind: 'calendar' },
                ]"
            >
                <template #cta><Link :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link></template>
            </FeatureEmpty>

            <form v-else class="card space-y-3 p-5" @submit.prevent="submit">
                <h2 class="text-sm font-medium text-slate-700">Nueva plantilla de texto</h2>
                <label class="block text-sm">
                    <span class="block text-xs font-medium text-slate-600">Nombre</span>
                    <input v-model="form.name" type="text" maxlength="120" required class="field" />
                    <InputError :message="form.errors.name" />
                </label>
                <label class="block text-sm">
                    <span class="block text-xs font-medium text-slate-600">Texto</span>
                    <textarea v-model="form.text" rows="3" maxlength="10000" class="field"></textarea>
                    <InputError :message="form.errors.text" />
                </label>
                <InputError :message="form.errors.brand_id" />
                <PrimaryButton :disabled="form.processing">Guardar plantilla</PrimaryButton>
                <p class="text-xs text-slate-500">También puedes guardar una publicación ya creada desde su editor o calendario.</p>
            </form>

            <section v-if="brand" class="card">
                <h2 class="border-b px-5 py-3 text-sm font-medium text-slate-700">Plantillas guardadas</h2>
                <p v-if="templates.length === 0" class="p-5 text-sm text-slate-600">Aún no tienes plantillas. Crea una arriba o guarda una publicación desde su editor.</p>
                <ul v-else class="divide-y">
                    <li v-for="t in templates" :key="t.id" class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 text-sm">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900">{{ t.name }}</p>
                            <p v-if="t.text" class="line-clamp-2 text-slate-600">{{ t.text }}</p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <NetworkChip v-for="n in t.networks" :key="n" :network="n" />
                                <span class="text-xs text-slate-500">{{ t.brand }} · {{ t.media.length }} archivos</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="button" class="btn btn-secondary" @click="use(t)">Usar</button>
                            <button type="button" class="text-xs font-medium text-red-700 underline" @click="remove(t)">Eliminar</button>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
