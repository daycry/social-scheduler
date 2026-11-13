<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

/** Sitios (T-15): lista de los sitios de la marca y alta de uno nuevo. La edición vive en Sites/Edit. */
const props = defineProps({ brand: Object, sites: { type: Array, default: () => [] }, max_sites: Number, flash: Object });

const form = useForm({ brand_id: props.brand?.id ?? '', title: '', slug: '' });
const submit = () => form.post(route('sites.store'));
const accessLabel = (m) => ({ public: 'Público', password: 'Con contraseña', token: 'Por enlace con token' })[m] ?? m;
</script>

<template>
    <Head title="Sitios" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Sitios</h1>
        </template>

        <div class="space-y-5">
            <p v-if="flash?.success" class="text-sm text-emerald-700" role="status">{{ flash.success }}</p>
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
                <template #cta><Link :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link></template>
            </FeatureEmpty>

            <template v-else>
                <section class="card p-5" aria-labelledby="sites-list">
                    <h2 id="sites-list" class="text-base font-semibold text-slate-900">Sitios de {{ brand.name }}</h2>
                    <p v-if="!sites.length" class="mt-2 text-sm text-slate-600">Todavía no tienes ningún sitio. Crea el primero con el formulario de abajo.</p>
                    <ul v-else class="mt-3 divide-y" data-testid="site-list">
                        <li v-for="s in sites" :key="s.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                            <div class="min-w-0">
                                <Link :href="route('sites.edit', s.id)" class="font-medium text-brand-700 underline">{{ s.title }}</Link>
                                <p class="break-all text-xs text-slate-600">{{ s.public_url }}</p>
                            </div>
                            <p class="text-xs text-slate-700">
                                <span class="rounded bg-slate-100 px-2 py-0.5">{{ s.is_published ? 'Publicado' : 'Borrador' }}</span>
                                <span class="ml-1 rounded bg-slate-100 px-2 py-0.5">{{ accessLabel(s.access_mode) }}</span>
                            </p>
                        </li>
                    </ul>
                </section>

                <form v-if="sites.length < max_sites" class="card space-y-4 p-5" @submit.prevent="submit">
                    <h2 class="text-base font-semibold text-slate-900">Nuevo sitio</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm">
                            <span class="block text-xs font-medium text-slate-600">Título</span>
                            <input v-model="form.title" type="text" maxlength="100" required class="field" data-testid="new-site-title" />
                            <InputError :message="form.errors.title" />
                        </label>
                        <div class="block text-sm">
                            <label for="site-slug" class="block text-xs font-medium text-slate-600">Dirección</label>
                            <div class="flex items-stretch">
                                <span class="inline-flex items-center rounded-l-md border border-r-0 border-slate-300 bg-slate-50 px-2 text-sm text-slate-600" aria-hidden="true">/s/</span>
                                <input id="site-slug" v-model="form.slug" type="text" maxlength="40" required aria-describedby="site-slug-help site-slug-error" :aria-invalid="form.errors.slug ? 'true' : undefined" class="field !rounded-l-none" data-testid="new-site-slug" />
                            </div>
                            <p id="site-slug-help" class="mt-1 text-xs text-slate-600">De 3 a 40 caracteres: minúsculas, números y guiones.</p>
                            <InputError id="site-slug-error" :message="form.errors.slug" />
                        </div>
                    </div>
                    <InputError :message="form.errors.brand" />
                    <PrimaryButton :disabled="form.processing">Crear sitio</PrimaryButton>
                </form>
                <p v-else class="text-sm text-slate-600">Has llegado al máximo de {{ max_sites }} sitios por marca.</p>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
