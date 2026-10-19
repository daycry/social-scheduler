<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MediaUploader from '@/Components/MediaUploader.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    brand: Object,
    accounts: Array,
    contentTypes: Object,
    post: Object,
    flash: Object,
});

const editable = computed(() => !props.post || props.post.editable);

const form = useForm({
    text: props.post?.text ?? '',
    scheduled_at: props.post?.scheduled_at ?? '',
    schedule: true,
    media: (props.post?.media ?? []).map((m) => m.id),
    targets: (props.post?.targets ?? []).map((t) => ({
        account_id: t.account_id,
        content_type: t.content_type,
        options: { ...(t.options ?? {}) },
    })),
});

// Metadatos de la media elegida (para mostrar nombre/duración y la previsualización).
const mediaItems = ref([...(props.post?.media ?? [])]);
const objectUrls = [];
const previews = ref({});
onBeforeUnmount(() => objectUrls.forEach((u) => URL.revokeObjectURL(u)));

const onUploaded = (asset, file) => {
    mediaItems.value.push(asset);
    form.media.push(asset.id);
    if (file && asset.kind === 'image') {
        const url = URL.createObjectURL(file);
        objectUrls.push(url);
        previews.value[asset.id] = url;
    }
};
const removeMedia = (id) => {
    mediaItems.value = mediaItems.value.filter((m) => m.id !== id);
    form.media = form.media.filter((m) => m !== id);
};
const move = (index, delta) => {
    const to = index + delta;
    if (to < 0 || to >= form.media.length) return;
    [form.media[index], form.media[to]] = [form.media[to], form.media[index]];
};
const mediaById = (id) => mediaItems.value.find((m) => m.id === id);

// Cuentas -> destinos
const targetFor = (accountId) => form.targets.find((t) => t.account_id === accountId);
const targetIndex = (accountId) => form.targets.findIndex((t) => t.account_id === accountId);
const typesOf = (network) => props.contentTypes[network] ?? [];
const typeInfo = (target, network) => typesOf(network).find((t) => t.value === target.content_type);

const toggleAccount = (account, on) => {
    if (on && !targetFor(account.id)) {
        form.targets.push({ account_id: account.id, content_type: typesOf(account.network)[0]?.value, options: {} });
    } else if (!on) {
        form.targets = form.targets.filter((t) => t.account_id !== account.id);
    }
};

// Validación en vivo (sin guardar): límites de media por red y tipo, y descripción demasiado larga.
const liveErrors = ref({});
let timer = null;
const validateNow = async () => {
    if (!editable.value || form.targets.length === 0) {
        liveErrors.value = {};
        return;
    }
    const xsrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    try {
        const response = await fetch(route('brands.posts.validate', props.brand.id), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
            // La fecha pendiente no es un error mientras se escribe: se valida solo contenido y destinos.
            body: JSON.stringify({ text: form.text, schedule: false, media: form.media, targets: form.targets }),
        });
        liveErrors.value = response.ok ? (await response.json()).errors : {};
    } catch {
        liveErrors.value = {};
    }
};
watch(() => [form.text, form.media, form.targets], () => {
    clearTimeout(timer);
    timer = setTimeout(validateNow, 500);
}, { deep: true });
if (props.post) validateNow();

const errorsFor = (accountId) => {
    const i = targetIndex(accountId);
    if (i < 0) return [];
    const server = form.errors[`targets.${i}`];
    return [...(liveErrors.value[`targets.${i}`] ?? []), ...(server && !(liveErrors.value[`targets.${i}`] ?? []).includes(server) ? [server] : [])];
};

const submit = (schedule) => {
    form.schedule = schedule;
    if (props.post) form.put(route('posts.update', props.post.id), { preserveScroll: true });
    else form.post(route('brands.posts.store', props.brand.id), { preserveScroll: true });
};

const remove = () => {
    if (confirm('¿Eliminar esta publicación?')) router.delete(route('posts.destroy', props.post.id));
};

const STATUS_CLASSES = {
    borrador: 'bg-gray-100 text-gray-700',
    programada: 'bg-blue-100 text-blue-800',
    publicando: 'bg-amber-100 text-amber-800',
    procesando: 'bg-amber-100 text-amber-800',
    publicada: 'bg-green-100 text-green-800',
    fallida: 'bg-red-100 text-red-800',
};

const previewText = computed(() => form.text || 'Aquí verás el texto de tu publicación.');
const firstPreview = computed(() => form.media.map((id) => previews.value[id]).find(Boolean));
const charCount = computed(() => form.text.length);
const accountName = (id) => props.accounts.find((a) => a.id === id)?.name ?? 'Cuenta eliminada';
</script>

<template>
    <Head :title="post ? 'Editar publicación' : 'Nueva publicación'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ post ? 'Editar publicación' : 'Nueva publicación' }} · {{ brand.name }}
                </h2>
                <Link :href="route('brands.show', brand.id)" class="text-sm text-indigo-600">Volver a la marca</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto grid max-w-6xl gap-6 sm:px-6 lg:grid-cols-3 lg:px-8">
                <form class="space-y-6 lg:col-span-2" @submit.prevent="submit(true)">
                    <p v-if="flash?.success" class="rounded bg-green-50 p-3 text-sm text-green-800" role="status">{{ flash.success }}</p>
                    <div v-if="post" class="flex items-center gap-2 text-sm">
                        <span class="rounded px-2 py-0.5" :class="STATUS_CLASSES[post.status]">{{ post.status_label }}</span>
                        <span v-if="!editable" class="text-gray-600">Esta publicación ya no se puede editar.</span>
                    </div>

                    <!-- Cuentas y tipo -->
                    <section class="rounded-lg bg-white p-4 shadow-sm">
                        <h3 class="mb-3 font-medium text-gray-900">Dónde publicar</h3>
                        <p v-if="accounts.length === 0" class="text-sm text-gray-600">
                            Esta marca no tiene cuentas conectadas.
                            <Link :href="route('brands.connections.index', brand.id)" class="text-indigo-600">Conectar redes</Link>
                        </p>
                        <ul class="space-y-3">
                            <li v-for="account in accounts" :key="account.id" class="rounded border border-gray-200 p-3">
                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        :checked="!!targetFor(account.id)"
                                        :disabled="!editable"
                                        @change="toggleAccount(account, $event.target.checked)"
                                    />
                                    <span class="font-medium">{{ account.network_label }}</span>
                                    <span class="text-gray-600">{{ account.name }}</span>
                                    <span v-if="account.status !== 'activa'" class="rounded bg-amber-100 px-2 text-xs text-amber-800">Requiere reconexión</span>
                                </label>

                                <div v-if="targetFor(account.id)" class="mt-2 space-y-2 pl-6">
                                    <div>
                                        <InputLabel :for="`type-${account.id}`" value="Tipo de contenido" />
                                        <select
                                            :id="`type-${account.id}`"
                                            v-model="targetFor(account.id).content_type"
                                            :disabled="!editable"
                                            class="mt-1 rounded-md border-gray-300 text-sm shadow-sm"
                                        >
                                            <option v-for="t in typesOf(account.network)" :key="t.value" :value="t.value">{{ t.label }}</option>
                                        </select>
                                    </div>
                                    <ul v-if="typeInfo(targetFor(account.id), account.network)?.limitations.length" class="list-disc space-y-0.5 pl-5 text-xs text-amber-700">
                                        <li v-for="l in typeInfo(targetFor(account.id), account.network).limitations" :key="l">{{ l }}</li>
                                    </ul>
                                    <label v-if="targetFor(account.id).content_type === 'instagram_reel'" class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" :disabled="!editable" :checked="targetFor(account.id).options.share_to_feed !== false" @change="targetFor(account.id).options.share_to_feed = $event.target.checked" />
                                        Compartir también en el feed
                                    </label>
                                    <div v-if="targetFor(account.id).content_type === 'facebook_text'">
                                        <InputLabel :for="`link-${account.id}`" value="Enlace (opcional)" />
                                        <input :id="`link-${account.id}`" v-model="targetFor(account.id).options.link" :disabled="!editable" type="url" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm" placeholder="https://" />
                                    </div>
                                    <ul v-if="errorsFor(account.id).length" class="space-y-0.5 text-sm text-red-600" role="alert">
                                        <li v-for="e in errorsFor(account.id)" :key="e">{{ e }}</li>
                                    </ul>
                                </div>
                            </li>
                        </ul>
                        <p v-if="form.errors.targets" class="mt-2 text-sm text-red-600">{{ form.errors.targets }}</p>
                    </section>

                    <!-- Texto -->
                    <section class="rounded-lg bg-white p-4 shadow-sm">
                        <InputLabel for="text" value="Texto" />
                        <textarea id="text" v-model="form.text" :disabled="!editable" rows="5" class="mt-1 w-full rounded-md border-gray-300 shadow-sm"></textarea>
                        <p class="text-right text-xs text-gray-500">{{ charCount }} caracteres</p>
                    </section>

                    <!-- Media -->
                    <section class="rounded-lg bg-white p-4 shadow-sm">
                        <h3 class="mb-2 font-medium text-gray-900">Imágenes y vídeos</h3>
                        <ul v-if="form.media.length" class="mb-3 space-y-1">
                            <li v-for="(id, i) in form.media" :key="id" class="flex items-center justify-between rounded border border-gray-200 px-2 py-1 text-sm">
                                <span>{{ i + 1 }}. {{ mediaById(id)?.name ?? `Archivo ${id}` }}
                                    <span class="text-gray-500">({{ mediaById(id)?.kind === 'video' ? `vídeo ${mediaById(id)?.duration ?? '?'} s` : 'imagen' }}<template v-if="mediaById(id)?.width"> · {{ mediaById(id).width }}×{{ mediaById(id).height }}</template>)</span>
                                </span>
                                <span v-if="editable" class="space-x-2">
                                    <button type="button" class="text-gray-600" @click="move(i, -1)">↑</button>
                                    <button type="button" class="text-gray-600" @click="move(i, 1)">↓</button>
                                    <button type="button" class="text-red-600" @click="removeMedia(id)">Quitar</button>
                                </span>
                            </li>
                        </ul>
                        <MediaUploader v-if="editable" @uploaded="onUploaded" />
                        <p v-if="form.errors.media" class="mt-1 text-sm text-red-600">{{ form.errors.media }}</p>
                    </section>

                    <!-- Fecha -->
                    <section class="rounded-lg bg-white p-4 shadow-sm">
                        <InputLabel for="scheduled_at" :value="`Fecha y hora (${brand.timezone})`" />
                        <input id="scheduled_at" v-model="form.scheduled_at" :disabled="!editable" type="datetime-local" class="mt-1 rounded-md border-gray-300 shadow-sm" />
                        <p v-if="form.errors.scheduled_at" class="mt-1 text-sm text-red-600">{{ form.errors.scheduled_at }}</p>
                    </section>

                    <p v-if="form.errors.post" class="text-sm text-red-600" role="alert">{{ form.errors.post }}</p>

                    <div v-if="editable" class="flex items-center gap-3">
                        <PrimaryButton type="submit" :disabled="form.processing">Programar</PrimaryButton>
                        <SecondaryButton type="button" :disabled="form.processing" @click="submit(false)">Guardar borrador</SecondaryButton>
                        <DangerButton v-if="post" type="button" class="ml-auto" @click="remove">Eliminar</DangerButton>
                    </div>
                </form>

                <!-- Previsualización y estado por red -->
                <aside class="space-y-4">
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <h3 class="mb-2 font-medium text-gray-900">Previsualización</h3>
                        <div class="space-y-2 rounded border border-gray-200 p-3">
                            <img v-if="firstPreview" :src="firstPreview" alt="" class="max-h-48 w-full rounded object-cover" />
                            <p v-else-if="form.media.length" class="rounded bg-gray-100 p-6 text-center text-sm text-gray-500">{{ mediaById(form.media[0])?.kind === 'video' ? 'Vídeo' : 'Imagen' }}</p>
                            <p class="whitespace-pre-line text-sm text-gray-800">{{ previewText }}</p>
                        </div>
                    </div>
                    <div v-if="post && post.targets.length" class="rounded-lg bg-white p-4 shadow-sm">
                        <h3 class="mb-2 font-medium text-gray-900">Estado por red</h3>
                        <ul class="space-y-2 text-sm">
                            <li v-for="t in post.targets" :key="`${t.account_id}-${t.content_type}`">
                                <span class="font-medium">{{ accountName(t.account_id) }}</span>
                                <span class="ml-2 rounded px-2 py-0.5 text-xs" :class="STATUS_CLASSES[t.status]">{{ t.status_label }}</span>
                                <a v-if="t.external_url" :href="t.external_url" target="_blank" rel="noopener" class="ml-2 text-indigo-600">Ver</a>
                                <p v-if="t.error_message" class="text-xs text-red-600">{{ t.error_message }}</p>
                            </li>
                        </ul>
                    </div>
                </aside>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
