<script setup>
/** @import { CalendarBrand, CalendarExportSummary, CalendarFeedState, CalendarFilters, CalendarItem, CalendarNavigation, CalendarViewMode, MarketingDate, SavedCalendarView, ScheduledCalendarItem, UndatedCalendarItem } from '@/types/calendar' */
/** @import { SessionFlash, NetworkOption, ShellPageProps } from '@/types/shell' */
import { useTabs } from '@/Composables/useTabs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import BestTimeSuggestion from '@/Components/BestTimeSuggestion.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PublishNowConfirm from '@/Components/Calendar/PublishNowConfirm.vue';
import CalendarToolbar from '@/Components/Calendar/CalendarToolbar.vue';
import CalendarFeedPanel from '@/Components/Calendar/CalendarFeedPanel.vue';
import { useCalendarPublishNow } from '@/Composables/useCalendarPublishNow';
import BatchBar from '@/Components/Calendar/BatchBar.vue';
import { workflowError, workflowRequest } from '@/Composables/useWorkflow';
import { useNetworks } from '@/Composables/useNetworks';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * Calendario inspirado en Planning de Metricool (ui-reference.md): semana por franjas horarias, mes y lista
 * con el estado por red, filtros de marca/red y botón «Crear publicación». Las pendientes se reprograman
 * arrastrando su tarjeta a otro día/hora; las publicadas o en curso no se mueven.
 */
const props = defineProps({
    view: { type: /** @type {import('vue').PropType<CalendarViewMode>} */ (String), required: true },
    date: { type: String, required: true },
    from: { type: String, required: true },
    to: { type: String, required: true },
    timezone: { type: String, required: true },
    prev: { type: String, required: true },
    next: { type: String, required: true },
    today: { type: String, required: true },
    brands: { type: /** @type {import('vue').PropType<CalendarBrand[]>} */ (Array), required: true },
    networks: { type: /** @type {import('vue').PropType<NetworkOption[]>} */ (Array), required: true },
    items: { type: /** @type {import('vue').PropType<ScheduledCalendarItem[]>} */ (Array), required: true },
    undated: { type: /** @type {import('vue').PropType<UndatedCalendarItem[]>} */ (Array), required: true },
    truncated: Boolean,
    marketing_dates: { type: /** @type {import('vue').PropType<MarketingDate[]>} */ (Array), default: () => [] },
    marketing_dates_hidden: { type: Boolean, default: false },
    feed: { type: /** @type {import('vue').PropType<CalendarFeedState>} */ (Object), required: true },
    export: { type: /** @type {import('vue').PropType<CalendarExportSummary | null>} */ (Object), default: null },
    filters: { type: /** @type {import('vue').PropType<CalendarFilters>} */ (Object), required: true },
    views: { type: /** @type {import('vue').PropType<SavedCalendarView[]>} */ (Array), default: () => [] },
    active_view: { type: /** @type {import('vue').PropType<number | null>} */ (Number), default: null },
    flash: /** @type {import('vue').PropType<SessionFlash>} */ (Object),
});

const catalog = useNetworks();
const { tabProps, panelProps } = useTabs(() => props.view, { sharedPanel: true });

/** @param {CalendarNavigation} params */
const go = (params) =>
    router.get(route('calendar.index'), { ...props.filters, view: props.view, date: props.date, ...params }, { preserveState: true, preserveScroll: true, replace: true });

// `export` es palabra reservada: el aviso del tope se lee por `csvInfo`.
const csvInfo = computed(() => props.export);
// ---------------------------------------------------------------- semana / mes
const hours = Array.from({ length: 24 }, (_, h) => h);
/** @param {number} n */
const pad = (n) => String(n).padStart(2, '0');
/** @param {Date} d */
const fmtDate = (d) => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
/** @param {string} iso @param {number} n */
const addDays = (iso, n) => {
    const d = new Date(`${iso}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);
    return fmtDate(d);
};
const dayCount = computed(() => Math.round((new Date(`${props.to}T00:00:00Z`).getTime() - new Date(`${props.from}T00:00:00Z`).getTime()) / 86400000) + 1);
const days = computed(() => Array.from({ length: dayCount.value }, (_, i) => addDays(props.from, i)));
/** @param {string} iso */
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
/** @param {string} day @param {number | null} [hour] */
const itemsAt = (day, hour = null) =>
    props.items.filter((i) => i.local_date === day && (hour === null || Number(i.local_time.slice(0, 2)) === hour));

/** Fechas señaladas de un día (no son publicaciones: no se arrastran ni se abren). @param {string} day */
const marketingAt = (day) => props.marketing_dates.filter((d) => d.date === day);
const longDay = new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });
/** @param {string} iso */
const longDayLabel = (iso) => longDay.format(new Date(`${iso}T00:00:00Z`));

// ---------------------------------------------------------------- reprogramar
/** @type {import('vue').Ref<ScheduledCalendarItem | null>} */
const dragging = ref(null);
/** @param {ScheduledCalendarItem} item @param {DragEvent} event */
const dragStart = (item, event) => {
    if (!item.editable) return event.preventDefault();
    dragging.value = item;
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
};
/** @param {string} day @param {number | null} [hour] */
const drop = (day, hour = null) => {
    const item = dragging.value;
    dragging.value = null;
    if (!item) return;
    const time = hour === null ? item.local_time : `${pad(hour)}:00`;
    reschedule(item, `${day}T${time}`);
};
/** @param {CalendarItem} item @param {string} value */
const reschedule = (item, value) => {
    if (!value || value === item.local_datetime) return;
    router.patch(route('posts.reschedule', item.id), { scheduled_at: value }, { preserveScroll: true });
};

// ---------------------------------------------------------------- crear
/** @param {CalendarItem} item @param {Event} event */
const rescheduleFromInput = (item, event) => {
    if (event.currentTarget instanceof HTMLInputElement) reschedule(item, event.currentTarget.value);
};

/** @type {ReturnType<typeof usePage<Pick<ShellPageProps, 'shell'>>>} */
const page = usePage();
const shellBrand = computed(() => page.props.shell?.currentBrand?.id ?? null);
const createBrand = computed(() => props.filters.brand_id ?? (props.brands.length === 1 ? props.brands[0].id : shellBrand.value));
const createHref = computed(() => (createBrand.value ? route('brands.posts.create', createBrand.value) : null));
const suggestionBrand = computed(() => props.brands.find((brand) => brand.id === Number(createBrand.value)) ?? null);
const suggestionNetworks = computed(() => props.networks.filter((network) => !props.filters.network || network.value === props.filters.network));
/** @param {import('@/types/best-time').AvailableBestTimeSuggestion} value */
const createAtBestTime = (value) => {
    if (createBrand.value) router.visit(route('brands.posts.create', { brand: createBrand.value, best_time: value.scheduled_at }));
};

// Etiqueta corta y color de la red desde el catalogo del servidor: texto oscurecido sobre un tinte claro del color de marca.
/** @param {string} n */
const networkOf = (n) => {
    const c = catalog.color(n);

    return {
        short: catalog.shortLabel(n),
        style: { backgroundColor: `color-mix(in srgb, ${c} 12%, white)`, color: `color-mix(in srgb, ${c} 60%, black)`, borderColor: `color-mix(in srgb, ${c} 35%, white)` },
    };
};
/** @param {CalendarItem} item */
const saveToLibrary = (item) =>
    router.post(route('library.store'), { post_id: item.id, name: (item.text || 'Mi plantilla').slice(0, 60) }, { preserveScroll: true });

const { confirming, opener, sending, askPublishNow, publishNow } = useCalendarPublishNow({
    getItems: () => props.items,
    getUndated: () => props.undated,
    getFlash: () => props.flash,
});
/** @param {CalendarItem} item */
const dateLabel = (item) => (item.local_date ? `${item.local_date} ${item.local_time}` : 'Sin fecha');

// ---------------------------------------------------------------- aprobación, manual, reintento y lotes (T-19)
const APPROVAL = { pendiente: 'Pendiente de aprobación', aprobado: 'Aprobado', rechazado: 'Rechazado' };
const APPROVAL_STYLE = { pendiente: 'bg-amber-50 text-amber-900', aprobado: 'bg-emerald-50 text-emerald-900', rechazado: 'bg-red-50 text-red-900' };
/** @type {import('vue').Ref<number[]>} */
const selected = ref([]);
const allRows = computed(() => [...props.items, ...props.undated]);
const allSelected = computed(() => allRows.value.length > 0 && selected.value.length === allRows.value.length);
/** @param {Event} event */
const toggleAll = (event) => {
    if (!(event.currentTarget instanceof HTMLInputElement)) return;
    selected.value = event.currentTarget.checked ? allRows.value.map((i) => i.id) : [];
};
// Si el calendario se recarga, solo se conservan las filas que siguen visibles.
watch(allRows, (rows) => {
    const ids = new Set(rows.map((i) => i.id));
    selected.value = selected.value.filter((id) => ids.has(id));
});
/** @type {import('vue').Ref<Set<number>>} */
const retrying = ref(new Set());
const retryNotice = ref('');
/** @param {CalendarItem} item */
const retry = async (item) => {
    if (retrying.value.has(item.id)) return;
    retrying.value = new Set(retrying.value).add(item.id);
    /** @type {import('@/Composables/useWorkflow').WorkflowResult<{ retried: number }>} */
    const result = await workflowRequest('POST', route('workflow.retry', item.id));
    const next = new Set(retrying.value);
    next.delete(item.id);
    retrying.value = next;
    retryNotice.value = result.ok ? (result.data?.retried ? 'Reintento enviado.' : 'No había nada que reintentar: ya está en curso o publicada.') : workflowError(result);
    router.reload({ only: ['items', 'undated', 'flash'] });
};
</script>

<template>
    <Head title="Calendario" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900">Calendario</h1>
                <div class="flex flex-wrap items-center gap-2">
                    <template v-if="createHref">
                        <Link :href="createHref" class="btn-primary">Crear publicación</Link>
                        <BestTimeSuggestion
                            v-if="suggestionBrand"
                            :brand="suggestionBrand"
                            :networks="suggestionNetworks"
                            :require-network-choice="!filters.network"
                            @apply="createAtBestTime"
                        />
                    </template>
                    <span v-else class="text-sm text-slate-500">Elige una marca para crear una publicación</span>
                </div>
            </div>
        </template>

        <div>
            <div class="w-full space-y-4">
                <!-- Barra: vistas, navegación, filtros -->
                <CalendarToolbar
                    :view="view"
                    :date="date"
                    :prev="prev"
                    :next="next"
                    :today="today"
                    :timezone="timezone"
                    :title="title"
                    :brands="brands"
                    :networks="networks"
                    :filters="filters"
                    :views="views"
                    :active-view="active_view"
                    :tab-props="tabProps"
                    @navigate="go"
                />

                <CalendarFeedPanel :feed="feed" :marketing-hidden="marketing_dates_hidden" />

                <div v-bind="panelProps(view)" class="space-y-3">
                    <EmptyState
                        v-if="items.length === 0 && undated.length === 0"
                        title="No hay publicaciones en este periodo"
                        description="Programa tu primera publicación y la verás aquí, con el estado en cada red."
                    >
                        <Link v-if="createHref" :href="createHref" class="btn-primary">Crear publicación</Link>
                        <Link v-else :href="route('brands.index')" class="btn-primary">Crear una marca</Link>
                    </EmptyState>

                    <p
                        v-if="view === 'list' && csvInfo?.truncated"
                        class="text-sm text-amber-800"
                        role="status"
                        data-testid="export-truncated"
                    >
                        Hay {{ csvInfo.total }} filas que exportar y el CSV incluye las primeras {{ csvInfo.max }}.
                        Filtra por marca, red o estado para exportar el resto.
                    </p>

                    <p
                        v-if="retryNotice"
                        class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900"
                        role="status"
                        data-testid="retry-notice"
                        >{{ retryNotice }}</p
                    >
                    <section
                        v-if="view === 'list' && marketing_dates.length"
                        class="card p-3"
                        aria-labelledby="marketing-dates-title"
                    >
                        <h2 id="marketing-dates-title" class="text-sm font-semibold text-slate-900"
                            >Fechas señaladas del periodo</h2
                        >
                        <ul class="mt-2 space-y-1 text-sm">
                            <li v-for="m in marketing_dates" :key="m.date + m.name" data-testid="marketing-date">
                                <span
                                    class="rounded border border-fuchsia-200 bg-fuchsia-50 px-1 text-xs font-medium text-fuchsia-900"
                                    >{{ m.name }}</span
                                >
                                <span class="ml-2 text-slate-600">{{ longDayLabel(m.date) }}</span>
                            </li>
                        </ul>
                    </section>
                    <BatchBar v-if="view === 'list'" :selected="selected" :items="allRows" @clear="selected = []" />

                    <p v-if="truncated" class="text-sm text-amber-700"
                        >Hay muchas publicaciones: se muestran las primeras 500. Filtra por marca o red.</p
                    >

                    <!-- SEMANA: franjas horarias -->
                    <div
                        v-if="view === 'week'"
                        class="card relative overflow-x-auto"
                        tabindex="0"
                        role="region"
                        aria-label="Calendario semanal"
                    >
                        <div class="grid min-w-[56rem]" style="grid-template-columns: 3.5rem repeat(7, minmax(0, 1fr))">
                            <div></div>
                            <div
                                v-for="d in days"
                                :key="d"
                                class="border-b border-l border-slate-200 p-2 text-center text-xs font-medium"
                                :class="d === today ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600'"
                                >{{ weekdayLabel(d) }}
                                <span
                                    v-for="m in marketingAt(d)"
                                    :key="m.name"
                                    class="mt-1 block rounded border border-fuchsia-200 bg-fuchsia-50 px-1 text-[11px] font-medium text-fuchsia-900"
                                    data-testid="marketing-date"
                                    ><span class="sr-only">Fecha señalada: </span>{{ m.name }}</span
                                ></div
                            >
                            <template v-for="h in hours" :key="h">
                                <div class="border-b border-slate-100 pr-1 text-right text-[10px] text-slate-500"
                                    >{{ pad(h) }}:00</div
                                >
                                <div
                                    v-for="d in days"
                                    :key="`${d}-${h}`"
                                    class="min-h-[2.25rem] space-y-1 border-b border-l border-slate-100 p-0.5"
                                    @dragover.prevent
                                    @drop.prevent="drop(d, h)"
                                >
                                    <article
                                        v-for="item in itemsAt(d, h)"
                                        :key="item.id"
                                        :draggable="item.editable"
                                        class="rounded border p-1 text-xs shadow-sm"
                                        :class="item.editable ? 'cursor-move bg-white' : 'bg-slate-50'"
                                        @dragstart="dragStart(item, $event)"
                                    >
                                        <Link :href="route('posts.edit', item.id)" class="block">
                                            <span class="flex flex-wrap items-center gap-1">
                                                <span class="text-slate-600">{{ item.local_time }}</span>
                                                <span
                                                    v-for="t in item.targets"
                                                    :key="t.network + t.content_type + t.account_name"
                                                    class="rounded border px-1"
                                                    :style="networkOf(t.network).style"
                                                    :title="`${t.network_label} · ${t.status_label}`"
                                                    >{{ networkOf(t.network).short }}</span
                                                >
                                                <StatusBadge :status="item.status" :label="item.status_label" />
                                                <span
                                                    v-if="item.approval_status"
                                                    class="rounded px-1 text-[11px] font-medium"
                                                    :class="APPROVAL_STYLE[item.approval_status]"
                                                    data-testid="approval-badge"
                                                    >{{ APPROVAL[item.approval_status] }}</span
                                                >
                                                <span
                                                    v-if="item.series_id"
                                                    class="rounded bg-indigo-50 px-1 text-[11px] font-medium text-indigo-900"
                                                    data-testid="series-badge"
                                                    >Serie</span
                                                >
                                                <span
                                                    v-if="item.manual_pending"
                                                    class="rounded bg-slate-100 px-1 text-[11px] text-slate-800"
                                                    data-testid="manual-badge"
                                                    >Manual: toca publicar</span
                                                >
                                                <span
                                                    v-if="item.has_notes"
                                                    class="rounded bg-amber-100 px-1 text-[11px] text-amber-900"
                                                    data-testid="notes-indicator"
                                                    title="Esta publicación tiene notas internas"
                                                    ><span aria-hidden="true">📝 </span>Nota</span
                                                >
                                            </span>
                                            <span
                                                class="line-clamp-2 text-slate-800"
                                                >{{ item.text || 'Sin texto' }}</span
                                            >
                                        </Link>
                                        <span
                                            v-if="sending.has(item.id)"
                                            role="status"
                                            class="mt-0.5 block text-[11px] font-medium text-indigo-700"
                                            >Enviando…</span
                                        >
                                        <button
                                            v-else-if="item.can_publish_now"
                                            type="button"
                                            class="link mt-0.5 text-[11px]"
                                            :aria-label="`Publicar ahora la publicación ${item.id}`"
                                            @click="askPublishNow(item, $event)"
                                            >Publicar ahora</button
                                        >
                                    </article>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- MES -->
                    <div v-else-if="view === 'month'" class="card relative overflow-hidden">
                        <div
                            class="grid grid-cols-7 border-b border-slate-200 bg-slate-50 text-center text-xs font-medium text-slate-600"
                        >
                            <div
                                v-for="w in ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']"
                                :key="w"
                                class="p-2"
                                >{{ w }}</div
                            >
                        </div>
                        <div class="grid grid-cols-7">
                            <div
                                v-for="d in days"
                                :key="d"
                                class="min-h-[6.5rem] space-y-1 border-b border-l border-slate-100 p-1"
                                :class="d.slice(0, 7) === monthOf ? '' : 'bg-slate-50 text-slate-500'"
                                @dragover.prevent
                                @drop.prevent="drop(d)"
                            >
                                <div
                                    class="text-xs"
                                    :class="d === today ? 'font-bold text-indigo-700' : ''"
                                    >{{ Number(d.slice(8)) }}</div
                                >
                                <p
                                    v-for="m in marketingAt(d)"
                                    :key="m.name"
                                    class="rounded border border-fuchsia-200 bg-fuchsia-50 px-1 text-[11px] font-medium text-fuchsia-900"
                                    data-testid="marketing-date"
                                    ><span class="sr-only">Fecha señalada: </span>{{ m.name }}</p
                                >
                                <article
                                    v-for="item in itemsAt(d)"
                                    :key="item.id"
                                    :draggable="item.editable"
                                    class="rounded border p-1 text-xs shadow-sm"
                                    :class="item.editable ? 'cursor-move bg-white' : 'bg-slate-50'"
                                    @dragstart="dragStart(item, $event)"
                                >
                                    <Link :href="route('posts.edit', item.id)" class="block">
                                        <span class="flex flex-wrap items-center gap-1">
                                            <span class="text-slate-500">{{ item.local_time }}</span>
                                            <span
                                                v-if="item.series_id"
                                                class="rounded bg-indigo-50 px-1 text-[11px] font-medium text-indigo-900"
                                                data-testid="series-badge"
                                                >Serie</span
                                            >
                                            <span
                                                v-if="item.approval_status"
                                                class="rounded px-1 text-[11px] font-medium"
                                                :class="APPROVAL_STYLE[item.approval_status]"
                                                data-testid="approval-badge"
                                                >{{ APPROVAL[item.approval_status] }}</span
                                            >
                                            <span
                                                v-if="item.manual_pending"
                                                class="rounded bg-slate-100 px-1 text-[11px] text-slate-800"
                                                data-testid="manual-badge"
                                                >Manual</span
                                            >
                                            <span
                                                v-for="t in item.targets"
                                                :key="t.network + t.content_type + t.account_name"
                                                class="rounded border px-1"
                                                :style="networkOf(t.network).style"
                                                :title="`${t.network_label} · ${t.status_label}`"
                                                >{{ networkOf(t.network).short }}</span
                                            >
                                            <span
                                                v-if="item.has_notes"
                                                class="rounded bg-amber-100 px-1 text-[11px] text-amber-900"
                                                data-testid="notes-indicator"
                                                title="Esta publicación tiene notas internas"
                                                ><span aria-hidden="true">📝 </span>Nota</span
                                            >
                                        </span>
                                        <span class="line-clamp-1 text-slate-800">{{ item.text || 'Sin texto' }}</span>
                                    </Link>
                                    <span
                                        v-if="sending.has(item.id)"
                                        role="status"
                                        class="block text-[11px] font-medium text-indigo-700"
                                        >Enviando…</span
                                    >
                                    <button
                                        v-else-if="item.can_publish_now"
                                        type="button"
                                        class="link text-[11px]"
                                        :aria-label="`Publicar ahora la publicación ${item.id}`"
                                        @click="askPublishNow(item, $event)"
                                        >Publicar ahora</button
                                    >
                                </article>
                            </div>
                        </div>
                    </div>

                    <!-- LISTA: estado por red -->
                    <div
                        v-else-if="items.length || undated.length"
                        class="card overflow-x-auto"
                        tabindex="0"
                        role="region"
                        aria-label="Lista de publicaciones"
                    >
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="px-3 py-2">
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-slate-400 text-indigo-600"
                                            aria-label="Seleccionar todas las publicaciones"
                                            :checked="allSelected"
                                            data-testid="select-all"
                                            @change="toggleAll"
                                        />
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
                                    <td colspan="6" class="px-3 py-6 text-center text-slate-500"
                                        >No hay publicaciones en este periodo.</td
                                    >
                                </tr>
                                <tr
                                    v-for="item in [...items, ...undated]"
                                    :key="item.id"
                                    class="align-top"
                                    :data-testid="`row-${item.id}`"
                                >
                                    <td class="px-3 py-2">
                                        <input
                                            v-model="selected"
                                            type="checkbox"
                                            :value="item.id"
                                            class="h-4 w-4 rounded border-slate-400 text-indigo-600"
                                            :aria-label="`Seleccionar la publicación ${item.id}`"
                                            :data-testid="`select-${item.id}`"
                                        />
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        <template v-if="item.editable && item.local_datetime">
                                            <input
                                                type="datetime-local"
                                                :value="item.local_datetime"
                                                class="field w-auto py-1 text-xs"
                                                :aria-label="`Fecha de la publicación ${item.id}`"
                                                @change="rescheduleFromInput(item, $event)"
                                            />
                                        </template>
                                        <template v-else>{{ dateLabel(item) }}</template>
                                        <div class="text-xs text-slate-500">{{ item.brand_name }}</div>
                                    </td>
                                    <td class="max-w-xs px-3 py-2"
                                        ><Link
                                            :href="route('posts.edit', item.id)"
                                            class="text-slate-900 hover:underline"
                                            >{{ item.text || 'Sin texto' }}</Link
                                        >&#32;<span
                                            v-if="item.has_notes"
                                            class="rounded bg-amber-100 px-1 text-[11px] text-amber-900"
                                            data-testid="notes-indicator"
                                            title="Esta publicación tiene notas internas"
                                            ><span aria-hidden="true">📝 </span>Nota</span
                                        ></td
                                    >
                                    <td class="px-3 py-2">
                                        <div class="flex flex-wrap gap-1">
                                            <span
                                                v-for="t in item.targets"
                                                :key="t.network + t.content_type + t.account_name"
                                                class="rounded border px-1 text-xs"
                                                :style="networkOf(t.network).style"
                                                >{{ t.network_label }}</span
                                            >
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div
                                            v-if="item.approval_status || item.series_id"
                                            class="mb-1 flex flex-wrap gap-1"
                                        >
                                            <span
                                                v-if="item.approval_status"
                                                class="rounded px-1.5 py-0.5 text-[11px] font-medium"
                                                :class="APPROVAL_STYLE[item.approval_status]"
                                                data-testid="approval-badge"
                                                >{{ APPROVAL[item.approval_status] }}</span
                                            >
                                            <span
                                                v-if="item.series_id"
                                                class="rounded bg-indigo-50 px-1.5 py-0.5 text-[11px] font-medium text-indigo-900"
                                                data-testid="series-badge"
                                                >Serie</span
                                            >
                                        </div>
                                        <ul class="space-y-1">
                                            <li
                                                v-for="t in item.targets"
                                                :key="t.network + t.content_type + t.account_name"
                                                class="text-xs"
                                            >
                                                <span class="font-medium">{{ networkOf(t.network).short }}</span>
                                                <StatusBadge class="ml-1" :status="t.status" :label="t.status_label" />
                                                <span
                                                    v-if="t.manual"
                                                    class="ml-1 rounded bg-slate-100 px-1 text-[11px] text-slate-800"
                                                    data-testid="manual-badge"
                                                    >{{ t.manual_pending ? 'Manual: toca publicar' : 'Manual' }}</span
                                                >
                                                <a
                                                    v-if="t.external_url"
                                                    :href="t.external_url"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="link ml-1"
                                                    >Ver</a
                                                >
                                                <span
                                                    v-if="t.status_note"
                                                    class="block text-amber-700"
                                                    >{{ t.status_note }}</span
                                                >
                                                <span
                                                    v-if="t.error_message"
                                                    class="block text-red-600"
                                                    >{{ t.error_message }}</span
                                                >
                                            </li>
                                            <li v-if="item.targets.length === 0" class="text-xs text-slate-500"
                                                >Sin destinos</li
                                            >
                                        </ul>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        <Link
                                            :href="route('posts.edit', item.id)"
                                            class="link"
                                            >{{ item.editable ? 'Editar' : 'Ver' }}</Link
                                        >
                                        <span
                                            v-if="sending.has(item.id)"
                                            role="status"
                                            class="ms-3 text-xs font-medium text-indigo-700"
                                            >Enviando…</span
                                        >
                                        <button
                                            v-else-if="item.can_publish_now"
                                            type="button"
                                            class="link ms-3"
                                            :aria-label="`Publicar ahora la publicación ${item.id}`"
                                            @click="askPublishNow(item, $event)"
                                            >Publicar ahora</button
                                        >
                                        <button
                                            v-if="item.can_retry"
                                            type="button"
                                            class="link ms-3"
                                            :disabled="retrying.has(item.id)"
                                            :aria-label="`Reintentar la publicación ${item.id}`"
                                            data-testid="retry"
                                            @click="retry(item)"
                                            >Reintentar</button
                                        >
                                        <span
                                            v-if="item.retry_blocked"
                                            class="ms-3 text-xs text-amber-700"
                                            data-testid="retry-blocked"
                                            >{{ item.retry_blocked }}</span
                                        >
                                        <Link
                                            v-if="item.has_manual"
                                            :href="route('posts.manual.show', item.id)"
                                            class="link ms-3"
                                            :aria-label="`Abrir el aviso manual de la publicación ${item.id}`"
                                            data-testid="manual-link"
                                            >Aviso manual</Link
                                        >
                                        <button
                                            type="button"
                                            class="link ms-3"
                                            :aria-label="`Guardar la publicación ${item.id} como plantilla`"
                                            @click="saveToLibrary(item)"
                                            >Guardar como plantilla</button
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <PublishNowConfirm
            v-if="confirming"
            :item="confirming"
            :opener="opener"
            @confirm="publishNow"
            @cancel="confirming = null"
        />
    </AuthenticatedLayout>
</template>
