<script setup>
import FormField from '@/Components/FormField.vue';
import { createDateFormatter } from '@/lib/format';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, watchEffect } from 'vue';

import { useConfirm } from '@/Composables/useConfirm';

const ask = useConfirm();

/** Autolistas (PA-09): cola de contenido + franjas semanales (en la zona de la marca) que genera publicaciones programadas. */
const props = defineProps({
    brand: Object,
    accounts: Array,
    templates: Array,
    autolists: Array,
});

const DAYS = [
    { value: 1, label: 'Lunes' },
    { value: 2, label: 'Martes' },
    { value: 3, label: 'Miércoles' },
    { value: 4, label: 'Jueves' },
    { value: 5, label: 'Viernes' },
    { value: 6, label: 'Sábado' },
    { value: 7, label: 'Domingo' },
];
const dayLabel = (d) => DAYS.find((x) => x.value === d)?.label ?? d;

const form = useForm({
    brand_id: props.brand?.id ?? '',
    name: '',
    recycle: false,
    rss_url: '',
    targets: [],
    slots: [{ day: 1, time: '10:00' }],
});

// Un destino por cuenta marcada, con su tipo de contenido.
const chosen = reactive({});
const toggleAccount = (a) => {
    if (chosen[a.id]) delete chosen[a.id];
    else chosen[a.id] = a.content_types[0]?.value ?? '';
};
const addSlot = () => form.slots.push({ day: 1, time: '10:00' });
const removeSlot = (i) => form.slots.splice(i, 1);

const submit = () => {
    form.targets = Object.entries(chosen).map(([account_id, content_type]) => ({ account_id: Number(account_id), content_type }));
    form.post(route('autolists.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('name', 'recycle', 'rss_url');
            Object.keys(chosen).forEach((k) => delete chosen[k]);
        },
    });
};

const itemForm = reactive({});
watchEffect(() => props.autolists.forEach((l) => (itemForm[l.id] ??= { text: '', template: '', rss: l.rss_url ?? '', rssError: null })));
const addItem = (list, payload) =>
    router.post(route('autolists.items.store', list.id), payload, {
        preserveScroll: true,
        onSuccess: () => Object.assign(itemForm[list.id], { text: '', template: '' }),
    });
// Feed RSS/Atom de la lista: vacío lo quita. El estado del último sondeo llega en la propia lista.
const saveFeed = (list) =>
    router.patch(
        route('autolists.update', list.id),
        { rss_url: itemForm[list.id]?.rss ?? '' },
        {
            preserveScroll: true,
            onSuccess: () => (itemForm[list.id].rssError = null),
            onError: (errors) => (itemForm[list.id].rssError = errors.rss_url ?? null),
        },
    );
const addText = (list) => addItem(list, { text: itemForm[list.id]?.text ?? '' });
const addTemplate = (list) => itemForm[list.id]?.template && addItem(list, { post_template_id: Number(itemForm[list.id].template) });
const removeItem = async (list, item) => {
    if (await ask({ message: '¿Quitar este contenido de la autolista?', confirmLabel: 'Quitar contenido' })) {
        router.delete(route('autolists.items.destroy', [list.id, item.id]), { preserveScroll: true });
    }
};
const toggle = (list) => router.patch(route('autolists.update', list.id), { active: !list.active }, { preserveScroll: true });
const generate = (list) => router.post(route('autolists.generate', list.id), {}, { preserveScroll: true });
const remove = async (list) => {
    if (await ask({ message: `¿Eliminar la autolista «${list.name}»? Las publicaciones ya generadas se conservan.`, confirmLabel: 'Eliminar autolista' })) router.delete(route('autolists.destroy', list.id), { preserveScroll: true });
};
const accountOf = (id) => props.accounts.find((a) => a.id === id);
const fmtDate = computed(() => createDateFormatter(props.brand?.timezone, { dateStyle: 'medium', timeStyle: 'short' }, 'nunca'));
</script>

<template>
    <Head title="Autolistas" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-foreground-strong">Autolistas</h1>
        </template>

        <div class="space-y-5">
            <FeatureEmpty
                v-if="!brand"
                title="Autolistas"
                description="Crea una marca para programar colas de contenido con franjas semanales."
                :cards="[
                    { title: 'Franjas semanales', text: 'Elige día y hora, en la zona horaria de tu marca.', kind: 'calendar' },
                    { title: 'Cola de contenido', text: 'Plantillas de la Biblioteca o textos con archivos.', kind: 'report' },
                    { title: 'Reciclado', text: 'Al acabar la cola, vuelve a empezar si quieres.', kind: 'chart' },
                ]"
            />

            <template v-else>
                <form class="card space-y-4 p-5" @submit.prevent="submit">
                    <h2 class="text-sm font-medium text-foreground-secondary">Nueva autolista · {{ brand.name }}</h2>
                    <FormField :error="form.errors.name" v-slot="{ control }">
                        <label class="block text-sm">
                            <span class="block text-xs font-medium text-foreground-subtle">Nombre</span>
                            <input
                                v-bind="control"
                                v-model="form.name"
                                type="text"
                                maxlength="120"
                                required
                                class="field max-w-md"
                            />
                        </label>
                    </FormField>

                    <FormField :error="form.errors.rss_url" v-slot="{ control }">
                        <label class="block text-sm">
                            <span class="block text-xs font-medium text-foreground-subtle"
                                >Feed RSS o Atom (opcional)</span
                            >
                            <input
                                v-bind="control"
                                v-model="form.rss_url"
                                type="url"
                                maxlength="2048"
                                placeholder="https://tu-blog.com/feed.xml"
                                class="field max-w-xl"
                            />
                        </label>
                        <p class="mt-1 text-xs text-foreground-subtle"
                            >Las entradas nuevas del feed se añaden a la cola cada vez que se genera la lista.</p
                        >
                    </FormField>

                    <FormField :error="form.errors.targets" v-slot="{ control }">
                        <fieldset v-bind="control">
                            <legend class="text-xs font-medium text-foreground-subtle">Cuentas de destino</legend>
                            <p v-if="accounts.length === 0" class="mt-1 text-sm text-foreground-subtle"
                                >Conecta una cuenta en esta marca.</p
                            >
                            <ul class="mt-1 space-y-2">
                                <li v-for="a in accounts" :key="a.id" class="flex flex-wrap items-center gap-3 text-sm">
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" :checked="a.id in chosen" @change="toggleAccount(a)" />
                                        <NetworkIcon :network="a.network" size="sm" />
                                        {{ a.name }}
                                    </label>
                                    <select
                                        v-if="a.id in chosen"
                                        v-model="chosen[a.id]"
                                        class="field w-auto"
                                        :aria-label="`Tipo de contenido de ${a.name}`"
                                    >
                                        <option
                                            v-for="t in a.content_types"
                                            :key="t.value"
                                            :value="t.value"
                                            >{{ t.label }}</option
                                        >
                                    </select>
                                </li>
                            </ul>
                        </fieldset>
                    </FormField>

                    <FormField :error="form.errors.slots" v-slot="{ control }">
                        <fieldset v-bind="control">
                            <legend class="text-xs font-medium text-foreground-subtle"
                                >Franjas semanales ({{ brand.timezone }})</legend
                            >
                            <div v-for="(s, i) in form.slots" :key="i" class="mt-2 flex items-center gap-2">
                                <select
                                    v-model.number="s.day"
                                    class="field w-auto"
                                    :aria-label="`Día de la franja ${i + 1}`"
                                >
                                    <option v-for="d in DAYS" :key="d.value" :value="d.value">{{ d.label }}</option>
                                </select>
                                <input
                                    v-model="s.time"
                                    type="time"
                                    required
                                    class="field w-auto"
                                    :aria-label="`Hora de la franja ${i + 1}`"
                                />
                                <button
                                    v-if="form.slots.length > 1"
                                    type="button"
                                    class="text-xs text-danger underline"
                                    @click="removeSlot(i)"
                                    >Quitar</button
                                >
                            </div>
                            <button type="button" class="btn btn-secondary mt-2" @click="addSlot">Añadir franja</button>
                        </fieldset>
                    </FormField>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.recycle" type="checkbox" />
                        Reciclar: al terminar la cola, volver a empezar
                    </label>
                    <FormField :error="form.errors.brand_id" />
                    <PrimaryButton :disabled="form.processing">Crear autolista</PrimaryButton>
                </form>

                <section v-for="l in autolists" :key="l.id" class="card">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-3">
                        <div>
                            <h2 class="text-sm font-semibold text-foreground-strong">
                                {{ l.name }}
                                <span
                                    class="ms-2 rounded-full px-2 py-0.5 text-xs"
                                    :class="l.active ? 'bg-success-soft text-success-ink' : 'bg-surface-hover text-foreground-muted'"
                                    >{{ l.active ? 'Activa' : 'En pausa' }}</span
                                >
                            </h2>
                            <p class="text-xs text-foreground-subtle">
                                {{ l.slots.map((s) => `${dayLabel(s.day)} ${s.time}`).join(' · ') }} ·
                                {{ l.recycle ? 'con reciclado' : 'sin reciclado' }} · última generación:
                                {{ fmtDate(l.last_generated_at) }}
                            </p>
                            <p v-if="l.rss_url" class="mt-1 break-all text-xs text-foreground-subtle">
                                Feed: {{ l.rss_url }} · último sondeo: {{ fmtDate(l.rss_checked_at) }}
                            </p>
                            <p v-if="l.rss_error" class="mt-1 text-xs text-danger"
                                >Último sondeo con error: {{ l.rss_error }}</p
                            >
                            <p class="mt-1 flex items-center gap-1 text-xs text-foreground-subtle">
                                <template v-for="t in l.targets" :key="t.account_id + t.content_type">
                                    <NetworkIcon
                                        v-if="accountOf(t.account_id)"
                                        :network="accountOf(t.account_id).network"
                                        size="sm"
                                    />
                                </template>
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="button" class="btn btn-secondary" @click="generate(l)">Generar ahora</button>
                            <button
                                type="button"
                                class="btn btn-secondary"
                                @click="toggle(l)"
                                >{{ l.active ? 'Pausar' : 'Activar' }}</button
                            >
                            <button type="button" class="text-xs text-danger underline" @click="remove(l)"
                                >Eliminar</button
                            >
                        </div>
                    </header>

                    <p v-if="l.items.length === 0" class="px-5 py-4 text-sm text-foreground-subtle"
                        >La cola está vacía: añade contenido para que se generen publicaciones.</p
                    >
                    <ol v-else class="divide-y">
                        <li
                            v-for="(it, idx) in l.items"
                            :key="it.id"
                            class="flex items-center justify-between gap-3 px-5 py-2 text-sm"
                        >
                            <span class="min-w-0 truncate">
                                <span class="me-2 text-xs text-foreground-subtle">{{ idx + 1 }}.</span
                                >{{ it.label || '(solo archivos)' }}
                                <span v-if="it.is_template" class="ms-2 text-xs text-accent">plantilla</span>
                                <span v-if="idx === l.cursor % l.items.length" class="ms-2 text-xs text-success-ink"
                                    >siguiente</span
                                >
                            </span>
                            <button type="button" class="text-xs text-danger underline" @click="removeItem(l, it)"
                                >Quitar</button
                            >
                        </li>
                    </ol>

                    <div class="grid gap-3 border-t px-5 py-3 md:grid-cols-2">
                        <form class="flex items-end gap-2" @submit.prevent="addText(l)">
                            <label class="block flex-1 text-sm">
                                <span class="block text-xs font-medium text-foreground-subtle">Texto nuevo</span>
                                <input v-model="itemForm[l.id].text" type="text" maxlength="10000" class="field" />
                            </label>
                            <button type="submit" class="btn btn-secondary">Añadir</button>
                        </form>
                        <form class="flex items-end gap-2" @submit.prevent="addTemplate(l)">
                            <label class="block flex-1 text-sm">
                                <span class="block text-xs font-medium text-foreground-subtle"
                                    >Plantilla de la Biblioteca</span
                                >
                                <select v-model="itemForm[l.id].template" class="field">
                                    <option value="">Elige una…</option>
                                    <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                            </label>
                            <button type="submit" class="btn btn-secondary">Añadir</button>
                        </form>
                        <form class="md:col-span-2" @submit.prevent="saveFeed(l)">
                            <FormField :error="itemForm[l.id].rssError" v-slot="{ control }">
                                <div class="flex items-end gap-2">
                                    <label class="block flex-1 text-sm">
                                        <span class="block text-xs font-medium text-foreground-subtle"
                                            >Feed RSS o Atom</span
                                        >
                                        <input
                                            v-bind="control"
                                            v-model="itemForm[l.id].rss"
                                            type="url"
                                            maxlength="2048"
                                            placeholder="https://tu-blog.com/feed.xml"
                                            class="field"
                                        />
                                    </label>
                                    <button type="submit" class="btn btn-secondary">Guardar feed</button>
                                </div>
                            </FormField>
                        </form>
                    </div>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
