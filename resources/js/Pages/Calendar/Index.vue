<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
    filters: Object,
    flash: Object,
});

const NETWORK_STYLE = {
    instagram: { short: 'IG', classes: 'bg-pink-100 text-pink-800 border-pink-300' },
    facebook: { short: 'FB', classes: 'bg-blue-100 text-blue-800 border-blue-300' },
    youtube: { short: 'YT', classes: 'bg-red-100 text-red-800 border-red-300' },
    tiktok: { short: 'TT', classes: 'bg-gray-800 text-white border-gray-900' },
};
const STATUS_STYLE = {
    borrador: 'bg-gray-100 text-gray-700',
    programada: 'bg-blue-100 text-blue-800',
    publicando: 'bg-amber-100 text-amber-800',
    procesando: 'bg-amber-100 text-amber-800',
    publicada: 'bg-green-100 text-green-800',
    fallida: 'bg-red-100 text-red-800',
};

const go = (params) =>
    router.get(route('calendar.index'), { ...props.filters, view: props.view, date: props.date, ...params }, { preserveState: true, preserveScroll: true, replace: true });

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
const title = computed(() => {
    const d = new Date(`${props.date}T00:00:00Z`);
    return props.view === 'month'
        ? d.toLocaleDateString('es', { month: 'long', year: 'numeric', timeZone: 'UTC' })
        : `${new Date(`${props.from}T00:00:00Z`).toLocaleDateString('es', { day: 'numeric', month: 'short', timeZone: 'UTC' })} – ${new Date(`${props.to}T00:00:00Z`).toLocaleDateString('es', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })}`;
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
const createBrand = computed(() => props.filters.brand_id ?? (props.brands.length === 1 ? props.brands[0].id : null));
const createHref = computed(() => (createBrand.value ? route('brands.posts.create', createBrand.value) : null));
const networkOf = (n) => NETWORK_STYLE[n] ?? { short: n, classes: 'bg-gray-100 text-gray-700 border-gray-300' };
const dateLabel = (item) => (item.local_date ? `${item.local_date} ${item.local_time}` : 'Sin fecha');
</script>

<template>
    <Head title="Calendario" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Calendario</h2>
                <div class="flex items-center gap-2">
                    <Link v-if="createHref" :href="createHref"><PrimaryButton type="button">Crear publicación</PrimaryButton></Link>
                    <span v-else class="text-sm text-gray-500">Elige una marca para crear una publicación</span>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                <p v-if="flash.success" class="rounded bg-green-50 p-3 text-sm text-green-800" role="status">{{ flash.success }}</p>
                <p v-if="flash.error" class="rounded bg-red-50 p-3 text-sm text-red-800" role="alert">{{ flash.error }}</p>

                <!-- Barra: vistas, navegación, filtros -->
                <div class="flex flex-wrap items-center gap-3 rounded-lg bg-white p-3 shadow-sm">
                    <div class="inline-flex overflow-hidden rounded-md border border-gray-300 text-sm" role="tablist">
                        <button v-for="v in [['week', 'Semana'], ['month', 'Mes'], ['list', 'Lista']]" :key="v[0]" type="button" role="tab" :aria-selected="view === v[0]" class="px-3 py-1.5" :class="view === v[0] ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700'" @click="go({ view: v[0] })">{{ v[1] }}</button>
                    </div>
                    <div class="flex items-center gap-1 text-sm">
                        <button type="button" class="rounded border border-gray-300 px-2 py-1" aria-label="Anterior" @click="go({ date: prev })">‹</button>
                        <button type="button" class="rounded border border-gray-300 px-2 py-1" @click="go({ date: today })">Hoy</button>
                        <button type="button" class="rounded border border-gray-300 px-2 py-1" aria-label="Siguiente" @click="go({ date: next })">›</button>
                    </div>
                    <span class="font-medium capitalize text-gray-800">{{ title }}</span>
                    <span class="text-xs text-gray-500">Zona horaria: {{ timezone }}</span>

                    <div class="ml-auto flex flex-wrap items-center gap-2 text-sm">
                        <select :value="filters.brand_id ?? ''" class="rounded-md border-gray-300 py-1 text-sm" aria-label="Marca" @change="setFilter('brand_id', $event.target.value)">
                            <option value="">Todas las marcas</option>
                            <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                        <select :value="filters.network ?? ''" class="rounded-md border-gray-300 py-1 text-sm" aria-label="Red" @change="setFilter('network', $event.target.value)">
                            <option value="">Todas las redes</option>
                            <option v-for="n in networks" :key="n.value" :value="n.value">{{ n.label }}</option>
                        </select>
                        <template v-if="view === 'list'">
                            <select :value="filters.status ?? ''" class="rounded-md border-gray-300 py-1 text-sm" aria-label="Estado" @change="setFilter('status', $event.target.value)">
                                <option value="">Todos los estados</option>
                                <option v-for="s in ['borrador', 'programada', 'publicando', 'procesando', 'publicada', 'fallida']" :key="s" :value="s">{{ s }}</option>
                            </select>
                            <input type="search" :value="filters.q ?? ''" placeholder="Buscar texto" class="rounded-md border-gray-300 py-1 text-sm" @keyup.enter="setFilter('q', $event.target.value)" />
                        </template>
                    </div>
                </div>

                <p v-if="truncated" class="text-sm text-amber-700">Hay muchas publicaciones: se muestran las primeras 500. Filtra por marca o red.</p>

                <!-- SEMANA: franjas horarias -->
                <div v-if="view === 'week'" class="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <div class="grid min-w-[56rem]" style="grid-template-columns: 3.5rem repeat(7, minmax(0, 1fr))">
                        <div></div>
                        <div v-for="d in days" :key="d" class="border-b border-l border-gray-200 p-2 text-center text-xs font-medium" :class="d === today ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600'">{{ weekdayLabel(d) }}</div>
                        <template v-for="h in hours" :key="h">
                            <div class="border-b border-gray-100 pr-1 text-right text-[10px] text-gray-400">{{ pad(h) }}:00</div>
                            <div v-for="d in days" :key="`${d}-${h}`" class="min-h-[2.25rem] space-y-1 border-b border-l border-gray-100 p-0.5" @dragover.prevent @drop.prevent="drop(d, h)">
                                <article v-for="item in itemsAt(d, h)" :key="item.id" :draggable="item.editable" class="rounded border p-1 text-xs shadow-sm" :class="item.editable ? 'cursor-move bg-white' : 'bg-gray-50'" @dragstart="dragStart(item, $event)">
                                    <Link :href="route('posts.edit', item.id)" class="block">
                                        <span class="flex flex-wrap items-center gap-1">
                                            <span class="text-gray-500">{{ item.local_time }}</span>
                                            <span v-for="t in item.targets" :key="t.network + t.content_type + t.account_name" class="rounded border px-1" :class="networkOf(t.network).classes" :title="`${t.network_label} · ${t.status_label}`">{{ networkOf(t.network).short }}</span>
                                            <span class="rounded px-1" :class="STATUS_STYLE[item.status]">{{ item.status_label }}</span>
                                        </span>
                                        <span class="line-clamp-2 text-gray-800">{{ item.text || 'Sin texto' }}</span>
                                    </Link>
                                </article>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- MES -->
                <div v-else-if="view === 'month'" class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="grid grid-cols-7 border-b border-gray-200 bg-gray-50 text-center text-xs font-medium text-gray-600">
                        <div v-for="w in ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']" :key="w" class="p-2">{{ w }}</div>
                    </div>
                    <div class="grid grid-cols-7">
                        <div v-for="d in days" :key="d" class="min-h-[6.5rem] space-y-1 border-b border-l border-gray-100 p-1" :class="d.slice(0, 7) === monthOf ? '' : 'bg-gray-50 text-gray-400'" @dragover.prevent @drop.prevent="drop(d)">
                            <div class="text-xs" :class="d === today ? 'font-bold text-indigo-700' : ''">{{ Number(d.slice(8)) }}</div>
                            <article v-for="item in itemsAt(d)" :key="item.id" :draggable="item.editable" class="rounded border bg-white p-1 text-xs shadow-sm" :class="item.editable ? 'cursor-move' : 'opacity-80'" @dragstart="dragStart(item, $event)">
                                <Link :href="route('posts.edit', item.id)" class="block">
                                    <span class="flex flex-wrap items-center gap-1">
                                        <span class="text-gray-500">{{ item.local_time }}</span>
                                        <span v-for="t in item.targets" :key="t.network + t.content_type + t.account_name" class="rounded border px-1" :class="networkOf(t.network).classes" :title="`${t.network_label} · ${t.status_label}`">{{ networkOf(t.network).short }}</span>
                                    </span>
                                    <span class="line-clamp-1 text-gray-800">{{ item.text || 'Sin texto' }}</span>
                                </Link>
                            </article>
                        </div>
                    </div>
                </div>

                <!-- LISTA: estado por red -->
                <div v-else class="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2">Contenido</th>
                                <th class="px-3 py-2">Redes</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="items.length === 0 && undated.length === 0">
                                <td colspan="5" class="px-3 py-6 text-center text-gray-500">No hay publicaciones en este periodo.</td>
                            </tr>
                            <tr v-for="item in [...items, ...undated]" :key="item.id" class="align-top">
                                <td class="whitespace-nowrap px-3 py-2">
                                    <template v-if="item.editable && item.local_datetime">
                                        <input type="datetime-local" :value="item.local_datetime" class="rounded border-gray-300 py-0.5 text-xs" :aria-label="`Fecha de la publicación ${item.id}`" @change="reschedule(item, $event.target.value)" />
                                    </template>
                                    <template v-else>{{ dateLabel(item) }}</template>
                                    <div class="text-xs text-gray-500">{{ item.brand_name }}</div>
                                </td>
                                <td class="max-w-xs px-3 py-2"><Link :href="route('posts.edit', item.id)" class="text-gray-900 hover:underline">{{ item.text || 'Sin texto' }}</Link></td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-for="t in item.targets" :key="t.network + t.content_type + t.account_name" class="rounded border px-1 text-xs" :class="networkOf(t.network).classes">{{ t.network_label }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <ul class="space-y-1">
                                        <li v-for="t in item.targets" :key="t.network + t.content_type + t.account_name" class="text-xs">
                                            <span class="font-medium">{{ networkOf(t.network).short }}</span>
                                            <span class="ml-1 rounded px-1.5 py-0.5" :class="STATUS_STYLE[t.status]">{{ t.status_label }}</span>
                                            <a v-if="t.external_url" :href="t.external_url" target="_blank" rel="noopener" class="ml-1 text-indigo-600">Ver</a>
                                            <span v-if="t.error_message" class="block text-red-600">{{ t.error_message }}</span>
                                        </li>
                                        <li v-if="item.targets.length === 0" class="text-xs text-gray-400">Sin destinos</li>
                                    </ul>
                                </td>
                                <td class="px-3 py-2 text-right"><Link :href="route('posts.edit', item.id)" class="text-indigo-600">{{ item.editable ? 'Editar' : 'Ver' }}</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
