<script setup>
import FormField from '@/Components/FormField.vue';
import Pagination from '@/Components/Pagination.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import NetworkChip from '@/Components/NetworkChip.vue';
import OpenStudioImage from '@/Components/OpenStudioImage.vue';
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

import { useConfirm } from '@/Composables/useConfirm';

const ask = useConfirm();

/** Plantillas de publicación (PA-06): plantillas reutilizables de texto + media + redes. «Usar» crea un borrador y abre el editor. */
const props = defineProps({
    pagination: Object,
    brand: Object,
    templates: Array,
    csrfToken: String,
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
const remove = async (t) => {
    if (await ask({ message: `¿Eliminar la plantilla «${t.name}»?`, confirmLabel: 'Eliminar plantilla' })) router.delete(route('library.destroy', t.id), { preserveScroll: true });
};
const readyImages = template => template.media.filter(asset => asset.kind === 'image' && asset.status === 'listo');
</script>

<template>
    <Head title="Plantillas de publicación" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-foreground-strong">Plantillas de publicación</h1>
        </template>

        <div class="space-y-5">
            <p
                v-if="useError"
                class="text-sm text-danger"
                role="alert"
                data-testid="library-use-error"
                >{{ useError }}</p
            >

            <FeatureEmpty
                v-if="!brand"
                title="Crea una marca para usar plantillas de publicación"
                description="Guarda plantillas con texto, recursos y redes para usarlas en nuevas publicaciones."
                :cards="[
                    { title: 'Plantillas reutilizables', text: 'Guarda tus mejores textos y vuelve a publicarlos en un clic.', kind: 'report' },
                    { title: 'Archivos incluidos', text: 'Las plantillas conservan imágenes y vídeos de tu biblioteca.', kind: 'link' },
                    { title: 'Borradores al instante', text: '«Usar» crea un borrador listo para programar.', kind: 'calendar' },
                ]"
            >
                <template #cta
                    ><Link :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link></template
                >
            </FeatureEmpty>

            <form v-else class="card space-y-3 p-5" @submit.prevent="submit">
                <h2 class="text-sm font-medium text-foreground-secondary">Nueva plantilla de texto</h2>
                <FormField :error="form.errors.name" v-slot="{ control }">
                    <label class="block text-sm">
                        <span class="block text-xs font-medium text-foreground-muted">Nombre</span>
                        <input
                            v-bind="control"
                            v-model="form.name"
                            type="text"
                            maxlength="120"
                            required
                            class="field"
                        />
                    </label>
                </FormField>
                <FormField :error="form.errors.text" v-slot="{ control }">
                    <label class="block text-sm">
                        <span class="block text-xs font-medium text-foreground-muted">Texto</span>
                        <textarea
                            v-bind="control"
                            v-model="form.text"
                            rows="3"
                            maxlength="10000"
                            class="field"
                        ></textarea>
                    </label>
                </FormField>
                <FormField :error="form.errors.brand_id" />
                <PrimaryButton :disabled="form.processing">Guardar plantilla</PrimaryButton>
                <p class="text-xs text-foreground-subtle"
                    >También puedes guardar una publicación ya creada desde su editor o calendario.</p
                >
            </form>

            <section v-if="brand" class="card">
                <h2 class="border-b px-5 py-3 text-sm font-medium text-foreground-secondary">Plantillas guardadas</h2>
                <p v-if="templates.length === 0" class="p-5 text-sm text-foreground-muted"
                    >Aún no tienes plantillas. Crea una arriba o guarda una publicación desde su editor.</p
                >
                <ul v-else class="divide-y">
                    <li
                        v-for="t in templates"
                        :key="t.id"
                        class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 text-sm"
                    >
                        <div class="min-w-0">
                            <p class="font-medium text-foreground-strong">{{ t.name }}</p>
                            <p v-if="t.text" class="line-clamp-2 text-foreground-muted">{{ t.text }}</p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <NetworkChip v-for="n in t.networks" :key="n" :network="n" />
                                <span class="text-xs text-foreground-subtle"
                                    >{{ t.brand }} · {{ t.media.length }} archivos</span
                                >
                            </div>
                        </div>
                        <ul
                            v-if="t.brand_id && readyImages(t).length"
                            aria-label="Imágenes de la plantilla"
                            class="w-full space-y-1"
                        >
                            <li
                                v-for="asset in readyImages(t)"
                                :key="asset.id"
                                class="flex flex-wrap items-center gap-2"
                            >
                                <span class="max-w-full break-words">{{ asset.name }}</span>
                                <OpenStudioImage :brand-id="t.brand_id" :media="asset" :csrf-token="csrfToken" />
                            </li>
                        </ul>
                        <div class="flex items-center gap-3">
                            <button type="button" class="btn btn-secondary" @click="use(t)">Usar</button>
                            <button type="button" class="text-xs font-medium text-danger underline" @click="remove(t)"
                                >Eliminar</button
                            >
                        </div>
                    </li>
                </ul>
                <Pagination
                    :page="pagination"
                    route-name="library.index"
                    label="Paginación de plantillas de publicación"
                    :params="{ brand_id: brand?.id }"
                    class="mt-3"
                />
            </section>
        </div>
    </AuthenticatedLayout>
</template>
