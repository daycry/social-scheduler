<script setup>
import FormField from '@/Components/FormField.vue';
import InputError from '@/Components/InputError.vue';
import { createBlockKey, focusEditedBlock, focusFirstFormError } from '@/lib/form-errors';
import SiteAccessPanel from '@/Components/Sites/SiteAccessPanel.vue';
import SiteContactInbox from '@/Components/Sites/SiteContactInbox.vue';
import SiteSavedPreview from '@/Components/Sites/SiteSavedPreview.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { computed, ref, useId, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

import { useConfirm } from '@/Composables/useConfirm';

const ask = useConfirm();

/**
 * Constructor de sitios (T-15): páginas con menú y bloques reordenables, vista previa responsive (iframe del MISMO render que
 * el sitio publicado), publicación con versión separada del borrador y controles de acceso. Sin `v-html`: todo texto se
 * pinta con interpolación y el HTML real lo genera el servidor (vista previa y sitio publicado).
 */
const props = defineProps({
    site: Object,
    theme: Object,
    brand: Object,
    tokens: { type: Array, default: () => [] },
    new_token: Object,
    stats: Object,
    messages: { type: Array, default: () => [] },
    messages_meta: { type: Object, default: () => ({ page: 1, last: 1, total: 0, unread: 0 }) },
    images: { type: Array, default: () => [] },
    exports: { type: Array, default: () => [] },
    limits: Object,
});

const clone = (v) => JSON.parse(JSON.stringify(v));
const hex8 = () => Array.from(crypto.getRandomValues(new Uint8Array(4)), (b) => b.toString(16).padStart(2, '0')).join('');

const form = useForm({
    title: props.site.title,
    slug: props.site.slug,
    description: props.site.description ?? '',
    theme_source: props.site.theme_source,
    theme_color: props.site.theme_color,
    theme_mode: props.site.theme_mode,
    pages: clone(props.site.pages),
});
const current = ref(0);
const page = computed(() => form.pages[current.value] ?? form.pages[0]);

// Tras guardar, el servidor es la verdad (claves de bloque, saneado).
watch(
    () => props.site.revision,
    () => {
        form.defaults({ title: props.site.title, slug: props.site.slug, description: props.site.description ?? '', theme_source: props.site.theme_source, theme_color: props.site.theme_color, theme_mode: props.site.theme_mode, pages: clone(props.site.pages) }).reset();
        if (current.value >= form.pages.length) current.value = 0;
    },
);

const TYPE_LABELS = { heading: 'Título', text: 'Texto', image: 'Imagen', gallery: 'Galería', button: 'Botón o enlace', youtube: 'Video de YouTube', contact: 'Formulario de contacto', design: 'Diseño del estudio', faq: 'Preguntas frecuentes (acordeón)', poll: 'Encuesta' };
const TYPES = Object.keys(TYPE_LABELS);
const blank = (type) => {
    switch (type) {
        case 'heading': return { type, text: '', level: 2 };
        case 'text': return { type, body: '' };
        case 'image': return { type, media_id: null, alt: '', caption: '' };
        case 'gallery': return { type, items: [{ media_id: null, alt: '' }] };
        case 'button': return { type, label: '', kind: 'url', url: 'https://', page: null };
        case 'contact': return { type, title: 'Escríbenos', cta: 'Enviar', consent: '' };
        case 'design': return { type, export_id: null, page: 0, alt: '' };
        case 'faq': return { type, title: '', items: [{ q: '', a: '' }] };
        case 'poll': return { type, question: '', options: ['', ''] };
        default: return { type, url: 'https://', title: '' }; // video
    }
};

const addPage = () => {
    if (form.pages.length >= props.limits.pages) return;
    const n = form.pages.length + 1;
    form.pages.push({ key: hex8(), slug: `pagina-${n}`, title: `Página ${n}`, menu: true, blocks: [] });
    current.value = form.pages.length - 1;
};
const removePage = async (i) => {
    const target = form.pages[i];
    if (!target || form.pages.length < 2 || !(await ask({ message: '¿Quitar esta página y sus bloques?', confirmLabel: 'Quitar página' }))) return;
    const index = form.pages.indexOf(target);
    if (index < 0 || form.pages.length < 2) return;
    form.pages.splice(index, 1);
    if (index < current.value) current.value--;
    else current.value = Math.min(current.value, form.pages.length - 1);
};
const movePage = (i, d) => {
    const j = i + d;
    if (j < 0 || j >= form.pages.length) return;
    [form.pages[i], form.pages[j]] = [form.pages[j], form.pages[i]];
    current.value = j;
};
const addBlock = (type) => {
    if (page.value.blocks.length < props.limits.blocks) page.value.blocks.push(blank(type));
};
const formElement = ref(null);
const blockListErrorId = useId();
const blockKey = createBlockKey(block => block.key);
const focusBlock = (index, direction) => focusEditedBlock(formElement.value, index, direction);
const moveBlock = (i, d) => {
    const j = i + d;
    const list = page.value.blocks;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];
    focusBlock(j, d);
};
const removeBlock = (i) => {
    const list = page.value.blocks;
    list.splice(i, 1);
    focusBlock(Math.min(i, list.length - 1));
};
const directBlockErrors = { image: 'alt', design: 'alt', youtube: 'url', button: 'url', poll: 'question' };
const blockFieldError = (index, field) => form.errors[`pages.${current.value}.blocks.${index}.${field}`];
const blockErrors = (index, block) => {
    const prefix = `pages.${current.value}.blocks.${index}`;
    const direct = block.type === 'button' && block.kind === 'page' ? null : directBlockErrors[block.type];
    return Object.entries(form.errors)
        .filter(([key]) => (key === prefix || key.startsWith(`${prefix}.`)) && (!direct || key !== `${prefix}.${direct}`))
        .map(([, message]) => message)
        .join(' ');
};
const blocksError = computed(() => form.errors[`pages.${current.value}.blocks`]);
const save = () => form.put(route('sites.update', props.site.id), {
    preserveScroll: true,
    onError: (errors) => {
        const first = Object.keys(errors).map(key => /^pages\.(\d+)\./.exec(key)).find(Boolean);
        if (first && form.pages[Number(first[1])]) current.value = Number(first[1]);
        return focusFirstFormError(formElement.value);
    },
});

// Publicación.
const publish = () => router.post(route('sites.publish', props.site.id), {}, { preserveScroll: true });
const unpublish = () => router.post(route('sites.unpublish', props.site.id), {}, { preserveScroll: true });
const destroy = async () => {
    const target = route('sites.destroy', props.site.id);
    if (await ask({ message: '¿Eliminar el sitio? Su dirección pública dejará de existir.', confirmLabel: 'Eliminar sitio' })) router.delete(target);
};
const imageUrl = (id) => props.images.find((m) => m.id === id)?.url ?? null;
const pageTitle = (key) => props.site.pages.find((p) => p.key === key)?.title ?? key;
</script>

<template>
    <Head :title="`Sitio: ${site.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-3">
                <Link :href="route('sites.index', { brand_id: brand.id })" class="text-sm underline">Sitios</Link>
                <h1 class="text-xl font-bold text-slate-900">{{ site.title }}</h1>
                <span
                    class="rounded bg-slate-100 px-2 py-0.5 text-xs"
                    data-testid="site-state"
                    >{{ site.is_published ? (site.dirty ? 'Publicado, con cambios sin publicar' : 'Publicado') : 'Borrador' }}</span
                >
            </div>
        </template>

        <div class="space-y-5">
            <FormField :error="form.errors.pages" />

            <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                <form ref="formElement" class="space-y-5" @submit.prevent="save">
                    <section class="card space-y-4 p-5" aria-labelledby="s-ajustes">
                        <h2 id="s-ajustes" class="text-base font-semibold text-slate-900">Ajustes del sitio</h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField :error="form.errors.title" v-slot="{ control }">
                                <label class="block text-sm">
                                    <span class="block text-xs font-medium text-slate-600">Título</span>
                                    <input
                                        v-bind="control"
                                        v-model="form.title"
                                        type="text"
                                        maxlength="100"
                                        required
                                        class="field"
                                        data-testid="site-title"
                                    />
                                </label>
                            </FormField>
                            <FormField :error="form.errors.slug" v-slot="{ control }">
                                <label class="block text-sm">
                                    <span class="block text-xs font-medium text-slate-600">Dirección (/s/…)</span>
                                    <input
                                        v-bind="control"
                                        v-model="form.slug"
                                        type="text"
                                        maxlength="40"
                                        required
                                        class="field"
                                        data-testid="site-slug"
                                    />
                                </label>
                            </FormField>
                        </div>
                        <label class="block text-sm">
                            <span class="block text-xs font-medium text-slate-600"
                                >Descripción (buscadores y redes)</span
                            >
                            <textarea v-model="form.description" rows="2" maxlength="300" class="field"></textarea>
                        </label>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <label class="block text-sm">
                                <span class="block text-xs font-medium text-slate-600">Tema</span>
                                <select v-model="form.theme_source" class="field" data-testid="theme-source">
                                    <option value="kit">Kit de marca ({{ brand.name }})</option>
                                    <option value="custom">Color propio</option>
                                </select>
                            </label>
                            <label v-if="form.theme_source === 'custom'" class="block text-sm">
                                <span class="block text-xs font-medium text-slate-600">Color</span>
                                <input
                                    v-model="form.theme_color"
                                    type="color"
                                    class="h-9 w-16 cursor-pointer rounded border border-slate-300 p-0.5"
                                    aria-label="Color del tema"
                                />
                            </label>
                            <p v-else class="text-sm text-slate-700"
                                >Color del kit:
                                <span
                                    class="rounded px-2 py-0.5 text-xs font-semibold"
                                    :style="{ background: theme.accent, color: theme.on_accent }"
                                    >{{ theme.accent }}</span
                                ></p
                            >
                            <label class="block text-sm">
                                <span class="block text-xs font-medium text-slate-600">Modo</span>
                                <select v-model="form.theme_mode" class="field">
                                    <option value="light">Claro</option>
                                    <option value="dark">Oscuro</option>
                                </select>
                            </label>
                        </div>
                    </section>

                    <section class="card space-y-3 p-5" aria-labelledby="s-paginas">
                        <h2 id="s-paginas" class="text-base font-semibold text-slate-900">Páginas y menú</h2>
                        <ul class="flex flex-wrap gap-2" role="list" data-testid="page-tabs">
                            <li v-for="(p, i) in form.pages" :key="p.key" class="flex items-center gap-1">
                                <button
                                    type="button"
                                    class="btn"
                                    :class="i === current ? 'btn-primary' : 'btn-secondary'"
                                    :aria-pressed="i === current"
                                    :data-testid="`page-tab-${i}`"
                                    @click="current = i"
                                    >{{ p.title || `Página ${i + 1}`
                                    }}<span v-if="i === 0" class="sr-only"> (inicio)</span></button
                                >
                            </li>
                            <li
                                ><button
                                    type="button"
                                    class="btn btn-secondary"
                                    data-testid="add-page"
                                    :disabled="form.pages.length >= limits.pages"
                                    @click="addPage"
                                    >Añadir página</button
                                ></li
                            >
                        </ul>

                        <div v-if="page" class="space-y-3 rounded border border-slate-200 p-3">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <FormField :error="form.errors[`pages.${current}.title`]" v-slot="{ control }">
                                    <label class="block text-sm">
                                        <span class="block text-xs font-medium text-slate-600"
                                            >Título de la página (menú)</span
                                        >
                                        <input
                                            v-bind="control"
                                            v-model="page.title"
                                            type="text"
                                            maxlength="80"
                                            class="field"
                                            data-testid="page-title"
                                        />
                                    </label>
                                </FormField>
                                <FormField :error="form.errors[`pages.${current}.slug`]" v-slot="{ control }">
                                    <label class="block text-sm">
                                        <span class="block text-xs font-medium text-slate-600"
                                            >Dirección de la página</span
                                        >
                                        <input
                                            v-bind="control"
                                            v-model="page.slug"
                                            type="text"
                                            maxlength="40"
                                            class="field"
                                            data-testid="page-slug"
                                        />
                                    </label>
                                </FormField>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                <label class="flex items-center gap-2"
                                    ><input v-model="page.menu" type="checkbox" class="rounded border-slate-300" />
                                    Mostrar en el menú</label
                                >
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    :disabled="current === 0"
                                    :aria-label="`Subir página ${current + 1}`"
                                    @click="movePage(current, -1)"
                                    >↑ Subir</button
                                >
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    :disabled="current === form.pages.length - 1"
                                    :aria-label="`Bajar página ${current + 1}`"
                                    @click="movePage(current, 1)"
                                    >↓ Bajar</button
                                >
                                <button
                                    type="button"
                                    class="text-xs font-medium text-red-700 underline"
                                    :disabled="form.pages.length < 2"
                                    @click="removePage(current)"
                                    >Quitar página</button
                                >
                                <span v-if="current === 0" class="text-xs text-slate-600"
                                    >Es la portada: se sirve en /s/{{ form.slug }}.</span
                                >
                            </div>
                        </div>
                    </section>

                    <template v-if="page">
                        <section
                            class="card space-y-3 p-5"
                            aria-labelledby="s-bloques"
                            :aria-describedby="blocksError ? blockListErrorId : undefined"
                            :data-error-target="blocksError ? 'true' : undefined"
                            tabindex="-1"
                        >
                            <h2 id="s-bloques" class="text-base font-semibold text-slate-900"
                                >Bloques de «{{ page.title }}»</h2
                            >

                            <InputError :id="blockListErrorId" :message="blocksError" />

                            <FormField
                                v-for="(b, i) in page.blocks"
                                :key="blockKey(b)"
                                :error="blockErrors(i, b)"
                                v-slot="{ group }"
                            >
                                <div
                                    v-bind="group"
                                    :data-block-index="i"
                                    role="group"
                                    :aria-label="`Bloque ${i + 1}`"
                                    class="rounded border border-slate-200 p-3"
                                    data-testid="block"
                                >
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span
                                            class="rounded bg-slate-100 px-2 py-0.5 text-xs"
                                            data-testid="block-type"
                                            >{{ TYPE_LABELS[b.type] }}</span
                                        >
                                        <span class="flex-1"></span>
                                        <button
                                            type="button"
                                            class="btn btn-secondary"
                                            data-block-shift="-1"
                                            :disabled="i === 0"
                                            :aria-label="`Subir bloque ${i + 1}`"
                                            @click="moveBlock(i, -1)"
                                            >↑</button
                                        >
                                        <button
                                            type="button"
                                            class="btn btn-secondary"
                                            data-block-shift="1"
                                            :disabled="i === page.blocks.length - 1"
                                            :aria-label="`Bajar bloque ${i + 1}`"
                                            @click="moveBlock(i, 1)"
                                            >↓</button
                                        >
                                        <button
                                            type="button"
                                            class="text-xs font-medium text-red-700 underline"
                                            :aria-label="`Quitar bloque ${i + 1}`"
                                            @click="removeBlock(i)"
                                            >Quitar</button
                                        >
                                    </div>

                                    <div v-if="b.type === 'heading'" class="mt-2 grid gap-2 sm:grid-cols-[1fr_8rem]">
                                        <input
                                            v-model="b.text"
                                            type="text"
                                            maxlength="120"
                                            class="field"
                                            :aria-label="`Texto del bloque ${i + 1}`"
                                        />
                                        <select
                                            v-model.number="b.level"
                                            class="field"
                                            :aria-label="`Nivel del título ${i + 1}`"
                                            ><option :value="2">Grande</option
                                            ><option :value="3">Mediano</option></select
                                        >
                                    </div>
                                    <div v-if="b.type === 'text'" class="mt-2">
                                        <textarea
                                            v-model="b.body"
                                            rows="5"
                                            maxlength="5000"
                                            class="field"
                                            :aria-label="`Texto del bloque ${i + 1}`"
                                        ></textarea>
                                        <p class="mt-1 text-xs text-slate-600"
                                            >Párrafos separados por una línea en blanco; «- » abre una lista;
                                            **negrita**, *cursiva* y [texto](https://enlace). No se admite HTML.</p
                                        >
                                    </div>
                                    <div v-if="b.type === 'image'" class="mt-2 grid gap-2 sm:grid-cols-3">
                                        <select
                                            v-model="b.media_id"
                                            class="field"
                                            :aria-label="`Imagen del bloque ${i + 1}`"
                                            ><option :value="null">Elegir imagen…</option
                                            ><option
                                                v-for="m in images"
                                                :key="m.id"
                                                :value="m.id"
                                                >{{ m.name }}</option
                                            ></select
                                        >
                                        <div class="min-w-0">
                                            <FormField :error="blockFieldError(i, 'alt')" v-slot="{ control }">
                                                <input
                                                    v-bind="control"
                                                    v-model="b.alt"
                                                    type="text"
                                                    maxlength="150"
                                                    class="field"
                                                    :aria-label="`Texto alternativo del bloque ${i + 1}`"
                                                    placeholder="Texto alternativo"
                                                />
                                            </FormField>
                                        </div>
                                        <input
                                            v-model="b.caption"
                                            type="text"
                                            maxlength="150"
                                            class="field"
                                            :aria-label="`Pie del bloque ${i + 1}`"
                                            placeholder="Pie (opcional)"
                                        />
                                    </div>
                                    <div v-if="b.type === 'gallery'" class="mt-2 space-y-2">
                                        <div
                                            v-for="(it, k) in b.items"
                                            :key="k"
                                            class="grid gap-2 sm:grid-cols-[1fr_1fr_auto]"
                                        >
                                            <select
                                                v-model="it.media_id"
                                                class="field"
                                                :aria-label="`Imagen ${k + 1} de la galería ${i + 1}`"
                                                ><option :value="null">Elegir imagen…</option
                                                ><option
                                                    v-for="m in images"
                                                    :key="m.id"
                                                    :value="m.id"
                                                    >{{ m.name }}</option
                                                ></select
                                            >
                                            <input
                                                v-model="it.alt"
                                                type="text"
                                                maxlength="150"
                                                class="field"
                                                :aria-label="`Texto alternativo de la imagen ${k + 1} de la galería ${i + 1}`"
                                                placeholder="Texto alternativo"
                                            />
                                            <button
                                                type="button"
                                                class="text-xs font-medium text-red-700 underline"
                                                @click="b.items.splice(k, 1)"
                                                >Quitar imagen</button
                                            >
                                        </div>
                                        <button
                                            type="button"
                                            class="btn btn-secondary"
                                            :disabled="b.items.length >= 12"
                                            @click="b.items.push({ media_id: null, alt: '' })"
                                            >Añadir imagen</button
                                        >
                                    </div>
                                    <div v-if="b.type === 'button'" class="mt-2 grid gap-2 sm:grid-cols-3">
                                        <input
                                            v-model="b.label"
                                            type="text"
                                            maxlength="60"
                                            class="field"
                                            :aria-label="`Texto del botón ${i + 1}`"
                                            placeholder="Texto del botón"
                                        />
                                        <select
                                            v-model="b.kind"
                                            class="field"
                                            :aria-label="`Destino del botón ${i + 1}`"
                                            ><option value="url">Enlace externo</option
                                            ><option value="page">Página del sitio</option></select
                                        >
                                        <div v-if="b.kind === 'url'" class="min-w-0">
                                            <FormField :error="blockFieldError(i, 'url')" v-slot="{ control }">
                                                <input
                                                    v-bind="control"
                                                    v-model="b.url"
                                                    type="url"
                                                    class="field"
                                                    :aria-label="`URL del botón ${i + 1}`"
                                                    placeholder="https://…"
                                                />
                                            </FormField>
                                        </div>
                                        <select
                                            v-else
                                            v-model="b.page"
                                            class="field"
                                            :aria-label="`Página del botón ${i + 1}`"
                                            ><option :value="null">Elegir página…</option
                                            ><option
                                                v-for="p in form.pages"
                                                :key="p.key"
                                                :value="p.key"
                                                >{{ p.title }}</option
                                            ></select
                                        >
                                    </div>
                                    <div v-if="b.type === 'youtube'" class="mt-2 grid gap-2 sm:grid-cols-2">
                                        <div class="min-w-0">
                                            <FormField :error="blockFieldError(i, 'url')" v-slot="{ control }">
                                                <input
                                                    v-bind="control"
                                                    v-model="b.url"
                                                    type="url"
                                                    class="field"
                                                    :aria-label="`URL del video ${i + 1}`"
                                                    placeholder="https://www.youtube.com/watch?v=…"
                                                />
                                            </FormField>
                                        </div>
                                        <input
                                            v-model="b.title"
                                            type="text"
                                            maxlength="100"
                                            class="field"
                                            :aria-label="`Título del video ${i + 1}`"
                                            placeholder="Título del video"
                                        />
                                    </div>
                                    <div v-if="b.type === 'contact'" class="mt-2 grid gap-2 sm:grid-cols-3">
                                        <input
                                            v-model="b.title"
                                            type="text"
                                            maxlength="80"
                                            class="field"
                                            :aria-label="`Título del formulario ${i + 1}`"
                                        />
                                        <input
                                            v-model="b.cta"
                                            type="text"
                                            maxlength="30"
                                            class="field"
                                            :aria-label="`Texto del envío ${i + 1}`"
                                        />
                                        <input
                                            v-model="b.consent"
                                            type="text"
                                            maxlength="200"
                                            class="field"
                                            :aria-label="`Aviso de consentimiento ${i + 1}`"
                                            placeholder="Aviso de consentimiento (opcional)"
                                        />
                                        <p class="text-xs text-slate-600 sm:col-span-3"
                                            >Los mensajes llegan a este panel (sin correo). Protegido con campo trampa,
                                            tiempo mínimo y límite de envíos.</p
                                        >
                                    </div>
                                    <div v-if="b.type === 'design'" class="mt-2 grid gap-2 sm:grid-cols-3">
                                        <select
                                            v-model="b.export_id"
                                            class="field"
                                            :aria-label="`Exportación del bloque ${i + 1}`"
                                            ><option :value="null">Elegir exportación…</option
                                            ><option v-for="x in exports" :key="x.id" :value="x.id"
                                                >{{ x.label }} ({{ x.pages }} p.)</option
                                            ></select
                                        >
                                        <input
                                            :value="b.page + 1"
                                            type="number"
                                            min="1"
                                            class="field"
                                            :aria-label="`Página del diseño del bloque ${i + 1}`"
                                            @input="b.page = Math.max(0, Number($event.target.value) - 1)"
                                        />
                                        <div class="min-w-0">
                                            <FormField :error="blockFieldError(i, 'alt')" v-slot="{ control }">
                                                <input
                                                    v-bind="control"
                                                    v-model="b.alt"
                                                    type="text"
                                                    maxlength="150"
                                                    class="field"
                                                    :aria-label="`Texto alternativo del bloque ${i + 1}`"
                                                    placeholder="Texto alternativo"
                                                />
                                            </FormField>
                                        </div>
                                        <p v-if="!exports.length" class="text-xs text-slate-600 sm:col-span-3"
                                            >Exporta un diseño como imagen en el Estudio para poder incrustarlo aquí.</p
                                        >
                                    </div>
                                    <div v-if="b.type === 'faq'" class="mt-2 space-y-2">
                                        <input
                                            v-model="b.title"
                                            type="text"
                                            maxlength="80"
                                            class="field"
                                            :aria-label="`Título del acordeón ${i + 1}`"
                                            placeholder="Título (opcional)"
                                        />
                                        <div
                                            v-for="(it, k) in b.items"
                                            :key="k"
                                            class="grid gap-2 sm:grid-cols-[1fr_1fr_auto]"
                                        >
                                            <input
                                                v-model="it.q"
                                                type="text"
                                                maxlength="150"
                                                class="field"
                                                :aria-label="`Pregunta ${k + 1} del bloque ${i + 1}`"
                                                placeholder="Pregunta"
                                            />
                                            <input
                                                v-model="it.a"
                                                type="text"
                                                maxlength="1000"
                                                class="field"
                                                :aria-label="`Respuesta ${k + 1} del bloque ${i + 1}`"
                                                placeholder="Respuesta"
                                            />
                                            <button
                                                type="button"
                                                class="text-xs font-medium text-red-700 underline"
                                                @click="b.items.splice(k, 1)"
                                                >Quitar pregunta</button
                                            >
                                        </div>
                                        <button
                                            type="button"
                                            class="btn btn-secondary"
                                            :disabled="b.items.length >= 10"
                                            @click="b.items.push({ q: '', a: '' })"
                                            >Añadir pregunta</button
                                        >
                                    </div>
                                    <div v-if="b.type === 'poll'" class="mt-2 space-y-2">
                                        <div class="min-w-0">
                                            <FormField :error="blockFieldError(i, 'question')" v-slot="{ control }">
                                                <input
                                                    v-bind="control"
                                                    v-model="b.question"
                                                    type="text"
                                                    maxlength="150"
                                                    class="field"
                                                    :aria-label="`Pregunta de la encuesta ${i + 1}`"
                                                    placeholder="Pregunta"
                                                />
                                            </FormField>
                                        </div>
                                        <div v-for="(o, k) in b.options" :key="k" class="flex gap-2">
                                            <input
                                                v-model="b.options[k]"
                                                type="text"
                                                maxlength="60"
                                                class="field"
                                                :aria-label="`Opción ${k + 1} de la encuesta ${i + 1}`"
                                            />
                                            <button
                                                type="button"
                                                class="text-xs font-medium text-red-700 underline"
                                                :disabled="b.options.length <= 2"
                                                @click="b.options.splice(k, 1)"
                                                >Quitar</button
                                            >
                                        </div>
                                        <button
                                            type="button"
                                            class="btn btn-secondary"
                                            :disabled="b.options.length >= 6"
                                            @click="b.options.push('')"
                                            >Añadir opción</button
                                        >
                                        <p class="text-xs text-slate-600"
                                            >Los resultados son agregados y se reinician si cambias las opciones.</p
                                        >
                                    </div>
                                </div>
                            </FormField>
                            <p v-if="!page.blocks.length" class="text-sm text-slate-600"
                                >Esta página aún no tiene bloques.</p
                            >
                            <div class="flex flex-wrap gap-2" data-testid="add-blocks">
                                <button
                                    v-for="t in TYPES"
                                    :key="t"
                                    type="button"
                                    class="btn btn-secondary"
                                    :data-testid="`add-${t}`"
                                    data-add-block
                                    @click="addBlock(t)"
                                    >Añadir {{ TYPE_LABELS[t].toLowerCase() }}</button
                                >
                            </div>
                        </section>
                    </template>

                    <div class="flex flex-wrap items-center gap-4">
                        <PrimaryButton :disabled="form.processing" data-testid="save-draft"
                            >Guardar borrador</PrimaryButton
                        >
                        <span v-if="form.isDirty" class="text-xs text-amber-800" data-testid="dirty"
                            >Hay cambios sin guardar.</span
                        >
                    </div>
                </form>

                <aside class="space-y-5 xl:sticky xl:top-4" aria-label="Vista previa y publicación">
                    <section class="card space-y-3 p-4 text-sm" aria-labelledby="s-publicar">
                        <h2 id="s-publicar" class="text-base font-semibold text-slate-900">Publicación</h2>
                        <p v-if="site.is_published"
                            >Publicado:
                            <a
                                :href="site.public_url"
                                target="_blank"
                                rel="noopener"
                                class="break-all underline"
                                data-testid="public-url"
                                >{{ site.public_url }}</a
                            ></p
                        >
                        <p v-else class="text-slate-700">No publicado: la dirección pública responde 404.</p>
                        <p v-if="site.dirty" class="text-amber-800" data-testid="dirty-published"
                            >Lo publicado no incluye tus últimos cambios guardados.</p
                        >
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="btn btn-primary"
                                :disabled="form.isDirty"
                                data-testid="publish"
                                @click="publish"
                                >{{ site.is_published ? 'Volver a publicar' : 'Publicar' }}</button
                            >
                            <button
                                v-if="site.is_published"
                                type="button"
                                class="btn btn-secondary"
                                data-testid="unpublish"
                                @click="unpublish"
                                >Despublicar</button
                            >
                        </div>
                        <p v-if="form.isDirty" class="text-xs text-slate-600">Guarda el borrador antes de publicar.</p>
                        <FormField :error="form.errors.access" />
                    </section>

                    <SiteAccessPanel :site="site" :tokens="tokens" :new-token="new_token" />

                    <section class="card space-y-2 p-4 text-sm" aria-labelledby="s-analitica">
                        <h2 id="s-analitica" class="text-base font-semibold text-slate-900">Visitas (30 días)</h2>
                        <p class="text-lg font-semibold tabular-nums" data-testid="visits-total">{{ stats.total }}</p>
                        <ul class="text-xs text-slate-700" data-testid="visits-by-page"
                            ><li v-for="(n, key) in stats.by_page" :key="key">{{ pageTitle(key) }}: {{ n }}</li></ul
                        >
                        <p class="text-xs text-slate-600"
                            >Solo contadores diarios por página: no se guarda IP ni agente.</p
                        >
                    </section>

                    <SiteContactInbox :site-id="site.id" :messages="messages" :messages-meta="messages_meta" />

                    <button type="button" class="text-sm font-medium text-red-700 underline" @click="destroy"
                        >Eliminar sitio</button
                    >
                </aside>
            </div>

            <SiteSavedPreview :site="site" :active-page="current" />
        </div>
    </AuthenticatedLayout>
</template>
