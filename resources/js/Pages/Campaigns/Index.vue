<script setup>
import FormField from '@/Components/FormField.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

import { useConfirm } from '@/Composables/useConfirm';

const ask = useConfirm();

/** Campañas (C-03): etiquetas por marca con color y fechas que agrupan posts enteros. */
const props = defineProps({
    brand: Object,
    campaigns: Array,
});

const editing = ref(null);
const form = useForm({ brand_id: props.brand?.id ?? null, name: '', color: '#4f46e5', starts_on: '', ends_on: '' });

const reset = () => {
    editing.value = null;
    form.reset();
    form.brand_id = props.brand?.id ?? null;
    form.clearErrors();
};

const edit = (c) => {
    editing.value = c.id;
    form.name = c.name;
    form.color = c.color;
    form.starts_on = c.starts_on ?? '';
    form.ends_on = c.ends_on ?? '';
    form.clearErrors();
};

const submit = () => {
    form.brand_id = props.brand?.id ?? null;
    form.transform((d) => ({ ...d, starts_on: d.starts_on || null, ends_on: d.ends_on || null }));
    const opts = { preserveScroll: true, onSuccess: reset };
    if (editing.value) form.put(route('campaigns.update', editing.value), opts);
    else form.post(route('campaigns.store'), opts);
};

const archive = (c) => router.put(route('campaigns.archive', c.id), { archived: !c.archived }, { preserveScroll: true });

const remove = async (c) => {
    if (await ask({ message: `¿Eliminar la campaña «${c.name}»? Sus publicaciones se conservan, pero quedan sin campaña.`, confirmLabel: 'Eliminar campaña' })) {
        router.delete(route('campaigns.destroy', c.id), { preserveScroll: true, onSuccess: () => editing.value === c.id && reset() });
    }
};

const range = (c) => {
    if (c.starts_on && c.ends_on) return `${c.starts_on} → ${c.ends_on}`;
    if (c.starts_on) return `Desde ${c.starts_on}`;
    if (c.ends_on) return `Hasta ${c.ends_on}`;
    return 'Sin fechas';
};
</script>

<template>
    <Head title="Campañas" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-foreground-strong"
                >Campañas<span v-if="brand" class="font-normal text-foreground-muted"> · {{ brand.name }}</span></h1
            >
        </template>

        <div class="space-y-5">
            <p v-if="!brand" class="card p-5 text-sm text-foreground-muted"
                >Crea una marca para empezar a organizar tus publicaciones en campañas.</p
            >

            <template v-else>
                <form class="card space-y-3 p-5" data-testid="campaign-form" @submit.prevent="submit">
                    <h2
                        class="text-sm font-medium text-foreground-secondary"
                        >{{ editing ? 'Editar campaña' : 'Nueva campaña' }}</h2
                    >
                    <div class="grid gap-3 sm:grid-cols-4">
                        <FormField :error="form.errors.name" v-slot="{ control }">
                            <label class="block text-sm sm:col-span-2">
                                <span class="block text-xs font-medium text-foreground-muted">Nombre</span>
                                <input
                                    v-bind="control"
                                    v-model="form.name"
                                    type="text"
                                    maxlength="120"
                                    required
                                    class="field"
                                    data-testid="campaign-name"
                                />
                            </label>
                        </FormField>
                        <FormField :error="form.errors.starts_on" v-slot="{ control }">
                            <label class="block text-sm">
                                <span class="block text-xs font-medium text-foreground-muted">Inicio</span>
                                <input v-bind="control" v-model="form.starts_on" type="date" class="field" />
                            </label>
                        </FormField>
                        <FormField :error="form.errors.ends_on" v-slot="{ control }">
                            <label class="block text-sm">
                                <span class="block text-xs font-medium text-foreground-muted">Fin</span>
                                <input v-bind="control" v-model="form.ends_on" type="date" class="field" />
                            </label>
                        </FormField>
                    </div>
                    <FormField :error="form.errors.color" v-slot="{ control }">
                        <label class="flex items-center gap-3 text-sm">
                            <span class="text-xs font-medium text-foreground-muted">Color</span>
                            <input
                                v-bind="control"
                                v-model="form.color"
                                type="color"
                                class="h-9 w-14 cursor-pointer rounded border border-control-border bg-surface p-1"
                                data-testid="campaign-color"
                            />
                            <span class="text-xs text-foreground-muted">{{ form.color }}</span>
                        </label>
                    </FormField>
                    <div class="flex flex-wrap items-center gap-3">
                        <PrimaryButton
                            :disabled="form.processing"
                            >{{ editing ? 'Guardar cambios' : 'Crear campaña' }}</PrimaryButton
                        >
                        <button v-if="editing" type="button" class="btn btn-secondary" @click="reset">Cancelar</button>
                    </div>
                </form>

                <section class="card">
                    <h2 class="border-b px-5 py-3 text-sm font-medium text-foreground-secondary"
                        >Campañas de {{ brand.name }}</h2
                    >
                    <p v-if="campaigns.length === 0" class="p-5 text-sm text-foreground-muted"
                        >Aún no hay campañas en esta marca. Crea una arriba y asígnala a tus publicaciones desde el
                        compositor.</p
                    >
                    <ul v-else class="divide-y">
                        <li
                            v-for="c in campaigns"
                            :key="c.id"
                            class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 text-sm"
                            data-testid="campaign-row"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <span
                                    class="mt-0.5 h-4 w-4 shrink-0 rounded-full border border-control-border"
                                    :style="{ backgroundColor: c.color }"
                                    aria-hidden="true"
                                ></span>
                                <div class="min-w-0">
                                    <p class="break-words font-medium text-foreground-strong">
                                        <a v-if="c.show_url" :href="c.show_url" class="underline">{{ c.name }}</a>
                                        <template v-else>{{ c.name }}</template>
                                        <span
                                            v-if="c.archived"
                                            class="ms-2 rounded bg-surface-pressed px-1.5 py-0.5 text-xs font-medium text-foreground-secondary"
                                            >Archivada</span
                                        >
                                    </p>
                                    <p class="text-xs text-foreground-muted"
                                        >{{ range(c) }} · {{ c.posts_count }}
                                        {{ c.posts_count === 1 ? 'publicación' : 'publicaciones' }}</p
                                    >
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    :aria-label="`Editar ${c.name}`"
                                    @click="edit(c)"
                                    >Editar</button
                                >
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    :aria-label="`${c.archived ? 'Restaurar' : 'Archivar'} ${c.name}`"
                                    @click="archive(c)"
                                    >{{ c.archived ? 'Restaurar' : 'Archivar' }}</button
                                >
                                <button
                                    type="button"
                                    class="text-xs font-medium text-danger underline"
                                    :aria-label="`Eliminar ${c.name}`"
                                    @click="remove(c)"
                                    >Eliminar</button
                                >
                            </div>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
