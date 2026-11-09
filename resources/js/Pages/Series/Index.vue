<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { workflowError, workflowRequest } from '@/Composables/useWorkflow';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Series recurrentes (T-19): diaria, semanal con días elegidos o mensual, con fin por fecha o por número de veces. Cada ocurrencia es una
 * publicación normal que aparece en el calendario; editarla la saca de la serie y cancelar la serie borra las futuras pendientes.
 */
const props = defineProps({ series: { type: Array, default: () => [] }, brands: { type: Array, default: () => [] } });

const DAYS = [[1, 'Lun'], [2, 'Mar'], [3, 'Mié'], [4, 'Jue'], [5, 'Vie'], [6, 'Sáb'], [7, 'Dom']];
const today = new Date().toISOString().slice(0, 10);

const form = ref({
    brand_id: props.brands[0]?.id ?? null,
    text: '',
    frequency: 'semanal',
    weekdays: [],
    time_local: '10:00',
    starts_on: today,
    end_mode: 'count',
    ends_on: '',
    max_occurrences: 4,
    targets: {}, // account_id -> content_type seleccionado
});
const brand = computed(() => props.brands.find((b) => b.id === Number(form.value.brand_id)));
const message = ref('');
const error = ref('');
const busy = ref(false);
const editing = ref(null);
const edit = ref({ text: '', time_local: '' });

const toggleAccount = (account, on) => {
    const next = { ...form.value.targets };
    if (on) next[account.id] = account.content_types[0]?.value;
    else delete next[account.id];
    form.value.targets = next;
};
const toggleDay = (d) => {
    const set = new Set(form.value.weekdays);
    set.has(d) ? set.delete(d) : set.add(d);
    form.value.weekdays = [...set].sort();
};
const done = (text) => {
    message.value = text;
    error.value = '';
    router.reload({ preserveScroll: true, only: ['series'] });
};

const create = async () => {
    if (busy.value) return;
    busy.value = true;
    const f = form.value;
    const body = {
        brand_id: f.brand_id, frequency: f.frequency, time_local: f.time_local, starts_on: f.starts_on, text: f.text, media: [],
        weekdays: f.frequency === 'semanal' ? f.weekdays : null,
        ends_on: f.end_mode === 'date' ? f.ends_on || null : null,
        max_occurrences: f.end_mode === 'count' ? Number(f.max_occurrences) || null : null,
        targets: Object.entries(f.targets).map(([id, type]) => ({ account_id: Number(id), content_type: type })),
    };
    const result = await workflowRequest('POST', route('workflow.series.store'), body);
    busy.value = false;
    if (result.ok) done(`Serie creada con ${result.data.series.generated} publicaciones programadas.`);
    else error.value = workflowError(result);
};
const cancel = async (s) => {
    if (busy.value || !confirm('¿Cancelar la serie? Se borrarán las publicaciones futuras pendientes.')) return;
    busy.value = true;
    const result = await workflowRequest('DELETE', route('workflow.series.cancel', s.id));
    busy.value = false;
    result.ok ? done('Serie cancelada.') : (error.value = workflowError(result));
};
const startEdit = (s) => {
    editing.value = s.id;
    edit.value = { text: s.text ?? '', time_local: s.time_local };
};
const saveEdit = async (s) => {
    if (busy.value) return;
    busy.value = true;
    const result = await workflowRequest('PATCH', route('workflow.series.update', s.id), { text: edit.value.text, time_local: edit.value.time_local });
    busy.value = false;
    if (result.ok) {
        editing.value = null;
        done('Serie actualizada: las ocurrencias futuras sin editar han cambiado.');
    } else {
        error.value = workflowError(result);
    }
};
const freq = (s) => ({ diaria: 'Cada día', mensual: 'Cada mes', semanal: `Cada semana (${(s.weekdays ?? []).map((d) => DAYS.find((x) => x[0] === d)?.[1]).join(', ')})` })[s.frequency];
const ends = (s) => (s.ends_on ? `hasta el ${s.ends_on}` : s.max_occurrences ? `${s.max_occurrences} veces` : 'sin fin');
</script>

<template>
    <Head title="Series" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Series recurrentes</h1>
        </template>

        <div class="max-w-4xl space-y-6">
            <p v-if="message" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status" data-testid="series-message">{{ message }}</p>
            <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert" data-testid="series-error">{{ error }}</p>

            <section v-if="brands.length" class="card p-4" aria-labelledby="new-series">
                <h2 id="new-series" class="text-base font-semibold text-slate-900">Nueva serie</h2>
                <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="create">
                    <div>
                        <label for="s-brand" class="block text-sm font-medium text-slate-800">Marca</label>
                        <select id="s-brand" v-model="form.brand_id" class="field w-full" @change="form.targets = {}">
                            <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="s-freq" class="block text-sm font-medium text-slate-800">Frecuencia</label>
                        <select id="s-freq" v-model="form.frequency" class="field w-full" data-testid="series-frequency">
                            <option value="diaria">Diaria</option>
                            <option value="semanal">Semanal</option>
                            <option value="mensual">Mensual</option>
                        </select>
                    </div>
                    <fieldset v-if="form.frequency === 'semanal'" class="sm:col-span-2">
                        <legend class="text-sm font-medium text-slate-800">Días de la semana</legend>
                        <div class="mt-1 flex flex-wrap gap-3">
                            <label v-for="d in DAYS" :key="d[0]" class="inline-flex items-center gap-1 text-sm">
                                <input type="checkbox" class="h-4 w-4 rounded border-slate-400 text-indigo-600" :checked="form.weekdays.includes(d[0])" :data-testid="`weekday-${d[0]}`" @change="toggleDay(d[0])" />{{ d[1] }}
                            </label>
                        </div>
                    </fieldset>
                    <div>
                        <label for="s-time" class="block text-sm font-medium text-slate-800">Hora (zona de la marca{{ brand ? `: ${brand.timezone}` : '' }})</label>
                        <input id="s-time" v-model="form.time_local" type="time" class="field w-full" required data-testid="series-time" />
                    </div>
                    <div>
                        <label for="s-start" class="block text-sm font-medium text-slate-800">Primer día</label>
                        <input id="s-start" v-model="form.starts_on" type="date" class="field w-full" required data-testid="series-start" />
                    </div>
                    <fieldset class="sm:col-span-2">
                        <legend class="text-sm font-medium text-slate-800">Fin</legend>
                        <div class="mt-1 flex flex-wrap items-center gap-4 text-sm">
                            <label class="inline-flex items-center gap-1"><input v-model="form.end_mode" type="radio" value="count" />Tras</label>
                            <label class="sr-only" for="s-count">Número de veces</label>
                            <input id="s-count" v-model="form.max_occurrences" type="number" min="1" max="366" class="field w-24" :disabled="form.end_mode !== 'count'" data-testid="series-count" />
                            <span>veces</span>
                            <label class="inline-flex items-center gap-1"><input v-model="form.end_mode" type="radio" value="date" />El día</label>
                            <label class="sr-only" for="s-end">Fecha de fin</label>
                            <input id="s-end" v-model="form.ends_on" type="date" class="field w-auto" :disabled="form.end_mode !== 'date'" />
                            <label class="inline-flex items-center gap-1"><input v-model="form.end_mode" type="radio" value="none" />Sin fin</label>
                        </div>
                    </fieldset>
                    <fieldset class="sm:col-span-2">
                        <legend class="text-sm font-medium text-slate-800">Cuentas de destino</legend>
                        <p v-if="!brand?.accounts.length" class="text-xs text-slate-600">Esta marca no tiene cuentas conectadas.</p>
                        <div v-for="a in brand?.accounts ?? []" :key="a.id" class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                            <label class="inline-flex items-center gap-1">
                                <input type="checkbox" class="h-4 w-4 rounded border-slate-400 text-indigo-600" :checked="a.id in form.targets" :data-testid="`series-account-${a.id}`" @change="toggleAccount(a, $event.target.checked)" />
                                {{ a.network_label }} · {{ a.name }}
                            </label>
                            <template v-if="a.id in form.targets">
                                <label class="sr-only" :for="`s-type-${a.id}`">Tipo de contenido de {{ a.name }}</label>
                                <select :id="`s-type-${a.id}`" v-model="form.targets[a.id]" class="field w-auto py-1">
                                    <option v-for="t in a.content_types" :key="t.value" :value="t.value">{{ t.label }}</option>
                                </select>
                            </template>
                        </div>
                    </fieldset>
                    <div class="sm:col-span-2">
                        <label for="s-text" class="block text-sm font-medium text-slate-800">Texto</label>
                        <textarea id="s-text" v-model="form.text" rows="3" class="field w-full" data-testid="series-text"></textarea>
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="btn btn-primary" :disabled="busy || Object.keys(form.targets).length === 0" data-testid="series-create">Crear serie</button>
                    </div>
                </form>
            </section>
            <EmptyState v-else title="Necesitas una marca" description="Crea una marca y conecta una cuenta para programar series.">
                <Link :href="route('brands.index')" class="btn-primary">Ir a marcas</Link>
            </EmptyState>

            <section aria-labelledby="my-series">
                <h2 id="my-series" class="mb-2 text-base font-semibold text-slate-900">Tus series</h2>
                <p v-if="series.length === 0" class="card p-4 text-sm text-slate-600">Todavía no tienes series.</p>
                <ul class="space-y-3">
                    <li v-for="s in series" :key="s.id" class="card p-4" :data-testid="`series-${s.id}`">
                        <div class="flex flex-wrap items-center gap-2">
                            <StatusBadge :status="s.status" :label="s.status === 'activa' ? 'Activa' : 'Cancelada'" />
                            <span class="text-sm font-medium text-slate-900">{{ freq(s) }} a las {{ s.time_local }}</span>
                            <span class="text-xs text-slate-600">desde {{ s.starts_on }} · {{ ends(s) }} · {{ s.generated }} generadas</span>
                        </div>
                        <p class="mt-1 line-clamp-2 text-sm text-slate-800">{{ s.text || '(sin texto)' }}</p>
                        <ul class="mt-2 flex flex-wrap gap-1.5">
                            <li v-for="o in s.occurrences.slice(0, 12)" :key="o.date" class="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs">
                                <Link v-if="o.post_id && o.status !== 'eliminada'" :href="route('posts.edit', o.post_id)" class="link">{{ o.date }}</Link><span v-else>{{ o.date }}</span>
                                <span v-if="o.edited" class="text-amber-800"> · editada</span>
                            </li>
                        </ul>
                        <div v-if="s.status === 'activa'" class="mt-3 flex flex-wrap gap-2">
                            <button type="button" class="btn btn-secondary px-3 py-1 text-sm" @click="startEdit(s)">Editar serie</button>
                            <button type="button" class="btn btn-secondary px-3 py-1 text-sm" data-testid="series-cancel" :aria-label="`Cancelar la serie ${s.id}`" @click="cancel(s)">Cancelar serie</button>
                        </div>
                        <form v-if="editing === s.id" class="mt-3 space-y-2" @submit.prevent="saveEdit(s)">
                            <label :for="`e-text-${s.id}`" class="block text-sm font-medium text-slate-800">Texto de las próximas ocurrencias</label>
                            <textarea :id="`e-text-${s.id}`" v-model="edit.text" rows="3" class="field w-full"></textarea>
                            <label :for="`e-time-${s.id}`" class="block text-sm font-medium text-slate-800">Hora</label>
                            <input :id="`e-time-${s.id}`" v-model="edit.time_local" type="time" class="field w-auto" required />
                            <p class="text-xs text-slate-600">Las ocurrencias que hayas editado a mano no cambian.</p>
                            <div class="flex gap-2">
                                <button type="submit" class="btn btn-primary px-3 py-1 text-sm" :disabled="busy">Guardar</button>
                                <button type="button" class="btn btn-secondary px-3 py-1 text-sm" @click="editing = null">Cerrar</button>
                            </div>
                        </form>
                    </li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
