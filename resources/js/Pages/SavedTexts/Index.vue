<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FormField from '@/Components/FormField.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import { useConfirm } from '@/Composables/useConfirm';

const ask = useConfirm();

/** Textos guardados (C-01a): fragmentos y grupos de hashtags por usuario; sin marca = «Todas las marcas». */
const props = defineProps({
    texts: Array,
    brands: Array,
    kinds: Object,
});

const search = ref('');
const brandFilter = ref(''); // '' = todos · 'all' = solo «Todas las marcas» · id de marca
const editing = ref(null);

const form = useForm({ kind: 'fragmento', title: '', body: '', brand_id: '' });

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.texts.filter((t) => {
        if (brandFilter.value === 'all' && t.brand_id !== null) return false;
        if (brandFilter.value !== '' && brandFilter.value !== 'all' && t.brand_id !== null && t.brand_id !== Number(brandFilter.value)) return false;
        return !q || t.title.toLowerCase().includes(q) || t.body.toLowerCase().includes(q);
    });
});

const reset = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
};

const edit = (t) => {
    editing.value = t.id;
    form.kind = t.kind;
    form.title = t.title;
    form.body = t.body;
    form.brand_id = t.brand_id ?? '';
    form.clearErrors();
};

const submit = () => {
    form.transform((d) => ({ ...d, brand_id: d.brand_id === '' ? null : d.brand_id }));
    const opts = { preserveScroll: true, onSuccess: reset };
    if (editing.value) form.put(route('saved-texts.update', editing.value), opts);
    else form.post(route('saved-texts.store'), opts);
};

const remove = async (t) => {
    if (await ask({ message: `¿Eliminar el texto «${t.title}»?`, confirmLabel: 'Eliminar texto' })) router.delete(route('saved-texts.destroy', t.id), { preserveScroll: true, onSuccess: () => editing.value === t.id && reset() });
};
</script>

<template>
    <Head title="Textos guardados" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-foreground-strong">Textos guardados</h1>
        </template>

        <div class="space-y-5">
            <form class="card space-y-3 p-5" data-testid="saved-text-form" @submit.prevent="submit">
                <h2
                    class="text-sm font-medium text-foreground-secondary"
                    >{{ editing ? 'Editar texto' : 'Nuevo texto' }}</h2
                >
                <div class="grid gap-3 sm:grid-cols-3">
                    <FormField :error="form.errors.kind" v-slot="{ control }">
                        <label class="block text-sm">
                            <span class="block text-xs font-medium text-foreground-muted">Tipo</span>
                            <select v-bind="control" v-model="form.kind" class="field">
                                <option v-for="(label, key) in kinds" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </label>
                    </FormField>
                    <FormField :error="form.errors.brand_id" v-slot="{ control }">
                        <label class="block text-sm">
                            <span class="block text-xs font-medium text-foreground-muted">Marca</span>
                            <select
                                v-bind="control"
                                v-model="form.brand_id"
                                class="field"
                                data-testid="saved-text-brand"
                            >
                                <option value="">Todas las marcas</option>
                                <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </label>
                    </FormField>
                    <FormField :error="form.errors.title" v-slot="{ control }">
                        <label class="block text-sm">
                            <span class="block text-xs font-medium text-foreground-muted">Título</span>
                            <input
                                v-bind="control"
                                v-model="form.title"
                                type="text"
                                maxlength="120"
                                required
                                class="field"
                            />
                        </label>
                    </FormField>
                </div>
                <FormField :error="form.errors.body" v-slot="{ control }">
                    <label class="block text-sm">
                        <span class="block text-xs font-medium text-foreground-muted">Texto</span>
                        <textarea
                            v-bind="control"
                            v-model="form.body"
                            rows="3"
                            maxlength="5000"
                            required
                            class="field"
                        ></textarea>
                    </label>
                </FormField>
                <div class="flex flex-wrap items-center gap-3">
                    <PrimaryButton
                        :disabled="form.processing"
                        >{{ editing ? 'Guardar cambios' : 'Guardar texto' }}</PrimaryButton
                    >
                    <button v-if="editing" type="button" class="btn btn-secondary" @click="reset">Cancelar</button>
                </div>
            </form>

            <section class="card">
                <div class="flex flex-wrap items-end gap-3 border-b px-5 py-3">
                    <h2 class="mr-auto text-sm font-medium text-foreground-secondary">Mis textos</h2>
                    <label class="block text-sm">
                        <span class="sr-only">Buscar</span>
                        <input v-model="search" type="search" placeholder="Buscar…" class="field" />
                    </label>
                    <label class="block text-sm">
                        <span class="sr-only">Filtrar por marca</span>
                        <select v-model="brandFilter" class="field" data-testid="saved-text-filter">
                            <option value="">Todas</option>
                            <option value="all">Solo «Todas las marcas»</option>
                            <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </label>
                </div>
                <p v-if="texts.length === 0" class="p-5 text-sm text-foreground-muted"
                    >Aún no tienes textos guardados. Crea uno arriba: firmas, llamadas a la acción o grupos de
                    hashtags.</p
                >
                <p v-else-if="visible.length === 0" class="p-5 text-sm text-foreground-muted"
                    >Ningún texto coincide con el filtro.</p
                >
                <ul v-else class="divide-y">
                    <li
                        v-for="t in visible"
                        :key="t.id"
                        class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 text-sm"
                        data-testid="saved-text-row"
                    >
                        <div class="min-w-0">
                            <p class="font-medium text-foreground-strong">{{ t.title }}</p>
                            <p class="whitespace-pre-line break-words text-foreground-muted">{{ t.body }}</p>
                            <p class="mt-1 text-xs text-foreground-subtle"
                                >{{ kinds[t.kind] }} · {{ t.brand ?? 'Todas las marcas' }}</p
                            >
                        </div>
                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                class="btn btn-secondary"
                                :aria-label="`Editar ${t.title}`"
                                @click="edit(t)"
                                >Editar</button
                            >
                            <button
                                type="button"
                                class="text-xs font-medium text-danger underline"
                                :aria-label="`Eliminar ${t.title}`"
                                @click="remove(t)"
                                >Eliminar</button
                            >
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
