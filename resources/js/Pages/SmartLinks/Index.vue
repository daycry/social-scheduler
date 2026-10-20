<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

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
    flash: Object,
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

const addBlock = (type) => {
    if (form.blocks.length < props.defaults.max_blocks) form.blocks.push({ id: null, type, label: '', url: type === 'link' ? 'https://' : null });
};
const removeBlock = (i) => form.blocks.splice(i, 1);
const move = (i, d) => {
    const j = i + d;
    if (j < 0 || j >= form.blocks.length) return;
    [form.blocks[i], form.blocks[j]] = [form.blocks[j], form.blocks[i]];
};
const submit = () => form.post(route('smartlinks.save'), { preserveScroll: true });
const destroy = () => {
    if (confirm('¿Eliminar tu SmartLink? La página pública dejará de existir.')) router.delete(route('smartlinks.destroy', props.link.id));
};
const clicks = (b) => (b.id ? (props.stats?.by_block?.[b.id] ?? 0) : 0);
</script>

<template>
    <Head title="SmartLinks" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">SmartLinks</h1>
        </template>

        <div class="space-y-5">
            <p v-if="flash?.success" class="text-sm text-emerald-700" role="status">{{ flash.success }}</p>
            <FeatureEmpty
                v-if="!brand"
                title="Crea una marca para tener su página de enlaces"
                description="SmartLinks es tu enlace en la bio: una página pública con todos tus enlaces, sin cookies y con contador de clics."
                :cards="[
                    { title: 'Una sola dirección', text: 'Pon /l/tu-marca en la bio de tus redes.', kind: 'link' },
                    { title: 'Bloques a medida', text: 'Enlaces y cabeceras, con color y tema claro u oscuro.', kind: 'report' },
                    { title: 'Clics sin rastreo', text: 'Solo contadores diarios agregados, sin datos personales.', kind: 'chart' },
                ]"
            >
                <template #cta><Link :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link></template>
            </FeatureEmpty>

            <template v-else>
                <section v-if="link" class="card flex flex-wrap items-center justify-between gap-4 p-5 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-600">Tu página pública</p>
                        <a :href="link.public_url" target="_blank" rel="noopener" class="link">{{ link.public_url }}</a>
                    </div>
                    <dl class="flex gap-6">
                        <div><dt class="text-xs text-slate-600">Visitas (30 días)</dt><dd class="text-lg font-semibold tabular-nums">{{ stats.views }}</dd></div>
                        <div><dt class="text-xs text-slate-600">Clics (30 días)</dt><dd class="text-lg font-semibold tabular-nums">{{ stats.clicks }}</dd></div>
                    </dl>
                </section>

                <form class="card space-y-4 p-5" @submit.prevent="submit">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm">
                            <span class="block text-xs font-medium text-slate-600">Enlace (/l/…)</span>
                            <input v-model="form.slug" type="text" maxlength="40" required class="field" />
                            <InputError :message="form.errors.slug" />
                        </label>
                        <label class="block text-sm">
                            <span class="block text-xs font-medium text-slate-600">Título</span>
                            <input v-model="form.title" type="text" maxlength="100" required class="field" />
                            <InputError :message="form.errors.title" />
                        </label>
                    </div>
                    <label class="block text-sm">
                        <span class="block text-xs font-medium text-slate-600">Bio</span>
                        <textarea v-model="form.bio" rows="2" maxlength="300" class="field"></textarea>
                        <InputError :message="form.errors.bio" />
                    </label>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="block text-sm">
                            <span class="block text-xs font-medium text-slate-600">Avatar</span>
                            <select v-model="form.avatar_media_id" class="field">
                                <option :value="null">Sin avatar</option>
                                <option v-for="m in images" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </select>
                            <InputError :message="form.errors.avatar_media_id" />
                        </label>
                        <label class="block text-sm">
                            <span class="block text-xs font-medium text-slate-600">Color</span>
                            <input v-model="form.theme_color" type="color" class="h-10 w-full rounded border border-slate-300" />
                            <InputError :message="form.errors.theme_color" />
                        </label>
                        <label class="block text-sm">
                            <span class="block text-xs font-medium text-slate-600">Tema</span>
                            <select v-model="form.theme_mode" class="field">
                                <option value="light">Claro</option>
                                <option value="dark">Oscuro</option>
                            </select>
                        </label>
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.published" type="checkbox" class="rounded border-slate-300" /> Página publicada</label>

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-medium text-slate-700">Bloques</legend>
                        <InputError :message="form.errors.blocks" />
                        <div v-for="(b, i) in form.blocks" :key="b.id ?? `n${i}`" class="rounded border border-slate-200 p-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ b.type === 'link' ? 'Enlace' : 'Cabecera' }}</span>
                                <input v-model="b.label" type="text" maxlength="100" :aria-label="`Texto del bloque ${i + 1}`" placeholder="Texto" class="field min-w-0 flex-1" />
                                <span v-if="b.type === 'link' && b.id" class="text-xs text-slate-600">{{ clicks(b) }} clics</span>
                                <button type="button" class="btn btn-secondary" :aria-label="`Subir bloque ${i + 1}`" @click="move(i, -1)">↑</button>
                                <button type="button" class="btn btn-secondary" :aria-label="`Bajar bloque ${i + 1}`" @click="move(i, 1)">↓</button>
                                <button type="button" class="text-xs font-medium text-red-700 underline" @click="removeBlock(i)">Quitar</button>
                            </div>
                            <input v-if="b.type === 'link'" v-model="b.url" type="url" :aria-label="`URL del bloque ${i + 1}`" placeholder="https://…" class="field mt-2" />
                            <InputError :message="form.errors[`blocks.${i}.label`] || form.errors[`blocks.${i}.url`]" />
                        </div>
                        <div class="flex gap-2">
                            <button type="button" class="btn btn-secondary" @click="addBlock('link')">Añadir enlace</button>
                            <button type="button" class="btn btn-secondary" @click="addBlock('header')">Añadir cabecera</button>
                        </div>
                    </fieldset>

                    <div class="flex items-center gap-4">
                        <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                        <button v-if="link" type="button" class="text-sm font-medium text-red-700 underline" @click="destroy">Eliminar SmartLink</button>
                    </div>
                </form>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
