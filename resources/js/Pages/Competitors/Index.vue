<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import NetworkChip from '@/Components/NetworkChip.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Competidores de Instagram (PA-08): seguidores y publicaciones de otras cuentas profesionales (Business Discovery). */
const props = defineProps({
    brand: Object,
    has_instagram: Boolean,
    comparison: Object,
    max: Number,
    sorts: { type: Array, default: () => [] },
    selected: { type: Object, default: null },
    flash: Object,
});

const form = useForm({ brand_id: props.brand?.id ?? '', username: '' });
const submit = () => form.post(route('competitors.store'), { preserveScroll: true, onSuccess: () => form.reset('username') });
const remove = (c) => {
    if (confirm(`¿Dejar de seguir a @${c.username}?`)) router.delete(route('competitors.destroy', c.id), { preserveScroll: true });
};

const range = useForm({ from: props.comparison?.range?.from ?? '', to: props.comparison?.range?.to ?? '', sort: props.comparison?.sort ?? 'followers' });
const sortLabels = { followers: 'Seguidores', growth: 'Crecimiento', growth_pct: 'Crecimiento %', media: 'Publicaciones', avg_interactions: 'Interacciones medias' };
const query = () => ({ brand_id: props.brand?.id, from: range.from, to: range.to, sort: range.sort });
const apply = () => router.get(route('competitors.index'), query(), { preserveScroll: true });
const exportUrl = computed(() => route('competitors.export', query()));
const showPosts = (c) => router.get(route('competitors.index'), { ...query(), competitor: c.id }, { preserveScroll: true });
const refreshPosts = (c) => router.post(route('competitors.posts.refresh', c.id), {}, { preserveScroll: true });
const fmtDate = (iso) => (iso ? new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium' }).format(new Date(iso)) : '');

const rows = computed(() => {
    const own = props.comparison?.own ? [{ ...props.comparison.own, own: true, id: 'own' }] : [];
    return [...own, ...(props.comparison?.competitors ?? [])];
});

const n = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('es-ES').format(v));
const pct = (v) => (v === null || v === undefined ? '—' : `${v > 0 ? '+' : ''}${new Intl.NumberFormat('es-ES', { maximumFractionDigits: 2 }).format(v)} %`);
const delta = (v) => (v === null || v === undefined ? '—' : `${v > 0 ? '+' : ''}${new Intl.NumberFormat('es-ES').format(v)}`);
const maxFollowers = computed(() => Math.max(1, ...rows.value.map((r) => r.followers ?? 0)));
</script>

<template>
    <Head title="Competidores" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-bold text-slate-900">Competidores</h1>
                <NetworkChip network="instagram" />
            </div>
        </template>

        <div class="space-y-5">
            <p v-if="flash?.success" class="text-sm text-emerald-700" role="status">{{ flash.success }}</p>
            <FeatureEmpty
                v-if="!brand || !has_instagram"
                network="instagram"
                :title="brand ? 'Conecta Instagram para comparar competidores' : 'Crea una marca para comparar competidores'"
                :description="brand ? `«${brand.name}» necesita una cuenta de Instagram profesional conectada para comparar su crecimiento con el de otras cuentas.` : 'Compara tu cuenta de Instagram con otras cuentas profesionales y mide su crecimiento.'"
                :cards="[
                    { title: 'Comparativa de seguidores', text: 'Tu cuenta frente a la de tus competidores, en un vistazo.', kind: 'chart' },
                    { title: 'Crecimiento a 7 y 30 días', text: 'Quién gana seguidores y a qué ritmo.', kind: 'calendar' },
                    { title: 'Solo datos públicos', text: 'Una lectura al día, sin pedir acceso a sus cuentas.', kind: 'report' },
                ]"
            >
                <template #cta>
                    <Link v-if="brand" :href="route('brands.connections.index', brand.id)" class="btn-primary">Conectar Instagram</Link>
                    <Link v-else :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link>
                </template>
            </FeatureEmpty>

            <template v-else>
                <form class="card space-y-3 p-5" @submit.prevent="submit">
                    <label class="block text-sm">
                        <span class="block text-xs font-medium text-slate-600">Usuario de Instagram (cuenta Business o Creator)</span>
                        <input v-model="form.username" type="text" maxlength="31" required placeholder="@usuario" class="field max-w-xs" />
                    </label>
                    <InputError :message="form.errors.username || form.errors.brand_id" />
                    <PrimaryButton :disabled="form.processing">Seguir cuenta</PrimaryButton>
                    <p class="text-xs text-slate-600">Hasta {{ max }} cuentas por marca. Solo se leen datos públicos: seguidores y número de publicaciones, una vez al día.</p>
                </form>

                <form class="card flex flex-wrap items-end gap-3 p-4 text-sm" data-testid="range-form" @submit.prevent="apply">
                    <label class="block">
                        <span class="block text-xs font-medium text-slate-600">Desde</span>
                        <input v-model="range.from" type="date" class="field" data-testid="range-from" />
                    </label>
                    <label class="block">
                        <span class="block text-xs font-medium text-slate-600">Hasta</span>
                        <input v-model="range.to" type="date" class="field" data-testid="range-to" />
                    </label>
                    <label class="block">
                        <span class="block text-xs font-medium text-slate-600">Ordenar por</span>
                        <select v-model="range.sort" class="field" data-testid="range-sort">
                            <option v-for="k in sorts" :key="k" :value="k">{{ sortLabels[k] }}</option>
                        </select>
                    </label>
                    <button type="submit" class="btn btn-secondary" data-testid="range-apply">Aplicar</button>
                    <a :href="exportUrl" class="btn btn-secondary" data-testid="export-csv" download>Exportar CSV</a>
                    <InputError :message="$page.props.errors?.from || $page.props.errors?.to" />
                </form>

                <section class="card overflow-x-auto" tabindex="0" role="region" aria-label="Comparativa de competidores">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Comparativa de seguidores y publicaciones</caption>
                        <thead class="border-b text-xs text-slate-600">
                            <tr>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Pos.</th>
                                <th scope="col" class="px-5 py-3 font-medium">Cuenta</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Seguidores</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">En el rango</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">%</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">7 días</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">30 días</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Publicaciones</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Interacc. medias</th>
                                <th scope="col" class="px-3 py-3"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-if="rows.length === 0">
                                <td colspan="10" class="px-5 py-4 text-slate-600">Aún no sigues a ninguna cuenta.</td>
                            </tr>
                            <tr v-for="r in rows" :key="r.id" data-testid="competitor-row">
                                <td class="px-3 py-3 text-right tabular-nums" data-testid="rank">{{ r.rank ?? '—' }}</td>
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900">@{{ r.username }} <span v-if="r.own" class="text-xs font-normal text-indigo-600">(tú)</span></p>
                                    <div class="mt-1 h-1.5 w-40 rounded bg-slate-100" aria-hidden="true">
                                        <div class="h-1.5 rounded bg-indigo-500" :style="{ width: `${((r.followers ?? 0) / maxFollowers) * 100}%` }"></div>
                                    </div>
                                    <p v-if="r.error" class="mt-1 text-xs text-red-600">{{ r.error }}</p>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ n(r.followers) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums" data-testid="range-growth">{{ delta(r.growth) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ pct(r.growth_pct) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ delta(r.growth_7d) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ delta(r.growth_30d) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ n(r.media) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ n(r.avg_interactions) }}</td>
                                <td class="space-x-3 whitespace-nowrap px-3 py-3 text-right">
                                    <button v-if="!r.own" type="button" class="text-xs font-medium text-indigo-700 underline" data-testid="show-posts" @click="showPosts(r)">Ver publicaciones</button>
                                    <button v-if="!r.own" type="button" class="text-xs font-medium text-red-700 underline" @click="remove(r)">Dejar de seguir</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section v-if="selected" class="card space-y-3 p-5 text-sm" data-testid="top-posts" aria-label="Publicaciones destacadas">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-slate-900">Publicaciones destacadas de @{{ selected.username }}</h2>
                        <button type="button" class="btn btn-secondary" data-testid="refresh-posts" @click="refreshPosts({ id: selected.id })">Actualizar publicaciones</button>
                    </div>
                    <p class="text-xs text-slate-600">Ordenadas por interacciones visibles (me gusta + comentarios) dentro del rango. Si la cuenta oculta sus me gusta, se muestra «—».</p>
                    <p v-if="selected.posts.length === 0" class="text-slate-600">No hay publicaciones recogidas en este rango. Pulsa «Actualizar publicaciones» para leer las últimas.</p>
                    <ol v-else class="space-y-2">
                        <li v-for="p in selected.posts" :key="p.id" class="rounded border border-slate-200 p-3" data-testid="top-post">
                            <p class="whitespace-pre-line break-words text-slate-800">{{ p.caption || 'Sin texto' }}</p>
                            <p class="mt-1 text-xs text-slate-600">
                                {{ fmtDate(p.posted_at) }} · {{ n(p.likes) }} me gusta · {{ n(p.comments) }} comentarios · {{ n(p.interactions) }} interacciones
                                <a v-if="p.permalink" :href="p.permalink" target="_blank" rel="noopener noreferrer" class="ms-1 underline">ver en Instagram</a>
                            </p>
                        </li>
                    </ol>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
