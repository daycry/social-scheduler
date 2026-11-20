<script setup>
/** @import { CalendarBrand, CalendarFilters, CalendarNavigation, CalendarViewMode, SavedCalendarView } from '@/types/calendar' */
/** @import { NetworkOption } from '@/types/shell' */
import ViewsMenu from '@/Components/Calendar/ViewsMenu.vue';
import { onTabsKeydown } from '@/Composables/useTabs';
import { computed } from 'vue';

const props = defineProps({
    view: { type: /** @type {import('vue').PropType<CalendarViewMode>} */ (String), required: true },
    date: { type: String, required: true },
    prev: { type: String, required: true },
    next: { type: String, required: true },
    today: { type: String, required: true },
    timezone: { type: String, required: true },
    title: { type: String, required: true },
    brands: { type: /** @type {import('vue').PropType<CalendarBrand[]>} */ (Array), required: true },
    networks: { type: /** @type {import('vue').PropType<NetworkOption[]>} */ (Array), required: true },
    filters: { type: /** @type {import('vue').PropType<CalendarFilters>} */ (Object), required: true },
    views: { type: /** @type {import('vue').PropType<SavedCalendarView[]>} */ (Array), required: true },
    activeView: { type: /** @type {import('vue').PropType<number | null>} */ (Number), default: null },
    tabProps: { type: /** @type {import('vue').PropType<ReturnType<typeof import('@/Composables/useTabs').useTabs>['tabProps']>} */ (Function), required: true },
});
const emit = defineEmits({
    /** @param {CalendarNavigation} params */
    navigate: (params) => params !== null && typeof params === 'object',
});
/** @param {CalendarNavigation} params */
const go = (params) => emit('navigate', params);

/** @type {[CalendarViewMode, string][]} */
const viewTabs = [['week', 'Semana'], ['month', 'Mes'], ['list', 'Lista']];

// Exportar CSV (C-03c): mismos filtros que la lista (los de la vista guardada ya vienen aplicados en `filters`).
const exportHref = computed(() => route('exports.posts', Object.fromEntries(Object.entries({ ...props.filters, date: props.date }).filter(([, v]) => v !== null && v !== undefined && v !== ''))));

/** @param {keyof CalendarFilters} key @param {Event} event */
const setFilter = (key, event) => {
    const input = event.currentTarget;
    if (input instanceof HTMLSelectElement || input instanceof HTMLInputElement) {
        go({ [key]: input.value || undefined });
    }
};
</script>

<template>
    <div class="flex flex-wrap items-center gap-3 card p-3">
        <div
            class="inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm"
            role="tablist"
            aria-label="Vista del calendario"
            @keydown="onTabsKeydown"
        >
            <button
                v-for="v in viewTabs"
                :key="v[0]"
                type="button"
                v-bind="tabProps(v[0])"
                class="px-3 py-1.5"
                :class="view === v[0] ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 hover:bg-slate-50'"
                @click="go({ view: v[0] })"
                >{{ v[1] }}</button
            >
        </div>
        <div class="flex items-center gap-1 text-sm">
            <button
                type="button"
                class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 hover:bg-slate-50"
                aria-label="Anterior"
                @click="go({ date: prev })"
                >‹</button
            >
            <button
                type="button"
                class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 hover:bg-slate-50"
                @click="go({ date: today })"
                >Hoy</button
            >
            <button
                type="button"
                class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 hover:bg-slate-50"
                aria-label="Siguiente"
                @click="go({ date: next })"
                >›</button
            >
        </div>
        <span class="font-medium capitalize text-slate-800">{{ title }}</span>
        <span class="text-xs text-slate-500">Zona horaria: {{ timezone }}</span>

        <div class="ml-auto flex min-w-0 max-w-full flex-wrap items-center gap-2 text-sm">
            <ViewsMenu :views="views" :active-view="activeView" :filters="filters" :view="view" :date="date" />
            <select
                :value="filters.brand_id ?? ''"
                class="field w-auto py-1.5"
                aria-label="Marca"
                @change="setFilter('brand_id', $event)"
            >
                <option value="">Todas las marcas</option>
                <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
            <select
                :value="filters.network ?? ''"
                class="field w-auto py-1.5"
                aria-label="Red"
                @change="setFilter('network', $event)"
            >
                <option value="">Todas las redes</option>
                <option v-for="n in networks" :key="n.value" :value="n.value">{{ n.label }}</option>
            </select>
            <template v-if="view === 'list'">
                <select
                    :value="filters.status ?? ''"
                    class="field w-auto py-1.5"
                    aria-label="Estado"
                    @change="setFilter('status', $event)"
                >
                    <option value="">Todos los estados</option>
                    <option
                        v-for="s in ['borrador', 'programada', 'publicando', 'procesando', 'publicada', 'fallida']"
                        :key="s"
                        :value="s"
                        >{{ s }}</option
                    >
                </select>
                <input
                    type="search"
                    :value="filters.q ?? ''"
                    placeholder="Buscar texto"
                    aria-label="Buscar texto"
                    class="field w-auto py-1.5"
                    @keyup.enter="setFilter('q', $event)"
                />
                <a :href="exportHref" class="btn-secondary" data-testid="export-posts">Exportar CSV</a>
            </template>
        </div>
    </div>
</template>
