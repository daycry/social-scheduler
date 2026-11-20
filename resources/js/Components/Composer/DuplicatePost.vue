<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import BaseDialog from '@/Components/BaseDialog.vue';

const props = defineProps({
    postId: { type: Number, required: true },
    brandId: { type: Number, required: true },
    dirty: Boolean,
    saving: Boolean,
});
const page = usePage();
const brands = computed(() => {
    const shell = /** @type {import('@/types/shell').Shell | null | undefined} */ (page.props.shell);
    return shell?.brands ?? [];
});
const open = ref(false);
const form = useForm({ brand_id: props.brandId });
const blocked = computed(() => props.dirty || props.saving || form.processing);
const errors = computed(() => Object.entries(form.errors));

function start() {
    if (blocked.value) return;
    form.clearErrors();
    form.brand_id = props.brandId;
    open.value = true;
}

function duplicate() {
    if (blocked.value || !brands.value.some((brand) => brand.id === Number(form.brand_id))) return;
    form.post(route('posts.duplicate', props.postId), {
        errorBag: 'duplicate',
        preserveScroll: true,
        // Un error conserva el diálogo. Un duplicado nuevo debe montar su propio compositor.
        preserveState: (next) => {
            const post = /** @type {{id?: number} | null | undefined} */ (next.props.post);
            return post?.id === props.postId;
        },
        onSuccess: () => { open.value = false; },
    });
}
</script>

<template>
    <div class="flex min-w-0 flex-wrap items-center gap-2">
        <button
            type="button"
            class="btn-secondary"
            :disabled="blocked"
            :aria-describedby="dirty ? 'duplicate-post-dirty' : undefined"
            @click="start"
            >Duplicar</button
        >
        <p v-if="dirty" id="duplicate-post-dirty" class="text-xs text-slate-600"
            >Guarda los cambios antes de duplicar.</p
        >
    </div>
    <BaseDialog
        :open="open"
        :closeable="!form.processing"
        aria-labelledby="duplicate-post-title"
        class="w-[calc(100vw-2rem)] max-w-lg rounded-xl p-6 shadow-xl backdrop:bg-slate-900/40"
        @close="open = false"
    >
        <form class="space-y-4" @submit.prevent="duplicate">
            <h2 id="duplicate-post-title" class="text-lg font-semibold text-slate-900">Duplicar publicación</h2>
            <p class="text-sm text-slate-600"
                >Crea un borrador independiente a partir de la versión guardada. Revisa las cuentas de destino antes de
                programarlo.</p
            >
            <div class="space-y-1">
                <label for="duplicate-brand" class="block text-sm font-medium text-slate-800">Marca de destino</label>
                <select
                    id="duplicate-brand"
                    v-model.number="form.brand_id"
                    class="field w-full"
                    :disabled="form.processing"
                    required
                >
                    <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                </select>
            </div>
            <div v-if="errors.length" role="alert" class="text-sm text-red-700">
                <p v-for="[field, error] in errors" :key="field">{{ error }}</p>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" class="btn-secondary" :disabled="form.processing" @click="open = false"
                    >Cancelar</button
                >
                <button
                    type="submit"
                    class="btn-primary"
                    :disabled="blocked || !brands.length"
                    >{{ form.processing ? 'Duplicando…' : 'Crear borrador duplicado' }}</button
                >
            </div>
        </form>
    </BaseDialog>
</template>
