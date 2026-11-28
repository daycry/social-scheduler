<script setup>
import { Link } from '@inertiajs/vue3';

/** @typedef {import('@/types/pagination').CursorPage} CursorPage */
/** @typedef {Record<string, string | number | boolean | null | undefined>} QueryParams */

defineOptions({ name: 'CursorPagination' });

defineProps({
    page: { type: /** @type {import('vue').PropType<CursorPage>} */ (Object), required: true },
    routeName: { type: /** @type {import('vue').PropType<keyof import('ziggy-js').RouteList>} */ (String), required: true },
    params: { type: /** @type {import('vue').PropType<QueryParams>} */ (Object), default: () => ({}) },
    label: { type: String, default: 'Paginación' },
});
</script>

<template>
    <nav class="flex flex-wrap items-center justify-between gap-3 text-sm" :aria-label="label" data-testid="pagination">
        <span v-if="page.total != null" class="text-foreground-muted">{{ page.total }} en total</span>
        <div v-if="page.cursor || page.has_more" class="flex flex-wrap gap-2">
            <Link
                v-if="page.cursor"
                :href="route(routeName, { ...params, limit: page.limit })"
                class="btn btn-secondary"
                >Primera página</Link
            >
            <Link
                v-if="page.has_more"
                :href="route(routeName, { ...params, limit: page.limit, cursor: page.next_cursor })"
                class="btn btn-secondary"
                >Siguiente</Link
            >
        </div>
    </nav>
</template>
