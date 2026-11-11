<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { workflowError, workflowRequest } from '@/Composables/useWorkflow';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Aviso de publicación manual (T-19): texto listo para copiar, media descargable y confirmación «Ya la he publicado» con una URL
 * opcional https. Marcar es idempotente.
 */
const props = defineProps({ post: Object, targets: Array });

const urls = ref({});
const errors = ref({});
const copied = ref(null);
const busy = ref(null);

const copy = async (target) => {
    try {
        await navigator.clipboard.writeText(target.text ?? '');
        copied.value = target.target_id;
    } catch {
        copied.value = null;
    }
};
const markPublished = async (target) => {
    if (busy.value) return;
    busy.value = target.target_id;
    errors.value = { ...errors.value, [target.target_id]: '' };
    const url = (urls.value[target.target_id] ?? '').trim();
    const result = await workflowRequest('POST', route('workflow.manual.published', [props.post.id, target.target_id]), { url: url || null });
    busy.value = null;
    if (result.ok) {
        router.reload({ preserveScroll: true });
    } else {
        errors.value = { ...errors.value, [target.target_id]: workflowError(result) };
    }
};
</script>

<template>
    <Head title="Publicación manual" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Publicación manual</h1>
        </template>

        <div class="max-w-3xl space-y-4">
            <p class="text-sm text-slate-700">
                Estos destinos de «{{ post.brand_name }}» no se envían a la red. Copia el texto, descarga la media, publica a mano y confirma aquí.
                <Link :href="route('posts.edit', post.id)" class="link ms-1">Abrir la publicación</Link>
            </p>

            <p v-if="targets.length === 0" class="card p-4 text-sm text-slate-600">Esta publicación no tiene destinos manuales.</p>

            <article v-for="t in targets" :key="t.target_id" class="card space-y-3 p-4" :data-testid="`manual-target-${t.target_id}`">
                <header class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-semibold text-slate-900">{{ t.network_label }}<span v-if="t.account_name"> · {{ t.account_name }}</span></h2>
                    <StatusBadge :status="t.status" :label="t.published ? 'Publicada' : 'Pendiente de publicar'" />
                </header>

                <div>
                    <label :for="`manual-text-${t.target_id}`" class="block text-sm font-medium text-slate-800">Texto</label>
                    <textarea :id="`manual-text-${t.target_id}`" readonly rows="5" class="field mt-1 w-full" :value="t.text" data-testid="manual-text"></textarea>
                    <button type="button" class="btn btn-secondary mt-2 px-3 py-1 text-sm" data-testid="manual-copy" @click="copy(t)">Copiar texto</button>
                    <span v-if="copied === t.target_id" class="ms-2 text-xs text-emerald-800" role="status">Copiado.</span>
                </div>

                <div v-if="t.first_comment">
                    <p class="text-sm font-medium text-slate-800">Primer comentario</p>
                    <p class="whitespace-pre-line text-sm text-slate-700">{{ t.first_comment }}</p>
                </div>

                <div v-if="t.media.length">
                    <p class="text-sm font-medium text-slate-800">Media</p>
                    <ul class="mt-1 space-y-1">
                        <li v-for="m in t.media" :key="m.id" class="text-sm">
                            <a :href="m.download_url" download class="link" data-testid="manual-download">Descargar {{ m.name }}</a>
                            <span v-if="m.alt" class="block text-xs text-slate-600">Texto alternativo: {{ m.alt }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="!t.published" class="border-t border-slate-100 pt-3">
                    <label :for="`manual-url-${t.target_id}`" class="block text-sm font-medium text-slate-800">URL de la publicación (opcional, https)</label>
                    <input :id="`manual-url-${t.target_id}`" v-model="urls[t.target_id]" type="url" inputmode="url" class="field mt-1 w-full" placeholder="https://" data-testid="manual-url" />
                    <p v-if="errors[t.target_id]" class="mt-1 text-xs text-red-700" role="alert" data-testid="manual-error">{{ errors[t.target_id] }}</p>
                    <button type="button" class="btn btn-primary mt-2 px-3 py-1.5 text-sm" :disabled="busy === t.target_id" data-testid="manual-mark" @click="markPublished(t)">Ya la he publicado</button>
                </div>
                <p v-else class="text-sm text-emerald-800" role="status" data-testid="manual-done">
                    Marcada como publicada<template v-if="t.external_url">: <a :href="t.external_url" target="_blank" rel="noopener" class="link">{{ t.external_url }}</a></template>.
                </p>
            </article>
        </div>
    </AuthenticatedLayout>
</template>
