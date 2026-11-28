<script setup>
import { computed, reactive, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import Pagination from '@/Components/Pagination.vue';
import { createDateFormatter } from '@/lib/format';

const props = defineProps({
    entries: { type: /** @type {import('vue').PropType<import('@/types/activity').ActivityEntry[]>} */ (Array), required: true },
    pagination: { type: /** @type {import('vue').PropType<import('@/types/pagination').CursorPage>} */ (Object), required: true },
    filters: { type: /** @type {import('vue').PropType<import('@/types/activity').ActivityFilters>} */ (Object), required: true },
    brands: { type: /** @type {import('vue').PropType<import('@/types/shell').BrandRef[]>} */ (Array), required: true },
    users: { type: /** @type {import('vue').PropType<import('@/types/activity').ActivityActor[]>} */ (Array), required: true },
    actions: { type: /** @type {import('vue').PropType<string[]>} */ (Array), required: true },
    timezone: { type: String, required: true },
    retention_days: { type: Number, required: true },
});
const page = usePage();
const filters = reactive({ ...props.filters });
watch(() => props.filters, (value) => Object.assign(filters, value));
const formatDate = computed(() => createDateFormatter(props.timezone, { dateStyle: 'medium', timeStyle: 'short' }, '—'));
/** @type {Record<string, string>} */
const outcomeLabels = { ok: 'Completada', denied: 'Acceso denegado', error: 'Error' };
const submit = () => {
    const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== null && value !== ''));
    router.get(route('activity.index'), query, { preserveState: true, preserveScroll: true });
};
</script>

<template>
    <Head title="Actividad" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-foreground-strong">Actividad</h1></template>

        <div class="space-y-5">
            <p class="text-sm text-foreground-muted"
                >Consulta las acciones registradas de tu cuenta y de tus marcas. Las acciones anteriores o sin una marca
                identificada aparecen en el historial general.</p
            >
            <p class="text-sm text-foreground-muted" data-testid="activity-retention">
                <template v-if="retention_days > 0"
                    >El historial se conserva durante {{ retention_days }} días. La limpieza se ejecuta
                    diariamente.</template
                >
                <template v-else>La limpieza automática del historial está desactivada.</template>
                Las fechas se muestran en {{ timezone }}.
            </p>
            <form
                class="card grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3"
                aria-label="Filtros de actividad"
                @submit.prevent="submit"
            >
                <FormField v-slot="{ control }" id="activity-brand" :error="page.props.errors.brand_id">
                    <div
                        ><label for="activity-brand" class="block text-sm font-medium text-foreground-secondary"
                            >Marca</label
                        >
                        <select v-bind="control" v-model="filters.brand_id" class="field">
                            <option :value="null">Todas mis marcas y mi cuenta</option>
                            <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                        </select>
                    </div>
                </FormField>
                <FormField v-slot="{ control }" id="activity-user" :error="page.props.errors.user_id">
                    <div
                        ><label for="activity-user" class="block text-sm font-medium text-foreground-secondary"
                            >Usuario</label
                        >
                        <select v-bind="control" v-model="filters.user_id" class="field">
                            <option :value="null">Todos los usuarios del historial</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                    </div>
                </FormField>
                <FormField v-slot="{ control }" id="activity-action" :error="page.props.errors.action">
                    <div
                        ><label for="activity-action" class="block text-sm font-medium text-foreground-secondary"
                            >Acción</label
                        >
                        <select v-bind="control" v-model="filters.action" class="field">
                            <option :value="null">Todas las acciones</option>
                            <option v-for="action in actions" :key="action" :value="action">{{ action }}</option>
                        </select>
                    </div>
                </FormField>
                <FormField v-slot="{ control }" id="activity-from" :error="page.props.errors.from">
                    <div
                        ><label for="activity-from" class="block text-sm font-medium text-foreground-secondary"
                            >Desde</label
                        ><input v-bind="control" v-model="filters.from" type="date" class="field"
                    /></div>
                </FormField>
                <FormField v-slot="{ control }" id="activity-to" :error="page.props.errors.to">
                    <div
                        ><label for="activity-to" class="block text-sm font-medium text-foreground-secondary"
                            >Hasta</label
                        ><input v-bind="control" v-model="filters.to" type="date" class="field"
                    /></div>
                </FormField>
                <div class="flex items-end"
                    ><button class="btn btn-primary" type="submit">Filtrar actividad</button></div
                >
            </form>

            <EmptyState
                v-if="entries.length === 0"
                title="No hay actividad"
                description="No hay acciones registradas con estos filtros."
            />
            <div
                v-else
                class="card overflow-x-auto focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                role="region"
                aria-label="Historial de actividad"
                tabindex="0"
            >
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Historial de actividad</caption>
                    <thead class="bg-canvas text-foreground-secondary">
                        <tr>
                            <th scope="col" class="px-4 py-3">Fecha</th>
                            <th scope="col" class="px-4 py-3">Usuario</th>
                            <th scope="col" class="px-4 py-3">Marca</th>
                            <th scope="col" class="px-4 py-3">Acción</th>
                            <th scope="col" class="px-4 py-3">Resultado</th>
                            <th scope="col" class="px-4 py-3">Canal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-subtle">
                        <tr v-for="entry in entries" :key="entry.id" data-testid="activity-entry">
                            <td class="whitespace-nowrap px-4 py-3">{{ formatDate(entry.created_at) }}</td>
                            <td class="px-4 py-3">{{ entry.user }}</td>
                            <td class="px-4 py-3">{{ entry.brand ?? 'Sin marca identificada' }}</td>
                            <td class="px-4 py-3">{{ entry.action }}</td>
                            <td class="px-4 py-3">{{ outcomeLabels[entry.outcome] ?? entry.outcome }}</td>
                            <td class="px-4 py-3">{{ entry.channel }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination
                :page="pagination"
                :params="{ ...props.filters }"
                route-name="activity.index"
                label="Paginación de actividad"
            />
        </div>
    </AuthenticatedLayout>
</template>
