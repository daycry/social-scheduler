<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    report: {
        type: /** @type {import('vue').PropType<import('@/types/system-status').SystemStatusReport>} */ (Object),
        required: true,
    },
});
const healthStates = {
    ok: { label: 'Operativa', class: 'text-green-800' },
    degraded: { label: 'Operativa con avisos', class: 'text-amber-800' },
    down: { label: 'Hay comprobaciones fallidas', class: 'text-red-700' },
};
const healthState = computed(() => healthStates[props.report.health.status]);
const dates = new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'medium', timeZone: 'UTC' });
/** @type {Record<string, string>} */
const names = {
    database: 'Base de datos',
    queue: 'Colas',
    storage: 'Almacenamiento',
    scheduler: 'Cron',
    snapshots: 'Métricas',
    config: 'Configuración',
    linkedin_version: 'API de LinkedIn',
    backup: 'Copias de seguridad',
    native: 'Herramientas nativas',
};
/** @param {string | null} value */
function date(value) {
    const time = value === null ? NaN : Date.parse(value);
    return Number.isFinite(time) ? dates.format(time) : 'No informada';
}
</script>

<template>
    <Head title="Estado del sistema" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-slate-900">Estado del sistema</h1>
        </template>
        <div class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-600"
                        >Datos globales de esta instalación. Las fechas se muestran en UTC.</p
                    >
                    <p class="text-sm text-slate-600"
                        >Última actualización:
                        <time :datetime="report.observed_at">{{ date(report.observed_at) }}</time></p
                    >
                </div>
                <Link :href="route('system.index')" class="btn-secondary" preserve-scroll>Actualizar estado</Link>
            </div>
            <section class="space-y-3" aria-labelledby="system-health-title">
                <h2 id="system-health-title" class="text-lg font-semibold text-slate-900">Salud de la instalación</h2>
                <p v-if="report.health.checks.native && report.native_cache_seconds" class="text-sm text-slate-600"
                    >Las herramientas nativas pueden reutilizar su última comprobación durante hasta
                    {{ report.native_cache_seconds }} segundos.</p
                >
                <p role="status" data-testid="system-health-status" class="font-medium" :class="healthState.class">
                    {{ healthState.label }}
                </p>
                <ul class="grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="(check, key) in report.health.checks"
                        :key="key"
                        class="min-w-0 rounded-lg border border-slate-200 bg-white p-4"
                    >
                        <h3 class="font-semibold text-slate-900"
                            >{{ names[key] ?? key }} · {{ !check.ok ? 'Fallo' : check.warn ? 'Aviso' : 'Correcto' }}</h3
                        >
                        <p class="break-words text-sm text-slate-700">{{ check.detail }}</p>
                    </li>
                </ul>
            </section>
            <section class="space-y-3" aria-labelledby="system-queue-title">
                <h2 id="system-queue-title" class="text-lg font-semibold text-slate-900">Sondas de trabajadores</h2>
                <p class="text-sm text-slate-600"
                    >La fecha confirma cuándo un trabajador procesó una prueba. Una cola vacía no demuestra que haya un
                    trabajador activo. Abrir esta pantalla no encola pruebas nuevas.</p
                >
                <ul class="grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="queue in report.queues"
                        :key="queue.name"
                        class="min-w-0 rounded-lg border border-slate-200 bg-white p-4"
                    >
                        <h3 class="break-words font-semibold text-slate-900">{{ queue.name }}</h3>
                        <p v-if="!queue.available" class="text-sm text-amber-800"
                            >No se pudo leer el estado de la sonda.</p
                        >
                        <p
                            v-else
                            class="text-sm text-slate-700"
                            >{{ queue.last_processed_at ? `Última prueba procesada: ${date(queue.last_processed_at)} UTC` : 'Sin sonda procesada en las últimas 24 horas.' }}</p
                        >
                    </li>
                </ul>
            </section>
            <section class="space-y-3" aria-labelledby="system-token-title">
                <h2 id="system-token-title" class="text-lg font-semibold text-slate-900">Caducidad de credenciales</h2>
                <p class="text-sm text-slate-600"
                    >La fecha más temprana informada por red. Una fecha desconocida no significa que la credencial siga
                    vigente.</p
                >
                <p v-if="!report.tokens_available" role="alert" class="text-sm text-amber-800"
                    >No se pudieron leer las caducidades de credenciales.</p
                >
                <p v-else-if="!Object.keys(report.tokens).length" class="text-sm text-slate-700"
                    >No hay cuentas conectadas.</p
                >
                <ul v-else class="grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="(token, network) in report.tokens"
                        :key="network"
                        class="min-w-0 space-y-1 rounded-lg border border-slate-200 bg-white p-4"
                    >
                        <h3 class="font-semibold text-slate-900"
                            >{{ report.network_labels[network] ?? network }} · {{ token.account_count }}
                            {{ token.account_count === 1 ? 'cuenta' : 'cuentas' }}</h3
                        >
                        <dl class="space-y-1 text-sm text-slate-700">
                            <div
                                ><dt class="inline font-medium">Acceso: </dt
                                ><dd class="inline">{{ date(token.expires_at) }}</dd></div
                            >
                            <div
                                ><dt class="inline font-medium">Renovación: </dt
                                ><dd class="inline">{{ date(token.refresh_expires_at) }}</dd></div
                            >
                            <div
                                ><dt class="inline font-medium">Acceso a datos: </dt
                                ><dd class="inline">{{ date(token.data_access_expires_at) }}</dd></div
                            >
                        </dl>
                        <p v-if="token.unknown_expiry_count" class="text-sm text-amber-800"
                            >{{ token.unknown_expiry_count }}
                            {{ token.unknown_expiry_count === 1 ? 'cuenta' : 'cuentas' }} sin fecha de caducidad de
                            acceso.</p
                        >
                    </li>
                </ul>
            </section>
            <section class="space-y-3" aria-labelledby="system-quota-title">
                <h2 id="system-quota-title" class="text-lg font-semibold text-slate-900">Cuotas por red</h2>
                <p class="text-sm text-slate-600"
                    >Al agotar el cupo:
                    {{ report.quota_policy === 'fail' ? 'se marca el destino como fallido' : 'se aplaza dentro del margen permitido'
                    }}.</p
                >
                <p class="text-sm text-slate-600"
                    >El contador local de YouTube no incluye consumos externos a esta instalación. No se consulta el
                    cupo a las redes sociales al abrir esta pantalla.</p
                >
                <ul class="grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="(quota, network) in report.quotas"
                        :key="network"
                        class="min-w-0 space-y-1 rounded-lg border border-slate-200 bg-white p-4"
                    >
                        <h3 class="font-semibold text-slate-900">{{ report.network_labels[network] ?? network }}</h3>
                        <template v-if="quota.source === 'local_counter'">
                            <p class="text-sm text-slate-700"
                                >Consumo local: {{ quota.used }} / {{ quota.limit }} unidades</p
                            >
                            <p class="text-sm text-slate-700">Leído: {{ date(quota.observed_at) }} UTC</p>
                            <p class="text-sm text-slate-700">Se reinicia: {{ date(quota.resets_at) }} UTC</p>
                        </template>
                        <p
                            v-else
                            class="text-sm text-slate-600"
                            >{{ quota.unavailable_reason === 'read_failed' ? 'No se pudo leer el contador local. Cuota y fecha no disponibles.' : 'Sin lectura remota guardada. Cuota y fecha no disponibles.' }}</p
                        >
                    </li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
