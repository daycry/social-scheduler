<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Cuadrícula de Instagram (C-02): solo lectura. Arriba lo programado (la fecha más tardía primero) y debajo lo ya publicado,
 * en tres columnas, que es el orden en que quedará el perfil. Las miniaturas son URLs del CDN de Meta que caducan: no se guardan.
 */
const props = defineProps({
    brand: Object,
    timezone: String,
    accounts: Array,
    account: Object,
    items: Array,
    error: String,
    cached: Boolean,
});

const TYPES = { VIDEO: 'Vídeo', CAROUSEL_ALBUM: 'Carrusel' };

const fmt = computed(() => {
    try {
        return new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: props.timezone || 'UTC' });
    } catch {
        return new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    }
});
const when = (iso) => (iso ? fmt.value.format(new Date(iso)) : '');
const label = (i) => {
    const kind = TYPES[i.type] ? `${TYPES[i.type]}, ` : '';
    return i.kind === 'scheduled' ? `${kind}programada para el ${when(i.timestamp)}. Abrir en el compositor` : `${kind}publicada el ${when(i.timestamp)}. Abrir en Instagram (se abre en otra pestaña)`;
};

const pick = (e) => router.get(route('instagram.grid'), { brand_id: props.brand?.id, account_id: e.target.value });
const scheduledCount = computed(() => props.items.filter((i) => i.kind === 'scheduled').length);
</script>

<template>
    <Head title="Cuadrícula de Instagram" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900">Cuadrícula de Instagram</h1>
                <label v-if="accounts.length > 1" class="flex items-center gap-2 text-sm">
                    <span class="font-medium text-slate-700">Cuenta</span>
                    <select class="field" data-testid="grid-account" :value="account?.id" @change="pick">
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}{{ a.username ? ` (@${a.username})` : '' }}</option>
                    </select>
                </label>
            </div>
        </template>

        <div class="mx-auto max-w-xl space-y-4" data-testid="ig-grid-page">
            <div v-if="!account" class="card p-5 text-sm text-slate-700" data-testid="grid-empty">
                <p>{{ brand ? 'Esta marca no tiene ninguna cuenta de Instagram conectada.' : 'Aún no tienes marcas.' }}</p>
                <Link v-if="brand" :href="route('brands.connections.index', brand.id)" class="mt-2 inline-block font-medium text-indigo-700 underline">Conectar una cuenta</Link>
            </div>

            <template v-else>
                <p v-if="error" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="alert" data-testid="grid-error">
                    No se pudo leer lo publicado en Instagram: {{ error }}
                    <span v-if="scheduledCount"> Lo programado sí se muestra.</span>
                </p>

                <p class="text-xs text-slate-600" data-testid="grid-pinned-note">
                    Los posts fijados en el perfil no se reflejan aquí, y las miniaturas se recortan como en Instagram. Lo publicado se actualiza cada 10 minutos.
                </p>

                <p v-if="!error && items.length === 0" class="card p-5 text-sm text-slate-700" data-testid="grid-no-items">Esta cuenta aún no tiene publicaciones ni nada programado.</p>

                <ul v-else class="grid grid-cols-3 gap-1 sm:gap-2" data-testid="grid-tiles">
                    <li v-for="i in items" :key="i.id" class="relative aspect-[3/4] overflow-hidden rounded bg-slate-200" :data-kind="i.kind" data-testid="grid-tile">
                        <component
                            :is="i.kind === 'scheduled' ? Link : 'a'"
                            v-bind="i.kind === 'scheduled' ? { href: route('posts.edit', i.post_id) } : { href: i.permalink || '#', target: '_blank', rel: 'noopener noreferrer' }"
                            class="block h-full w-full focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 focus-visible:ring-offset-1"
                            :aria-label="label(i)"
                        >
                            <img v-if="i.image_url" :src="i.image_url" alt="" loading="lazy" referrerpolicy="no-referrer" class="h-full w-full object-cover" />
                            <video v-else-if="i.video_url" :src="i.video_url + '#t=0.1'" preload="metadata" muted playsinline aria-hidden="true" tabindex="-1" class="h-full w-full object-cover"><track kind="captions" srclang="es" label="Sin subtítulos" /></video>
                            <span v-else class="flex h-full w-full items-center justify-center p-1 text-center text-[11px] text-slate-600" aria-hidden="true">Sin vista previa</span>
                        </component>
                        <span v-if="i.kind === 'scheduled'" class="pointer-events-none absolute inset-x-0 top-0 truncate bg-indigo-800/90 px-1 py-0.5 text-[10px] font-semibold leading-tight text-white" aria-hidden="true">Programada · {{ when(i.timestamp) }}</span>
                        <span v-if="TYPES[i.type]" class="pointer-events-none absolute bottom-1 right-1 rounded bg-slate-900/80 px-1 py-0.5 text-[10px] font-medium leading-tight text-white" aria-hidden="true">{{ TYPES[i.type] }}</span>
                    </li>
                </ul>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
