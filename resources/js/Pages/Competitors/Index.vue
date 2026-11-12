<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import NetworkChip from '@/Components/NetworkChip.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Competidores (PA-08, T-25): seguidores y publicaciones públicas de otras cuentas en Instagram (Business Discovery), Bluesky y YouTube. */
const props = defineProps({
    brand: Object,
    network: { type: String, default: 'instagram' },
    networks: { type: Array, default: () => [] },
    has_account: Boolean,
    comparison: Object,
    max: Number,
    sorts: { type: Array, default: () => [] },
    selected: { type: Object, default: null },
    flash: Object,
});

const form = useForm({ brand_id: props.brand?.id ?? '', username: '' });
const submit = () => form.transform((d) => ({ ...d, network: props.network })).post(route('competitors.store'), { preserveScroll: true, onSuccess: () => form.reset('username') });

// Textos por red: el resto de la pantalla es común a todas.
const netLabel = computed(() => props.networks.find((n) => n.value === props.network)?.label ?? 'Instagram');
const usernameLabel = computed(
    () =>
        ({
            instagram: 'Usuario de Instagram (cuenta Business o Creator)',
            bluesky: 'Usuario de Bluesky (por ejemplo ana.bsky.social)',
            youtube: 'Usuario del canal de YouTube (@usuario) o su URL',
        })[props.network] ?? `Usuario de ${netLabel.value}`,
);
const usernamePlaceholder = computed(() => ({ instagram: '@usuario', bluesky: 'ana.bsky.social', youtube: '@canal' })[props.network] ?? '@usuario');
const followersLabel = computed(() => (props.network === 'youtube' ? 'Suscriptores' : 'Seguidores'));
const mediaLabel = computed(() => (props.network === 'youtube' ? 'Videos' : 'Publicaciones'));
const dataNote = computed(() =>
    props.network === 'youtube'
        ? 'Solo se leen datos públicos: suscriptores y número de videos, una vez al día. Cada lectura gasta cuota de la API de YouTube (con un tope diario propio para no quitársela a tus subidas).'
        : 'Solo se leen datos públicos: seguidores y número de publicaciones, una vez al día.',
);
const remove = (c) => {
    if (confirm(`¿Dejar de seguir a @${c.username}?`)) router.delete(route('competitors.destroy', c.id), { preserveScroll: true });
};

const range = useForm({ from: props.comparison?.range?.from ?? '', to: props.comparison?.range?.to ?? '', sort: props.comparison?.sort ?? 'followers' });
const sortLabels = computed(() => ({ followers: followersLabel.value, growth: 'Crecimiento', growth_pct: 'Crecimiento %', media: mediaLabel.value, avg_interactions: 'Interacciones medias' }));
const query = () => ({ brand_id: props.brand?.id, network: props.network, from: range.from, to: range.to, sort: range.sort });
const networkHref = (value) => route('competitors.index', { brand_id: props.brand?.id, network: value });
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
                <NetworkChip :network="network" />
            </div>
        </template>

        <div class="space-y-5">
            <p v-if="flash?.success" class="text-sm text-emerald-700" role="status">{{ flash.success }}</p>
            <nav v-if="brand && networks.length > 1" class="flex gap-2 overflow-x-auto pb-1 text-sm" aria-label="Red de los competidores" data-testid="competitor-networks">
                <Link
                    v-for="n in networks"
                    :key="n.value"
                    :href="networkHref(n.value)"
                    :aria-current="n.value === network ? 'page' : undefined"
                    :data-testid="`competitor-network-${n.value}`"
                    :class="['flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 font-medium', n.value === network ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200']"
                >
                    <NetworkIcon :network="n.value" size="sm" />{{ n.label }}
                </Link>
            </nav>
            <FeatureEmpty
                v-if="!brand || !has_account"
                :network="network"
                :title="brand ? `Conecta ${netLabel} para comparar competidores` : 'Crea una marca para comparar competidores'"
                :description="brand ? `«${brand.name}» necesita una cuenta de ${netLabel} conectada para comparar su crecimiento con el de otras cuentas.` : `Compara tu cuenta de ${netLabel} con otras cuentas y mide su crecimiento.`"
                :cards="[
                    { title: 'Comparativa de seguidores', text: 'Tu cuenta frente a la de tus competidores, en un vistazo.', kind: 'chart' },
                    { title: 'Crecimiento a 7 y 30 días', text: 'Quién gana seguidores y a qué ritmo.', kind: 'calendar' },
                    { title: 'Solo datos públicos', text: 'Una lectura al día, sin pedir acceso a sus cuentas.', kind: 'report' },
                ]"
            >
                <template #cta>
                    <Link v-if="brand" :href="route('brands.connections.index', brand.id)" class="btn-primary">Conectar {{ netLabel }}</Link>
                    <Link v-else :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link>
                </template>
            </FeatureEmpty>

            <template v-else>
                <form class="card space-y-3 p-5" @submit.prevent="submit">
                    <label class="block text-sm">
                        <span class="block text-xs font-medium text-slate-600">{{ usernameLabel }}</span>
                        <input v-model="form.username" type="text" maxlength="300" required :placeholder="usernamePlaceholder" class="field max-w-xs" data-testid="competitor-username" />
                    </label>
                    <InputError :message="form.errors.username || form.errors.brand_id" />
                    <PrimaryButton :disabled="form.processing">Seguir cuenta</PrimaryButton>
                    <p class="text-xs text-slate-600">Hasta {{ max }} cuentas por marca. {{ dataNote }}</p>
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
                        <caption class="sr-only">Comparativa de {{ followersLabel.toLowerCase() }} y {{ mediaLabel.toLowerCase() }}</caption>
                        <thead class="border-b text-xs text-slate-600">
                            <tr>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Pos.</th>
                                <th scope="col" class="px-5 py-3 font-medium">Cuenta</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">{{ followersLabel }}</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">En el rango</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">%</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">7 días</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">30 días</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">{{ mediaLabel }}</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Interacc. medias</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-if="rows.length === 0">
                                <td colspan="9" class="px-5 py-4 text-slate-600">Aún no sigues a ninguna cuenta de {{ netLabel }}.</td>
                            </tr>
                            <tr v-for="r in rows" :key="r.id" data-testid="competitor-row">
                                <td class="px-3 py-3 text-right tabular-nums" data-testid="rank">{{ r.rank ?? '—' }}</td>
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900">@{{ r.username }} <span v-if="r.own" class="text-xs font-normal text-indigo-600">(tú)</span></p>
                                    <div class="mt-1 h-1.5 w-40 rounded bg-slate-100" aria-hidden="true">
                                        <div class="h-1.5 rounded bg-indigo-500" :style="{ width: `${((r.followers ?? 0) / maxFollowers) * 100}%` }"></div>
                                    </div>
                                    <p v-if="r.error" class="mt-1 text-xs text-red-600">{{ r.error }}</p>
                                    <div v-if="!r.own" class="mt-2 flex flex-wrap gap-x-3 gap-y-1">
                                        <button type="button" class="text-xs font-medium text-indigo-700 underline" data-testid="show-posts" :aria-label="`Ver publicaciones de @${r.username}`" @click="showPosts(r)">Ver publicaciones</button>
                                        <button type="button" class="text-xs font-medium text-red-700 underline" :aria-label="`Dejar de seguir a @${r.username}`" @click="remove(r)">Dejar de seguir</button>
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ n(r.followers) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums" data-testid="range-growth">{{ delta(r.growth) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ pct(r.growth_pct) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ delta(r.growth_7d) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ delta(r.growth_30d) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ n(r.media) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ n(r.avg_interactions) }}</td>
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
                                <a v-if="p.permalink" :href="p.permalink" target="_blank" rel="noopener noreferrer" class="ms-1 underline">ver en {{ netLabel }}</a>
                            </p>
                        </li>
                    </ol>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
