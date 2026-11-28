<script setup>
import FormField from '@/Components/FormField.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

/** Sitios (T-15): lista de los sitios de la marca y alta de uno nuevo. La edición vive en Sites/Edit. */
const props = defineProps({ brand: Object, sites: { type: Array, default: () => [] }, max_sites: Number });

const form = useForm({ brand_id: props.brand?.id ?? '', title: '', slug: '' });
const submit = () => form.post(route('sites.store'));
const accessLabel = (m) => ({ public: 'Público', password: 'Con contraseña', token: 'Por enlace con token' })[m] ?? m;
</script>

<template>
    <Head title="Sitios" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-foreground-strong">Sitios</h1>
        </template>

        <div class="space-y-5">
            <FeatureEmpty
                v-if="!brand"
                title="Crea una marca para construir su sitio"
                description="Un sitio es una web de varias páginas con el tema de tu marca, publicada en este mismo dominio."
                :cards="[
                    { title: 'Varias páginas', text: 'Menú, texto, imágenes, galerías, video y formulario de contacto.', kind: 'report' },
                    { title: 'Publicación propia', text: 'Pública, con contraseña o por enlace con token, y revocable al instante.', kind: 'link' },
                    { title: 'Sin rastreo', text: 'Solo visitas agregadas por página, sin IP.', kind: 'chart' },
                ]"
            >
                <template #cta
                    ><Link :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link></template
                >
            </FeatureEmpty>

            <template v-else>
                <section class="card p-5" aria-labelledby="sites-list">
                    <h2 id="sites-list" class="text-base font-semibold text-foreground-strong"
                        >Sitios de {{ brand.name }}</h2
                    >
                    <p v-if="!sites.length" class="mt-2 text-sm text-foreground-muted"
                        >Todavía no tienes ningún sitio. Crea el primero con el formulario de abajo.</p
                    >
                    <ul v-else class="mt-3 divide-y" data-testid="site-list">
                        <li
                            v-for="s in sites"
                            :key="s.id"
                            class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                        >
                            <div class="min-w-0">
                                <Link
                                    :href="route('sites.edit', s.id)"
                                    class="font-medium text-brand-700 underline"
                                    >{{ s.title }}</Link
                                >
                                <p class="break-all text-xs text-foreground-muted">{{ s.public_url }}</p>
                            </div>
                            <p class="text-xs text-foreground-secondary">
                                <span
                                    class="rounded bg-surface-hover px-2 py-0.5"
                                    >{{ s.is_published ? 'Publicado' : 'Borrador' }}</span
                                >
                                <span
                                    class="ml-1 rounded bg-surface-hover px-2 py-0.5"
                                    >{{ accessLabel(s.access_mode) }}</span
                                >
                            </p>
                        </li>
                    </ul>
                </section>

                <form v-if="sites.length < max_sites" class="card space-y-4 p-5" @submit.prevent="submit">
                    <h2 class="text-base font-semibold text-foreground-strong">Nuevo sitio</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField :error="form.errors.title" v-slot="{ control }">
                            <label class="block text-sm">
                                <span class="block text-xs font-medium text-foreground-muted">Título</span>
                                <input
                                    v-bind="control"
                                    v-model="form.title"
                                    type="text"
                                    maxlength="100"
                                    required
                                    class="field"
                                    data-testid="new-site-title"
                                />
                            </label>
                        </FormField>
                        <div class="block text-sm">
                            <FormField
                                id="site-slug"
                                described-by="site-slug-help"
                                :error="form.errors.slug"
                                v-slot="{ control }"
                            >
                                <label for="site-slug" class="block text-xs font-medium text-foreground-muted"
                                    >Dirección</label
                                >
                                <div class="flex items-stretch">
                                    <span
                                        class="inline-flex items-center rounded-l-md border border-r-0 border-control-border bg-canvas px-2 text-sm text-foreground-muted"
                                        aria-hidden="true"
                                        >/s/</span
                                    >
                                    <input
                                        v-bind="control"
                                        v-model="form.slug"
                                        type="text"
                                        maxlength="40"
                                        required
                                        class="field !rounded-l-none"
                                        data-testid="new-site-slug"
                                    />
                                </div>
                                <p id="site-slug-help" class="mt-1 text-xs text-foreground-muted"
                                    >De 3 a 40 caracteres: minúsculas, números y guiones.</p
                                >
                            </FormField>
                        </div>
                    </div>
                    <FormField :error="form.errors.brand" />
                    <PrimaryButton :disabled="form.processing">Crear sitio</PrimaryButton>
                </form>
                <p v-else class="text-sm text-foreground-muted"
                    >Has llegado al máximo de {{ max_sites }} sitios por marca.</p
                >
            </template>
        </div>
    </AuthenticatedLayout>
</template>
