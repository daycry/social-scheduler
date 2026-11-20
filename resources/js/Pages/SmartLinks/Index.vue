<script setup>
import FormField from '@/Components/FormField.vue';
import { createBlockKey, focusEditedBlock, focusFirstFormError } from '@/lib/form-errors';
import SmartLinkPreview from '@/Components/SmartLinks/SmartLinkPreview.vue';
import SmartLinkSubscribers from '@/Components/SmartLinks/SmartLinkSubscribers.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

import { useConfirm } from '@/Composables/useConfirm';

const ask = useConfirm();

/**
 * SmartLinks (PA-07): microsite «link in bio» de la marca con título, bio, avatar, bloques (enlace / cabecera) y tema.
 * La página pública no usa sesión ni cookies; las visitas y clics son contadores diarios sin datos personales.
 */
const props = defineProps({
    brand: Object,
    link: Object,
    stats: Object,
    images: Array,
    defaults: Object,
    subscribers: { type: Array, default: () => [] },
    block_types: { type: Array, default: () => [] },
    social_networks: { type: Array, default: () => [] },
});

const form = useForm({
    brand_id: props.brand?.id ?? '',
    slug: props.link?.slug ?? '',
    title: props.link?.title ?? props.brand?.name ?? '',
    bio: props.link?.bio ?? '',
    avatar_media_id: props.link?.avatar_media_id ?? null,
    theme_color: props.link?.theme_color ?? props.defaults.theme_color,
    theme_mode: props.link?.theme_mode ?? props.defaults.theme_mode,
    published: props.link?.published ?? true,
    blocks: (props.link?.blocks ?? []).map((b) => ({ ...b })),
});

const TYPE_LABELS = { link: 'Botón', header: 'Título', image: 'Imagen con enlace', youtube: 'Video de YouTube', divider: 'Separador', social: 'Iconos sociales', email: 'Formulario de correo', posts: 'Posts recientes' };
const typeLabel = (t) => TYPE_LABELS[t] ?? t;
const NEEDS_URL = ['link', 'image', 'youtube'];
const defaultsFor = (type) => ({
    id: null,
    type,
    label: '',
    url: NEEDS_URL.includes(type) ? 'https://' : null,
    media_id: null,
    data: type === 'social' ? { items: [{ network: 'instagram', url: 'https://' }] } : type === 'email' ? { cta: 'Suscribirme', consent: '' } : type === 'posts' ? { count: 3 } : null,
    visible_from: '',
    visible_until: '',
    visibility: 'visible',
});
const blockFieldError = (index, field) => form.errors[`blocks.${index}.${field}`];
const blockError = (index, type) => {
    const prefix = `blocks.${index}`;
    const handled = new Set([`${prefix}.label`]);
    if (NEEDS_URL.includes(type)) handled.add(`${prefix}.url`);
    return Object.entries(form.errors)
        .filter(([key]) => (key === prefix || key.startsWith(`${prefix}.`)) && !handled.has(key))
        .map(([, message]) => message)
        .join(' ');
};
const formElement = ref(null);
const focusBlock = (index, direction) => focusEditedBlock(formElement.value, index, direction);
const blockKey = createBlockKey(block => block.id);
const addBlock = (type) => {
    if (form.blocks.length < props.defaults.max_blocks) form.blocks.push(defaultsFor(type));
};
const addSocial = (b) => {
    if (b.data.items.length < 8) b.data.items.push({ network: 'instagram', url: 'https://' });
};
const removeSocial = (b, k) => b.data.items.splice(k, 1);
const visibilityText = (b) => (b.visibility === 'scheduled' ? 'Programado: aún no visible' : b.visibility === 'expired' ? 'Caducado: ya no se muestra' : '');
const ctr = (b) => (props.stats?.views > 0 ? Math.round((clicks(b) / props.stats.views) * 100) : 0);
const clicks7 = (b) => (b.id ? (props.stats?.by_block_7d?.[b.id] ?? 0) : 0);
const hasClicks = (b) => ['link', 'image', 'youtube', 'social'].includes(b.type);
const removeBlock = async (i) => {
    const b = form.blocks[i];
    if (b.type === 'email' && b.id && (props.stats?.subscribers ?? 0) > 0 && !(await ask({ message: 'La lista de suscriptores se conserva aunque quites el formulario; puedes descargarla o borrarla desde «Suscriptores». ¿Quitar el formulario?', confirmLabel: 'Quitar formulario' }))) return;
    const index = form.blocks.indexOf(b);
    if (index < 0) return;
    form.blocks.splice(index, 1);
    focusBlock(Math.min(index, form.blocks.length - 1));
};
const move = (i, d) => {
    const j = i + d;
    if (j < 0 || j >= form.blocks.length) return;
    [form.blocks[i], form.blocks[j]] = [form.blocks[j], form.blocks[i]];
    focusBlock(j, d);
};
// Tras guardar, el servidor es la verdad: ids nuevos (clics por bloque), estado de visibilidad y orden.
watch(
    () => props.link?.blocks,
    (blocks) => {
        if (blocks) form.blocks = blocks.map((b) => ({ ...b, data: b.data ? JSON.parse(JSON.stringify(b.data)) : null }));
    },
);
const submit = () => form.post(route('smartlinks.save'), {
    preserveScroll: true,
    onError: () => focusFirstFormError(formElement.value),
});
const destroy = async () => {
    const target = route('smartlinks.destroy', props.link.id);
    if (await ask({ message: '¿Eliminar tu SmartLink? La página pública dejará de existir.', confirmLabel: 'Eliminar SmartLink' })) router.delete(target);
};
const clicks = (b) => (b.id ? (props.stats?.by_block?.[b.id] ?? 0) : 0);
// Cuota de clics de un bloque sobre el total de clics (30 días): da contexto a «N clics».
const share = (b) => (props.stats?.clicks > 0 ? Math.round((clicks(b) / props.stats.clicks) * 100) : 0);

// Color: swatch + hex editable. El hex solo se acepta si es #RRGGBB válido (si no, la vista previa conserva el último bueno).
const hexDraft = computed({
    get: () => form.theme_color,
    set: (v) => {
        const value = String(v).trim();
        form.theme_color = value.startsWith('#') ? value : `#${value}`;
    },
});
</script>

<template>
    <Head title="SmartLinks" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">SmartLinks</h1>
        </template>

        <div class="space-y-5">
            <FeatureEmpty
                v-if="!brand"
                title="Crea una marca para tener su página de enlaces"
                description="SmartLinks es tu enlace en la bio: una página pública con todos tus enlaces, sin cookies y con contador de clics."
                :cards="[
                    { title: 'Una sola dirección', text: 'Pon /l/tu-marca en la bio de tus redes.', kind: 'link' },
                    { title: 'Bloques a medida', text: 'Botones, imágenes, video, iconos sociales y formulario de correo, con color y tema claro u oscuro.', kind: 'report' },
                    { title: 'Clics sin rastreo', text: 'Solo contadores diarios agregados, sin datos personales.', kind: 'chart' },
                ]"
            >
                <template #cta
                    ><Link :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link></template
                >
            </FeatureEmpty>

            <template v-else>
                <section v-if="link" class="card flex flex-wrap items-center justify-between gap-4 p-5 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-600">Tu página pública</p>
                        <a :href="link.public_url" target="_blank" rel="noopener" class="link">{{ link.public_url }}</a>
                        <a
                            :href="link.public_url"
                            target="_blank"
                            rel="noopener"
                            class="btn btn-secondary mt-2 inline-flex"
                            >Ver página pública</a
                        >
                    </div>
                    <dl class="flex gap-6">
                        <div
                            ><dt class="text-xs text-slate-600">Visitas (30 días)</dt
                            ><dd class="text-lg font-semibold tabular-nums">{{ stats.views }}</dd></div
                        >
                        <div
                            ><dt class="text-xs text-slate-600">Clics (30 días)</dt
                            ><dd class="text-lg font-semibold tabular-nums">{{ stats.clicks }}</dd></div
                        >
                    </dl>
                </section>

                <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <form ref="formElement" class="card space-y-4 p-5" @submit.prevent="submit">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="block text-sm">
                                <FormField
                                    id="sl-slug"
                                    :error="form.errors.slug"
                                    described-by="sl-slug-help"
                                    v-slot="{ control }"
                                >
                                    <label for="sl-slug" class="block text-xs font-medium text-slate-600"
                                        >Dirección de tu página</label
                                    >
                                    <div class="flex items-stretch">
                                        <span
                                            class="inline-flex items-center rounded-l-md border border-r-0 border-slate-300 bg-slate-50 px-2 text-sm text-slate-600"
                                            aria-hidden="true"
                                            >/l/</span
                                        >
                                        <input
                                            v-bind="control"
                                            v-model="form.slug"
                                            type="text"
                                            maxlength="40"
                                            required
                                            class="field !rounded-l-none"
                                        />
                                    </div>
                                    <p id="sl-slug-help" class="mt-1 text-xs text-slate-600"
                                        >De 3 a 40 caracteres: minúsculas, números y guiones. Es lo que pones en la bio
                                        de tus redes.</p
                                    >
                                </FormField>
                            </div>
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
                                    />
                                </label>
                            </FormField>
                        </div>
                        <FormField :error="form.errors.bio" v-slot="{ control }">
                            <label class="block text-sm">
                                <span class="block text-xs font-medium text-slate-600">Bio</span>
                                <textarea
                                    v-bind="control"
                                    v-model="form.bio"
                                    rows="2"
                                    maxlength="300"
                                    class="field"
                                ></textarea>
                            </label>
                        </FormField>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <FormField :error="form.errors.avatar_media_id" v-slot="{ control }">
                                <label class="block text-sm">
                                    <span class="block text-xs font-medium text-slate-600">Avatar</span>
                                    <select v-bind="control" v-model="form.avatar_media_id" class="field">
                                        <option :value="null">Sin avatar</option>
                                        <option v-for="m in images" :key="m.id" :value="m.id">{{ m.name }}</option>
                                    </select>
                                </label>
                            </FormField>
                            <div class="block text-sm">
                                <FormField id="sl-hex" :error="form.errors.theme_color" v-slot="{ control }">
                                    <label for="sl-hex" class="block text-xs font-medium text-slate-600">Color</label>
                                    <div class="flex items-center gap-2">
                                        <input
                                            v-model="form.theme_color"
                                            type="color"
                                            aria-label="Elegir color"
                                            class="h-9 w-9 shrink-0 cursor-pointer rounded border border-slate-300 p-0.5"
                                        />
                                        <input
                                            v-bind="control"
                                            v-model="hexDraft"
                                            type="text"
                                            maxlength="7"
                                            inputmode="text"
                                            spellcheck="false"
                                            class="field font-mono uppercase"
                                        />
                                    </div>
                                </FormField>
                            </div>
                            <label class="block text-sm">
                                <span class="block text-xs font-medium text-slate-600">Tema</span>
                                <select v-model="form.theme_mode" class="field">
                                    <option value="light">Claro</option>
                                    <option value="dark">Oscuro</option>
                                </select>
                            </label>
                        </div>
                        <label class="flex items-center gap-2 text-sm"
                            ><input v-model="form.published" type="checkbox" class="rounded border-slate-300" /> Página
                            publicada</label
                        >

                        <FormField :error="form.errors.blocks" v-slot="{ group }">
                            <fieldset v-bind="group" class="space-y-2">
                                <legend class="text-sm font-medium text-slate-700">Bloques</legend>

                                <FormField
                                    v-for="(b, i) in form.blocks"
                                    :key="blockKey(b)"
                                    :error="blockError(i, b.type)"
                                    v-slot="{ group }"
                                >
                                    <div
                                        v-bind="group"
                                        :data-block-index="i"
                                        role="group"
                                        :aria-label="`Bloque ${i + 1}`"
                                        class="rounded border border-slate-200 p-3"
                                    >
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span
                                                class="rounded bg-slate-100 px-2 py-0.5 text-xs"
                                                data-testid="block-type"
                                                >{{ typeLabel(b.type) }}</span
                                            >
                                            <div class="min-w-0 flex-1">
                                                <FormField :error="blockFieldError(i, 'label')" v-slot="{ control }">
                                                    <input
                                                        v-bind="control"
                                                        v-model="b.label"
                                                        type="text"
                                                        maxlength="100"
                                                        :aria-label="`Texto del bloque ${i + 1}`"
                                                        :placeholder="b.type === 'divider' ? 'Texto del separador (opcional)' : 'Texto'"
                                                        class="field min-w-0 flex-1"
                                                        data-testid="block-label"
                                                    />
                                                </FormField>
                                            </div>
                                            <span
                                                v-if="hasClicks(b) && b.id"
                                                class="text-xs text-slate-600 tabular-nums"
                                                data-testid="block-clicks"
                                                >{{ clicks(b) }} clics ({{ share(b) }} %) · 7 días: {{ clicks7(b) }} ·
                                                CTR {{ ctr(b) }} %</span
                                            >
                                            <button
                                                type="button"
                                                class="btn btn-secondary"
                                                data-block-shift="-1"
                                                :disabled="i === 0"
                                                :aria-label="`Subir bloque ${i + 1}`"
                                                @click="move(i, -1)"
                                                >↑</button
                                            >
                                            <button
                                                type="button"
                                                class="btn btn-secondary"
                                                data-block-shift="1"
                                                :disabled="i === form.blocks.length - 1"
                                                :aria-label="`Bajar bloque ${i + 1}`"
                                                @click="move(i, 1)"
                                                >↓</button
                                            >
                                            <button
                                                type="button"
                                                class="text-xs font-medium text-red-700 underline"
                                                @click="removeBlock(i)"
                                                >Quitar</button
                                            >
                                        </div>
                                        <div v-if="NEEDS_URL.includes(b.type)" class="min-w-0">
                                            <FormField :error="blockFieldError(i, 'url')" v-slot="{ control }">
                                                <input
                                                    v-bind="control"
                                                    v-model="b.url"
                                                    type="url"
                                                    :aria-label="`URL del bloque ${i + 1}`"
                                                    :placeholder="b.type === 'youtube' ? 'https://www.youtube.com/watch?v=…' : 'https://…'"
                                                    class="field mt-2"
                                                    data-testid="block-url"
                                                />
                                            </FormField>
                                        </div>
                                        <label v-if="b.type === 'image'" class="mt-2 block text-sm">
                                            <span class="block text-xs font-medium text-slate-600"
                                                >Imagen (de tu biblioteca)</span
                                            >
                                            <select
                                                v-model="b.media_id"
                                                class="field"
                                                :aria-label="`Imagen del bloque ${i + 1}`"
                                                data-testid="block-media"
                                            >
                                                <option :value="null">Elegir imagen…</option>
                                                <option
                                                    v-for="m in images"
                                                    :key="m.id"
                                                    :value="m.id"
                                                    >{{ m.name }}</option
                                                >
                                            </select>
                                        </label>
                                        <div
                                            v-if="b.type === 'social'"
                                            class="mt-2 space-y-2"
                                            data-testid="social-items"
                                        >
                                            <div
                                                v-for="(it, k) in b.data.items"
                                                :key="k"
                                                class="flex flex-wrap items-center gap-2"
                                            >
                                                <select
                                                    v-model="it.network"
                                                    class="field w-auto"
                                                    :aria-label="`Red del icono ${k + 1}`"
                                                >
                                                    <option
                                                        v-for="n in social_networks"
                                                        :key="n"
                                                        :value="n"
                                                        >{{ n }}</option
                                                    >
                                                </select>
                                                <input
                                                    v-model="it.url"
                                                    type="url"
                                                    class="field min-w-0 flex-1"
                                                    :aria-label="`URL del icono ${k + 1}`"
                                                    placeholder="https://…"
                                                />
                                                <button
                                                    type="button"
                                                    class="text-xs font-medium text-red-700 underline"
                                                    @click="removeSocial(b, k)"
                                                    >Quitar icono</button
                                                >
                                            </div>
                                            <button type="button" class="btn btn-secondary" @click="addSocial(b)"
                                                >Añadir icono</button
                                            >
                                        </div>
                                        <div v-if="b.type === 'email'" class="mt-2 grid gap-2 sm:grid-cols-2">
                                            <label class="block text-sm"
                                                ><span class="block text-xs font-medium text-slate-600"
                                                    >Texto del botón</span
                                                ><input v-model="b.data.cta" type="text" maxlength="30" class="field"
                                            /></label>
                                            <label class="block text-sm"
                                                ><span class="block text-xs font-medium text-slate-600"
                                                    >Aviso de consentimiento</span
                                                ><input
                                                    v-model="b.data.consent"
                                                    type="text"
                                                    maxlength="200"
                                                    class="field"
                                            /></label>
                                            <p class="text-xs text-slate-600 sm:col-span-2"
                                                >Las direcciones se guardan en tu cuenta (sin servicio externo ni correo
                                                de confirmación) y te avisamos cuando se llena. Puedes borrarlas una a
                                                una.
                                                <a
                                                    v-if="link"
                                                    :href="route('smartlinks.subscribers', link.id)"
                                                    class="underline"
                                                    data-testid="export-subscribers"
                                                    >Descargar suscriptores (CSV)</a
                                                >
                                                · {{ stats?.subscribers ?? 0 }} suscritos.</p
                                            >
                                        </div>
                                        <label v-if="b.type === 'posts'" class="mt-2 block text-sm">
                                            <span class="block text-xs font-medium text-slate-600"
                                                >Cuántas mostrar (1-6)</span
                                            >
                                            <input
                                                v-model.number="b.data.count"
                                                type="number"
                                                min="1"
                                                max="6"
                                                class="field w-24"
                                            />
                                        </label>
                                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                            <label class="block text-sm">
                                                <span class="block text-xs font-medium text-slate-600"
                                                    >Visible desde ({{ defaults.timezone }})</span
                                                >
                                                <input
                                                    v-model="b.visible_from"
                                                    type="datetime-local"
                                                    class="field"
                                                    :aria-label="`Visible desde del bloque ${i + 1}`"
                                                    data-testid="block-from"
                                                />
                                            </label>
                                            <label class="block text-sm">
                                                <span class="block text-xs font-medium text-slate-600"
                                                    >Visible hasta</span
                                                >
                                                <input
                                                    v-model="b.visible_until"
                                                    type="datetime-local"
                                                    class="field"
                                                    :aria-label="`Visible hasta del bloque ${i + 1}`"
                                                    data-testid="block-until"
                                                />
                                            </label>
                                        </div>
                                        <p
                                            v-if="visibilityText(b)"
                                            class="mt-1 text-xs font-medium text-amber-800"
                                            data-testid="block-visibility"
                                            >{{ visibilityText(b) }}</p
                                        >
                                    </div>
                                </FormField>
                                <div class="flex flex-wrap gap-2" data-testid="add-blocks">
                                    <button type="button" class="btn btn-secondary" data-add-block @click="addBlock('link')"
                                        >Añadir enlace</button
                                    >
                                    <button type="button" class="btn btn-secondary" @click="addBlock('header')"
                                        >Añadir cabecera</button
                                    >
                                    <button
                                        v-for="t in ['image', 'youtube', 'divider', 'social', 'email', 'posts']"
                                        :key="t"
                                        type="button"
                                        class="btn btn-secondary"
                                        :data-testid="`add-${t}`"
                                        @click="addBlock(t)"
                                        >Añadir {{ typeLabel(t).toLowerCase() }}</button
                                    >
                                </div>
                            </fieldset>
                        </FormField>

                        <div class="flex items-center gap-4">
                            <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                            <button
                                v-if="link"
                                type="button"
                                class="text-sm font-medium text-red-700 underline"
                                @click="destroy"
                                >Eliminar SmartLink</button
                            >
                        </div>
                    </form>

                    <SmartLinkSubscribers :link-id="link?.id" :count="stats?.subscribers ?? 0" :subscribers="subscribers" />

                    <SmartLinkPreview :draft="form" :images="images" :default-color="defaults.theme_color" />
                </div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
