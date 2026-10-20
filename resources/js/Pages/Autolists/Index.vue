<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive, watchEffect } from 'vue';

/** Autolistas (PA-09): cola de contenido + franjas semanales (en la zona de la marca) que genera publicaciones programadas. */
const props = defineProps({
    brand: Object,
    accounts: Array,
    templates: Array,
    autolists: Array,
    flash: Object,
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
            form.reset('name', 'recycle');
            Object.keys(chosen).forEach((k) => delete chosen[k]);
        },
    });
};

const itemForm = reactive({});
watchEffect(() => props.autolists.forEach((l) => (itemForm[l.id] ??= { text: '', template: '' })));
const addItem = (list, payload) => router.post(route('autolists.items.store', list.id), payload, { preserveScroll: true, onSuccess: () => (itemForm[list.id] = { text: '', template: '' }) });
const addText = (list) => addItem(list, { text: itemForm[list.id]?.text ?? '' });
const addTemplate = (list) => itemForm[list.id]?.template && addItem(list, { post_template_id: Number(itemForm[list.id].template) });
const removeItem = (list, item) => router.delete(route('autolists.items.destroy', [list.id, item.id]), { preserveScroll: true });
const toggle = (list) => router.patch(route('autolists.update', list.id), { active: !list.active }, { preserveScroll: true });
const generate = (list) => router.post(route('autolists.generate', list.id), {}, { preserveScroll: true });
const remove = (list) => {
    if (confirm(`¿Eliminar la autolista «${list.name}»? Las publicaciones ya generadas se conservan.`)) router.delete(route('autolists.destroy', list.id), { preserveScroll: true });
};
const accountOf = (id) => props.accounts.find((a) => a.id === id);
const fmtDate = (iso) => (iso ? new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : 'nunca');
</script>

<template>
    <Head title="Autolistas" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Autolistas</h1>
        </template>

        <div class="space-y-5">
            <p v-if="flash?.success" class="text-sm text-emerald-700" role="status">{{ flash.success }}</p>
            <p v-if="flash?.error" class="text-sm text-red-600" role="alert">{{ flash.error }}</p>

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
                    <h2 class="text-sm font-medium text-slate-700">Nueva autolista · {{ brand.name }}</h2>
                    <label class="block text-sm">
                        <span class="block text-xs font-medium text-slate-500">Nombre</span>
                        <input v-model="form.name" type="text" maxlength="120" required class="field max-w-md" />
                        <InputError :message="form.errors.name" />
                    </label>

                    <fieldset>
                        <legend class="text-xs font-medium text-slate-500">Cuentas de destino</legend>
                        <p v-if="accounts.length === 0" class="mt-1 text-sm text-slate-500">Conecta una cuenta en esta marca.</p>
                        <ul class="mt-1 space-y-2">
                            <li v-for="a in accounts" :key="a.id" class="flex flex-wrap items-center gap-3 text-sm">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" :checked="a.id in chosen" @change="toggleAccount(a)" />
                                    <NetworkIcon :network="a.network" size="sm" />
                                    {{ a.name }}
                                </label>
                                <select v-if="a.id in chosen" v-model="chosen[a.id]" class="field w-auto" :aria-label="`Tipo de contenido de ${a.name}`">
                                    <option v-for="t in a.content_types" :key="t.value" :value="t.value">{{ t.label }}</option>
                                </select>
                            </li>
                        </ul>
                        <InputError :message="form.errors.targets" />
                    </fieldset>

                    <fieldset>
                        <legend class="text-xs font-medium text-slate-500">Franjas semanales ({{ brand.timezone }})</legend>
                        <div v-for="(s, i) in form.slots" :key="i" class="mt-2 flex items-center gap-2">
                            <select v-model.number="s.day" class="field w-auto" :aria-label="`Día de la franja ${i + 1}`">
                                <option v-for="d in DAYS" :key="d.value" :value="d.value">{{ d.label }}</option>
                            </select>
                            <input v-model="s.time" type="time" required class="field w-auto" :aria-label="`Hora de la franja ${i + 1}`" />
                            <button v-if="form.slots.length > 1" type="button" class="text-xs text-red-600 underline" @click="removeSlot(i)">Quitar</button>
                        </div>
                        <button type="button" class="btn btn-secondary mt-2" @click="addSlot">Añadir franja</button>
                        <InputError :message="form.errors.slots" />
                    </fieldset>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.recycle" type="checkbox" />
                        Reciclar: al terminar la cola, volver a empezar
                    </label>
                    <InputError :message="form.errors.brand_id" />
                    <PrimaryButton :disabled="form.processing">Crear autolista</PrimaryButton>
                </form>

                <section v-for="l in autolists" :key="l.id" class="card">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-3">
                        <div>
                            <h2 class="text-sm font-semibold text-slate-900">
                                {{ l.name }}
                                <span class="ms-2 rounded-full px-2 py-0.5 text-xs" :class="l.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ l.active ? 'Activa' : 'En pausa' }}</span>
                            </h2>
                            <p class="text-xs text-slate-500">
                                {{ l.slots.map((s) => `${dayLabel(s.day)} ${s.time}`).join(' · ') }} · {{ l.recycle ? 'con reciclado' : 'sin reciclado' }} · última generación: {{ fmtDate(l.last_generated_at) }}
                            </p>
                            <p class="mt-1 flex items-center gap-1 text-xs text-slate-500">
                                <template v-for="t in l.targets" :key="t.account_id + t.content_type">
                                    <NetworkIcon v-if="accountOf(t.account_id)" :network="accountOf(t.account_id).network" size="sm" />
                                </template>
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="button" class="btn btn-secondary" @click="generate(l)">Generar ahora</button>
                            <button type="button" class="btn btn-secondary" @click="toggle(l)">{{ l.active ? 'Pausar' : 'Activar' }}</button>
                            <button type="button" class="text-xs text-red-600 underline" @click="remove(l)">Eliminar</button>
                        </div>
                    </header>

                    <p v-if="l.items.length === 0" class="px-5 py-4 text-sm text-slate-500">La cola está vacía: añade contenido para que se generen publicaciones.</p>
                    <ol v-else class="divide-y">
                        <li v-for="(it, idx) in l.items" :key="it.id" class="flex items-center justify-between gap-3 px-5 py-2 text-sm">
                            <span class="min-w-0 truncate">
                                <span class="me-2 text-xs text-slate-500">{{ idx + 1 }}.</span>{{ it.label || '(solo archivos)' }}
                                <span v-if="it.is_template" class="ms-2 text-xs text-indigo-600">plantilla</span>
                                <span v-if="idx === l.cursor % l.items.length" class="ms-2 text-xs text-emerald-700">siguiente</span>
                            </span>
                            <button type="button" class="text-xs text-red-600 underline" @click="removeItem(l, it)">Quitar</button>
                        </li>
                    </ol>

                    <div class="grid gap-3 border-t px-5 py-3 md:grid-cols-2">
                        <form class="flex items-end gap-2" @submit.prevent="addText(l)">
                            <label class="block flex-1 text-sm">
                                <span class="block text-xs font-medium text-slate-500">Texto nuevo</span>
                                <input v-model="itemForm[l.id].text" type="text" maxlength="10000" class="field" />
                            </label>
                            <button type="submit" class="btn btn-secondary">Añadir</button>
                        </form>
                        <form class="flex items-end gap-2" @submit.prevent="addTemplate(l)">
                            <label class="block flex-1 text-sm">
                                <span class="block text-xs font-medium text-slate-500">Plantilla de la Biblioteca</span>
                                <select v-model="itemForm[l.id].template" class="field">
                                    <option value="">Elige una…</option>
                                    <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                            </label>
                            <button type="submit" class="btn btn-secondary">Añadir</button>
                        </form>
                    </div>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
