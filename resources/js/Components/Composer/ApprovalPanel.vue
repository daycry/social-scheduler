<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useComposerContext } from '@/Composables/useComposer';
import { workflowRequest } from '@/Composables/useWorkflow';

/**
 * Aprobación (T-19). Sin post guardado: casilla «Requiere aprobación» (el post nace como borrador pendiente). Con post guardado:
 * muestra el estado (pendiente / aprobado / rechazado con el comentario) y permite pedir la aprobación. Un post sin aprobar no se programa.
 */
const { post, form, editable } = useComposerContext();
const busy = ref(false);
const error = ref('');

const STATUS = { pendiente: 'Pendiente de aprobación', aprobado: 'Aprobado', rechazado: 'Rechazado' };
const status = computed(() => post?.approval_status ?? null);

const request = async () => {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    const { ok, data } = await workflowRequest('POST', route('workflow.approval.request', post.id));
    busy.value = false;
    if (!ok) {
        error.value = data?.message ?? 'No se pudo pedir la aprobación.';
        return;
    }
    router.reload({ preserveScroll: true });
};
</script>

<template>
    <section class="min-w-0" data-testid="approval-panel">
        <h3 class="text-sm font-medium text-slate-900">Aprobación</h3>
        <template v-if="!post">
            <div class="mt-2 flex items-start gap-2">
                <input id="requires-approval" v-model="form.requires_approval" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-400 text-indigo-600" data-testid="requires-approval" />
                <label for="requires-approval" class="text-sm text-slate-800">
                    Requiere aprobación
                    <span class="block text-xs text-slate-600">Se guarda como borrador pendiente: no se programa ni se publica hasta que la apruebe el propietario o un aprobador de la marca.</span>
                </label>
            </div>
        </template>
        <template v-else>
            <p v-if="status" class="mt-2 text-sm" data-testid="approval-status">
                <span class="font-medium" :class="status === 'aprobado' ? 'text-emerald-800' : status === 'rechazado' ? 'text-red-800' : 'text-amber-800'">{{ STATUS[status] }}</span>
                <span v-if="status === 'aprobado'" class="block text-xs text-slate-600">Si editas el contenido, volverá a pendiente.</span>
            </p>
            <p v-if="status === 'rechazado' && post.approval_comment" class="mt-1 rounded-lg bg-red-50 p-2 text-sm text-red-900" data-testid="approval-comment">{{ post.approval_comment }}</p>
            <p v-if="!status" class="mt-2 text-xs text-slate-600">Esta publicación no requiere aprobación.</p>
            <button
                v-if="editable && status !== 'pendiente' && status !== 'aprobado'"
                type="button"
                class="btn btn-secondary mt-2 px-3 py-1 text-sm"
                :disabled="busy"
                data-testid="approval-request"
                @click="request"
            >{{ status === 'rechazado' ? 'Volver a pedir aprobación' : 'Pedir aprobación' }}</button>
            <p v-if="error" class="mt-1 text-xs text-red-700" role="alert">{{ error }}</p>
        </template>
    </section>
</template>
