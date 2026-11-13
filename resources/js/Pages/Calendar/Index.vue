<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PublishNowConfirm from '@/Components/Calendar/PublishNowConfirm.vue';
import ViewsMenu from '@/Components/Calendar/ViewsMenu.vue';
import BatchBar from '@/Components/Calendar/BatchBar.vue';
import { workflowError, workflowRequest } from '@/Composables/useWorkflow';
import { useNetworks } from '@/Composables/useNetworks';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Calendario inspirado en Planning de Metricool (ui-reference.md): semana por franjas horarias, mes y lista
 * con el estado por red, filtros de marca/red y botón «Crear publicación». Las pendientes se reprograman
 * arrastrando su tarjeta a otro día/hora; las publicadas o en curso no se mueven.
 */
const props = defineProps({
    view: String,
    date: String,
    from: String,
    to: String,
    timezone: String,
    prev: String,
    next: String,
    today: String,
    brands: Array,
    networks: Array,
    items: Array,
    undated: Array,
    truncated: Boolean,
    export: { type: Object, default: null },
    filters: Object,
    views: { type: Array, default: () => [] },
    active_view: { type: Number, default: null },
    flash: Object,
});

const catalog = useNetworks();

const go = (params) =>
    router.get(route('calendar.index'), { ...props.filters, view: props.view, date: props.date, ...params }, { preserveState: true, preserveScroll: true, replace: true });

// `export` es palabra reservada: el aviso del tope se lee por `csvInfo`.
const csvInfo = computed(() => props.export);
// Exportar CSV (C-03c): mismos filtros que la lista (los de la vista guardada ya vienen aplicados en `filters`).
const exportHref = computed(() => route('exports.posts', Object.fromEntries(Object.entries({ ...props.filters, date: props.date }).filter(([, v]) => v !== null && v !== undefined && v !== ''))));

const setFilter = (key, value) => go({ [key]: value || undefined });

// ---------------------------------------------------------------- semana / mes
const hours = Array.from({ length: 24 }, (_, h) => h);
const pad = (n) => String(n).padStart(2, '0');
const fmtDate = (d) => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
const addDays = (iso, n) => {
    const d = new Date(`${iso}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);
    return fmtDate(d);
};
const dayCount = computed(() => Math.round((new Date(`${props.to}T00:00:00Z`) - new Date(`${props.from}T00:00:00Z`)) / 86400000) + 1);
const days = computed(() => Array.from({ length: dayCount.value }, (_, i) => addDays(props.from, i)));
const weekdayLabel = (iso) => new Date(`${iso}T00:00:00Z`).toLocaleDateString('es', { weekday: 'short', day: 'numeric', timeZone: 'UTC' });
const monthOf = computed(() => props.date.slice(0, 7));
// «19–25 oct 2026» (es-ES, Intl): el formateador de rangos comparte mes y año en vez de repetirlos.
const rangeFormat = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
const title = computed(() => {
    const d = new Date(`${props.date}T00:00:00Z`);
    return props.view === 'month'
        ? d.toLocaleDateString('es', { month: 'long', year: 'numeric', timeZone: 'UTC' })
        : rangeFormat.formatRange(new Date(`${props.from}T00:00:00Z`), new Date(`${props.to}T00:00:00Z`)).replace(/\./g, '');
});
const itemsAt = (day, hour = null) =>
    props.items.filter((i) => i.local_date === day && (hour === null || Number(i.local_time.slice(0, 2)) === hour));

// ---------------------------------------------------------------- reprogramar
const dragging = ref(null);
const dragStart = (item, event) => {
    if (!item.editable) return event.preventDefault();
    dragging.value = item;
    event.dataTransfer.effectAllowed = 'move';
};
const drop = (day, hour = null) => {
    const item = dragging.value;
    dragging.value = null;
    if (!item) return;
    const time = hour === null ? item.local_time : `${pad(hour)}:00`;
    reschedule(item, `${day}T${time}`);
};
const reschedule = (item, value) => {
    if (!value || value === item.local_datetime) return;
    router.patch(route('posts.reschedule', item.id), { scheduled_at: value }, { preserveScroll: true });
};

// ---------------------------------------------------------------- crear
const shellBrand = computed(() => usePage().props.shell?.currentBrand?.id ?? null);
const createBrand = computed(() => props.filters.brand_id ?? (props.brands.length === 1 ? props.brands[0].id : shellBrand.value));
const createHref = computed(() => (createBrand.value ? route('brands.posts.create', createBrand.value) : null));
// Etiqueta corta y color de la red desde el catalogo del servidor: texto oscurecido sobre un tinte claro del color de marca.
const networkOf = (n) => {
    const c = catalog.color(n);

    return {
        short: catalog.shortLabel(n),
        style: { backgroundColor: `color-mix(in srgb, ${c} 12%, white)`, color: `color-mix(in srgb, ${c} 60%, black)`, borderColor: `color-mix(in srgb, ${c} 35%, white)` },
    };
};
const saveToLibrary = (item) =>
    router.post(route('library.store'), { post_id: item.id, name: (item.text || 'Mi plantilla').slice(0, 60) }, { preserveScroll: true });

// ---------------------------------------------------------------- publicar ahora
// Confirmación -> POST posts/{id}/publish-now. La tarjeta muestra «Enviando…» mientras la petición va y, después, hasta que el
// worker reclama el destino (el estado deja de ser borrador/programada): entre tanto se refresca el calendario cada pocos segundos.
const confirming = ref(null);
const opener = ref(null);
const sending = ref(new Set());
const askPublishNow = (item, event) => {
    opener.value = event?.currentTarget ?? null;
    confirming.value = item;
};
const PENDING = ['borrador', 'programada'];
let pollTimer = null;
let polls = 0;
const stopPolling = () => {
    clearInterval(pollTimer);
    pollTimer = null;
};
const startPolling = () => {
    if (pollTimer) return;
    polls = 0;
    pollTimer = setInterval(() => {
        if (sending.value.size === 0) return stopPolling();
        if (++polls > 15) {
            // Se agotaron las vueltas: la tarjeta deja de decir «Enviando…» aunque el estado no haya cambiado.
            sending.value = new Set();
            return stopPolling();
        }
        router.reload({ only: ['items', 'undated', 'flash'], preserveScroll: true });
    }, 4000);
};
const publishNow = (item) => {
    confirming.value = null;
    sending.value = new Set(sending.value).add(item.id);
    router.post(route('posts.publish-now', item.id), {}, {
        preserveScroll: true,
        onSuccess: () => startPolling(),
        onError: () => stopSending(item.id),
    });
};
const stopSending = (id) => {
    const next = new Set(sending.value);
    next.delete(id);
    sending.value = next;
};
// Un error del servidor (flash.error) o un estado distinto de pendiente liberan la tarjeta.
watch(() => [props.items, props.undated, props.flash?.error], () => {
    const all = [...props.items, ...props.undated];
    for (const id of sending.value) {
        const item = all.find((i) => i.id === id);
        if (!item || !PENDING.includes(item.status) || props.flash?.error) stopSending(id);
    }
});
// «Publicar ahora» desde el compositor redirige aquí con el id del post: se sondea igual que desde el calendario.
onMounted(() => {
    const id = props.flash?.publishing_post_id;
    if (id) {
        sending.value = new Set(sending.value).add(Number(id));
        startPolling();
    }
});
onBeforeUnmount(stopPolling);
const dateLabel = (item) => (item.local_date ? `${item.local_date} ${item.local_time}` : 'Sin fecha');

// ---------------------------------------------------------------- aprobación, manual, reintento y lotes (T-19)
const APPROVAL = { pendiente: 'Pendiente de aprobación', aprobado: 'Aprobado', rechazado: 'Rechazado' };
const APPROVAL_STYLE = { pendiente: 'bg-amber-50 text-amber-900', aprobado: 'bg-emerald-50 text-emerald-900', rechazado: 'bg-red-50 text-red-900' };
const selected = ref([]);
const allRows = computed(() => [...props.items, ...props.undated]);
const allSelected = computed(() => allRows.value.length > 0 && selected.value.length === allRows.value.length);
const toggleAll = (on) => {
    selected.value = on ? allRows.value.map((i) => i.id) : [];
};
// Si el calendario se recarga, solo se conservan las filas que siguen visibles.
watch(allRows, (rows) => {
    const ids = new Set(rows.map((i) => i.id));
    selected.value = selected.value.filter((id) => ids.has(id));
});
const retrying = ref(new Set());
const retryNotice = ref('');
const retry = async (item) => {
    if (retrying.value.has(item.id)) return;
    retrying.value = new Set(retrying.value).add(item.id);
    const result = await workflowRequest('POST', route('workflow.retry', item.id));
    const next = new Set(retrying.value);
    next.delete(item.id);
    retrying.value = next;
    retryNotice.value = result.ok ? (result.data.retried ? 'Reintento enviado.' : 'No había nada que reintentar: ya está en curso o publicada.') : workflowError(result);
    router.reload({ only: ['items', 'undated', 'flash'], preserveScroll: true });
};
</script>

<template>
    <Head title="Calendario" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900">Calendario</h1>
                <div class="flex items-center gap-2">
                    <Link v-if="createHref" :href="createHref" class="btn-primary">Crear publicación</Link>
                    <span v-else class="text-sm text-slate-500">Elige una marca para crear una publicación</span>
                </div>
            </div>
        </template>

        <div>
            <div class="w-full space-y-4">
                <p v-if="flash.success" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ flash.success }}</p>
                <p v-if="flash.error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ flash.error }}</p>

                <!-- Barra: vistas, navegación, filtros -->
                <div class="flex flex-wrap items-center gap-3 card p-3">
                    <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm" role="tablist">
                        <button v-for="v in [['week', 'Semana'], ['month', 'Mes'], ['list', 'Lista']]" :key="v[0]" type="button" role="tab" :aria-selected="view === v[0]" class="px-3 py-1.5" :class="view === v[0] ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 hover:bg-slate-50'" @click="go({ view: v[0] })">{{ v[1] }}</button>
                    </div>
                    <div class="flex items-center gap-1 text-sm">
                        <button type="button" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 hover:bg-slate-50" aria-label="Anterior" @click="go({ date: prev })">‹</button>
                        <button type="button" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 hover:bg-slate-50" @click="go({ date: today })">Hoy</button>
                        <button type="button" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 hover:bg-slate-50" aria-label="Siguiente" @click="go({ date: next })">›</button>
                    </div>
                    <span class="font-medium capitalize text-slate-800">{{ title }}</span>
                    <span class="text-xs text-slate-500">Zona horaria: {{ timezone }}</span>

                    <div class="ml-auto flex min-w-0 max-w-full flex-wrap items-center gap-2 text-sm">
                        <ViewsMenu :views="views" :active-view="active_view" :filters="filters" :view="view" :date="date" />
                        <select :value="filters.brand_id ?? ''" class="field w-auto py-1.5" aria-label="Marca" @change="setFilter('brand_id', $event.target.value)">
                            <option value="">Todas las marcas</option>
                            <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                        <select :value="filters.network ?? ''" class="field w-auto py-1.5" aria-label="Red" @change="setFilter('network', $event.target.value)">
                            <option value="">Todas las redes</option>
                            <option v-for="n in networks" :key="n.value" :value="n.value">{{ n.label }}</option>
                        </select>
                        <template v-if="view === 'list'">
                            <select :value="filters.status ?? ''" class="field w-auto py-1.5" aria-label="Estado" @change="setFilter('status', $event.target.value)">
                                <option value="">Todos los estados</option>
                                <option v-for="s in ['borrador', 'programada', 'publicando', 'procesando', 'publicada', 'fallida']" :key="s" :value="s">{{ s }}</option>
                            </select>
                            <input type="search" :value="filters.q ?? ''" placeholder="Buscar texto" class="field w-auto py-1.5" @keyup.enter="setFilter('q', $event.target.value)" />
                            <a :href="exportHref" class="btn-secondary" data-testid="export-posts">Exportar CSV</a>
                        </template>
                    </div>
                </div>

                <EmptyState v-if="items.length === 0 && undated.length === 0" title="No hay publicaciones en este periodo" description="Programa tu primera publicación y la verás aquí, con el estado en cada red.">
                    <Link v-if="createHref" :href="createHref" class="btn-primary">Crear publicación</Link>
                    <Link v-else :href="route('brands.index')" class="btn-primary">Crear una marca</Link>
                </EmptyState>

                <p v-if="view === 'list' && csvInfo?.truncated" class="text-sm text-amber-800" role="status" data-testid="export-truncated">
                    Hay {{ csvInfo.total }} filas que exportar y el CSV incluye las primeras {{ csvInfo.max }}. Filtra por marca, red o estado para exportar el resto.
                </p>

                <p v-if="retryNotice" class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900" role="status" data-testid="retry-notice">{{ retryNotice }}</p>
                <BatchBar v-if="view === 'list'" :selected="selected" :items="allRows" @clear="selected = []" />

                <p v-if="truncated" class="text-sm text-amber-700">Hay muchas publicaciones: se muestran las primeras 500. Filtra por marca o red.</p>

                <!-- SEMANA: franjas horarias -->
                <div v-if="view === 'week'" class="card overflow-x-auto" tabindex="0" role="region" aria-label="Calendario semanal">
                    <div class="grid min-w-[56rem]" style="grid-template-columns: 3.5rem repeat(7, minmax(0, 1fr))">
                        <div></div>
                        <div v-for="d in days" :key="d" class="border-b border-l border-slate-200 p-2 text-center text-xs font-medium" :class="d === today ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600'">{{ weekdayLabel(d) }}</div>
                        <template v-for="h in hours" :key="h">
                            <div class="border-b border-slate-100 pr-1 text-right text-[10px] text-slate-500">{{ pad(h) }}:00</div>
                            <div v-for="d in days" :key="`${d}-${h}`" class="min-h-[2.25rem] space-y-1 border-b border-l border-slate-100 p-0.5" @dragover.prevent @drop.prevent="drop(d, h)">
                                <article v-for="item in itemsAt(d, h)" :key="item.id" :draggable="item.editable" class="rounded border p-1 text-xs shadow-sm" :class="item.editable ? 'cursor-move bg-white' : 'bg-slate-50'" @dragstart="dragStart(item, $event)">
                                    <Link :href="route('posts.edit', item.id)" class="block">
                                        <span class="flex flex-wrap items-center gap-1">
                                            <span class="text-slate-600">{{ item.local_time }}</span>
                                            <span v-for="t in item.targets" :key="t.network + t.content_type + t.account_name" class="rounded border px-1" :style="networkOf(t.network).style" :title="`${t.network_label} · ${t.status_label}`">{{ networkOf(t.network).short }}</span>
                                            <StatusBadge :status="item.status" :label="item.status_label" />
                                            <span v-if="item.approval_status" class="rounded px-1 text-[11px] font-medium" :class="APPROVAL_STYLE[item.approval_status]" data-testid="approval-badge">{{ APPROVAL[item.approval_status] }}</span>
                                            <span v-if="item.series_id" class="rounded bg-indigo-50 px-1 text-[11px] font-medium text-indigo-900" data-testid="series-badge">Serie</span>
                                            <span v-if="item.manual_pending" class="rounded bg-slate-100 px-1 text-[11px] text-slate-800" data-testid="manual-badge">Manual: toca publicar</span>
                                            <span v-if="item.has_notes" class="rounded bg-amber-100 px-1 text-[11px] text-amber-900" data-testid="notes-indicator" title="Esta publicación tiene notas internas"><span aria-hidden="true">📝 </span>Nota</span>
                                        </span>
                                        <span class="line-clamp-2 text-slate-800">{{ item.text || 'Sin texto' }}</span>
                                    </Link>
                                    <span v-if="sending.has(item.id)" role="status" class="mt-0.5 block text-[11px] font-medium text-indigo-700">Enviando…</span>
                                    <button v-else-if="item.can_publish_now" type="button" class="link mt-0.5 text-[11px]" :aria-label="`Publicar ahora la publicación ${item.id}`" @click="askPublishNow(item, $event)">Publicar ahora</button>
                                </article>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- MES -->
                <div v-else-if="view === 'month'" class="card overflow-hidden">
                    <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50 text-center text-xs font-medium text-slate-600">
                        <div v-for="w in ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']" :key="w" class="p-2">{{ w }}</div>
                    </div>
                    <div class="grid grid-cols-7">
                        <div v-for="d in days" :key="d" class="min-h-[6.5rem] space-y-1 border-b border-l border-slate-100 p-1" :class="d.slice(0, 7) === monthOf ? '' : 'bg-slate-50 text-slate-500'" @dragover.prevent @drop.prevent="drop(d)">
                            <div class="text-xs" :class="d === today ? 'font-bold text-indigo-700' : ''">{{ Number(d.slice(8)) }}</div>
                            <article v-for="item in itemsAt(d)" :key="item.id" :draggable="item.editable" class="rounded border p-1 text-xs shadow-sm" :class="item.editable ? 'cursor-move bg-white' : 'bg-slate-50'" @dragstart="dragStart(item, $event)">
                                <Link :href="route('posts.edit', item.id)" class="block">
                                    <span class="flex flex-wrap items-center gap-1">
                                        <span class="text-slate-500">{{ item.local_time }}</span>
                                        <span v-if="item.series_id" class="rounded bg-indigo-50 px-1 text-[11px] font-medium text-indigo-900" data-testid="series-badge">Serie</span>
                                        <span v-if="item.approval_status" class="rounded px-1 text-[11px] font-medium" :class="APPROVAL_STYLE[item.approval_status]" data-testid="approval-badge">{{ APPROVAL[item.approval_status] }}</span>
                                        <span v-if="item.manual_pending" class="rounded bg-slate-100 px-1 text-[11px] text-slate-800" data-testid="manual-badge">Manual</span>
                                        <span v-for="t in item.targets" :key="t.network + t.content_type + t.account_name" class="rounded border px-1" :style="networkOf(t.network).style" :title="`${t.network_label} · ${t.status_label}`">{{ networkOf(t.network).short }}</span>
                                        <span v-if="item.has_notes" class="rounded bg-amber-100 px-1 text-[11px] text-amber-900" data-testid="notes-indicator" title="Esta publicación tiene notas internas"><span aria-hidden="true">📝 </span>Nota</span>
                                    </span>
                                    <span class="line-clamp-1 text-slate-800">{{ item.text || 'Sin texto' }}</span>
                                </Link>
                                <span v-if="sending.has(item.id)" role="status" class="block text-[11px] font-medium text-indigo-700">Enviando…</span>
                                <button v-else-if="item.can_publish_now" type="button" class="link text-[11px]" :aria-label="`Publicar ahora la publicación ${item.id}`" @click="askPublishNow(item, $event)">Publicar ahora</button>
                            </article>
                        </div>
                    </div>
                </div>

                <!-- LISTA: estado por red -->
                <div v-else-if="items.length || undated.length" class="card overflow-x-auto" tabindex="0" role="region" aria-label="Lista de publicaciones">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-3 py-2">
                                    <input type="checkbox" class="h-4 w-4 rounded border-slate-400 text-indigo-600" aria-label="Seleccionar todas las publicaciones" :checked="allSelected" data-testid="select-all" @change="toggleAll($event.target.checked)" />
                                </th>
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2">Contenido</th>
                                <th class="px-3 py-2">Redes</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-if="items.length === 0 && undated.length === 0">
                                <td colspan="6" class="px-3 py-6 text-center text-slate-500">No hay publicaciones en este periodo.</td>
                            </tr>
                            <tr v-for="item in [...items, ...undated]" :key="item.id" class="align-top" :data-testid="`row-${item.id}`">
                                <td class="px-3 py-2">
                                    <input v-model="selected" type="checkbox" :value="item.id" class="h-4 w-4 rounded border-slate-400 text-indigo-600" :aria-label="`Seleccionar la publicación ${item.id}`" :data-testid="`select-${item.id}`" />
                                </td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    <template v-if="item.editable && item.local_datetime">
                                        <input type="datetime-local" :value="item.local_datetime" class="field w-auto py-1 text-xs" :aria-label="`Fecha de la publicación ${item.id}`" @change="reschedule(item, $event.target.value)" />
                                    </template>
                                    <template v-else>{{ dateLabel(item) }}</template>
                                    <div class="text-xs text-slate-500">{{ item.brand_name }}</div>
                                </td>
                                <td class="max-w-xs px-3 py-2"><Link :href="route('posts.edit', item.id)" class="text-slate-900 hover:underline">{{ item.text || 'Sin texto' }}</Link> <span v-if="item.has_notes" class="rounded bg-amber-100 px-1 text-[11px] text-amber-900" data-testid="notes-indicator" title="Esta publicación tiene notas internas"><span aria-hidden="true">📝 </span>Nota</span></td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-for="t in item.targets" :key="t.network + t.content_type + t.account_name" class="rounded border px-1 text-xs" :style="networkOf(t.network).style">{{ t.network_label }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <div v-if="item.approval_status || item.series_id" class="mb-1 flex flex-wrap gap-1">
                                        <span v-if="item.approval_status" class="rounded px-1.5 py-0.5 text-[11px] font-medium" :class="APPROVAL_STYLE[item.approval_status]" data-testid="approval-badge">{{ APPROVAL[item.approval_status] }}</span>
                                        <span v-if="item.series_id" class="rounded bg-indigo-50 px-1.5 py-0.5 text-[11px] font-medium text-indigo-900" data-testid="series-badge">Serie</span>
                                    </div>
                                    <ul class="space-y-1">
                                        <li v-for="t in item.targets" :key="t.network + t.content_type + t.account_name" class="text-xs">
                                            <span class="font-medium">{{ networkOf(t.network).short }}</span>
                                            <StatusBadge class="ml-1" :status="t.status" :label="t.status_label" />
                                            <span v-if="t.manual" class="ml-1 rounded bg-slate-100 px-1 text-[11px] text-slate-800" data-testid="manual-badge">{{ t.manual_pending ? 'Manual: toca publicar' : 'Manual' }}</span>
                                            <a v-if="t.external_url" :href="t.external_url" target="_blank" rel="noopener" class="link ml-1">Ver</a>
                                            <span v-if="t.status_note" class="block text-amber-700">{{ t.status_note }}</span>
                                            <span v-if="t.error_message" class="block text-red-600">{{ t.error_message }}</span>
                                        </li>
                                        <li v-if="item.targets.length === 0" class="text-xs text-slate-500">Sin destinos</li>
                                    </ul>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    <Link :href="route('posts.edit', item.id)" class="link">{{ item.editable ? 'Editar' : 'Ver' }}</Link>
                                    <span v-if="sending.has(item.id)" role="status" class="ms-3 text-xs font-medium text-indigo-700">Enviando…</span>
                                    <button v-else-if="item.can_publish_now" type="button" class="link ms-3" :aria-label="`Publicar ahora la publicación ${item.id}`" @click="askPublishNow(item, $event)">Publicar ahora</button>
                                    <button v-if="item.can_retry" type="button" class="link ms-3" :disabled="retrying.has(item.id)" :aria-label="`Reintentar la publicación ${item.id}`" data-testid="retry" @click="retry(item)">Reintentar</button>
                                    <span v-if="item.retry_blocked" class="ms-3 text-xs text-amber-700" data-testid="retry-blocked">{{ item.retry_blocked }}</span>
                                    <Link v-if="item.has_manual" :href="route('posts.manual.show', item.id)" class="link ms-3" :aria-label="`Abrir el aviso manual de la publicación ${item.id}`" data-testid="manual-link">Aviso manual</Link>
                                    <button type="button" class="link ms-3" :aria-label="`Guardar la publicación ${item.id} en la biblioteca`" @click="saveToLibrary(item)">Guardar en biblioteca</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <PublishNowConfirm v-if="confirming" :item="confirming" :opener="opener" @confirm="publishNow" @cancel="confirming = null" />
    </AuthenticatedLayout>
</template>
