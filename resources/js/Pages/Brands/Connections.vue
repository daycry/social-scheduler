<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    brand: Object,
    accounts: Array,
    providers: Array,
    pending: Object,
    flash: Object,
});

const STATUS = {
    activa: { label: 'Conectada', classes: 'bg-emerald-100 text-emerald-800' },
    requiere_reconexion: { label: 'Requiere reconexión', classes: 'bg-amber-100 text-amber-800' },
    desconectada: { label: 'Desconectada', classes: 'bg-slate-100 text-slate-600' },
};

// Una tarjeta por red (como la barra lateral de Analytics en ui-reference.md): cuentas conectadas o «+».
const networks = computed(() =>
    props.providers.map((p) => ({
        ...p,
        accounts: props.accounts.filter((a) => a.network === p.network),
    })),
);

// El OAuth se inicia por POST (CSRF); el servidor responde con Inertia::location hacia la red externa.
const connect = (provider, rerequest = false) =>
    router.post(route('brands.connections.connect', { brand: props.brand.id, provider }), rerequest ? { rerequest: 1 } : {});

const disconnect = (account) => {
    if (confirm(`¿Desconectar ${account.network_label} «${account.name}»? Se eliminarán sus credenciales.`)) {
        router.delete(route('brands.connections.destroy', { brand: props.brand.id, account: account.id }));
    }
};

const toggleDirectPost = (account, enabled) =>
    router.patch(route('brands.connections.direct-post', { brand: props.brand.id, account: account.id }), { enabled }, { preserveScroll: true });

// Sin preselección: una cuenta de Meta puede gestionar muchas páginas/perfiles y solo se conecta lo que se marque.
const selection = useForm({ keys: [] });
const confirmSelection = () => selection.post(route('brands.connections.select', props.brand.id));
</script>

<template>
    <Head :title="`Conexiones · ${brand.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900">Conexiones · {{ brand.name }}</h1>
                <Link :href="route('brands.show', brand.id)" class="link text-sm">Volver a la marca</Link>
            </div>
        </template>

        <div>
            <div class="max-w-4xl space-y-5">
                <p v-if="flash.success" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ flash.success }}</p>
                <p v-if="flash.error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ flash.error }}</p>

                <!-- Elección de cuentas tras un OAuth que devolvió varias (p. ej. Páginas de Meta) -->
                <section v-if="pending" class="card space-y-3 border-indigo-300 p-6">
                    <h2 class="font-medium text-slate-900">Elige qué cuentas conectar</h2>
                    <p class="text-sm text-slate-600">Marca solo las que pertenezcan a esta marca; el resto no se conecta.</p>
                    <label v-for="c in pending.candidates" :key="c.key" class="flex items-center gap-3 text-sm text-slate-700">
                        <input v-model="selection.keys" type="checkbox" :value="c.key" class="rounded border-slate-300" />
                        <span class="font-medium">{{ c.network_label }}</span>
                        <span>{{ c.name }}</span>
                        <span v-if="c.username" class="text-slate-500">@{{ c.username }}</span>
                        <span v-if="c.missing_scopes && c.missing_scopes.length" class="text-xs text-amber-700">
                            Faltan permisos para publicar: {{ c.missing_scopes.join(', ') }}
                        </span>
                    </label>
                    <PrimaryButton :disabled="selection.processing || selection.keys.length === 0" @click="confirmSelection">
                        Conectar seleccionadas
                    </PrimaryButton>
                </section>

                <section
                    v-for="n in networks"
                    :key="n.network"
                    class="card p-6"
                    :data-network="n.network"
                >
                    <div class="flex items-center justify-between">
                        <h2 class="flex items-center gap-2.5 font-semibold text-slate-900"><NetworkIcon :network="n.network" />{{ n.label }}</h2>
                        <SecondaryButton v-if="n.available" type="button" @click="connect(n.provider)">+ Conectar</SecondaryButton>
                        <span v-else class="text-xs text-slate-500">No disponible</span>
                    </div>

                    <p v-if="n.accounts.length === 0" class="mt-3 text-sm text-slate-500">Sin cuentas conectadas.</p>

                    <ul v-else class="mt-3 divide-y divide-slate-100">
                        <li v-for="a in n.accounts" :key="a.id" class="flex items-center justify-between py-3" :data-account="a.id">
                            <div>
                                <p class="text-sm font-medium text-slate-900">
                                    {{ a.name }}
                                    <span v-if="a.username" class="font-normal text-slate-500">@{{ a.username }}</span>
                                </p>
                                <span class="mt-1 inline-block"><StatusBadge :status="a.status" :label="STATUS[a.status].label" /></span>
                                <label v-if="a.network === 'tiktok'" class="mt-2 flex items-start gap-2 text-xs text-slate-700">
                                    <input type="checkbox" class="mt-0.5 rounded border-slate-300" :checked="a.direct_post" @change="toggleDirectPost(a, $event.target.checked)" />
                                    <span>
                                        Publicar directamente (Direct Post)
                                        <span class="block text-slate-500">Requiere auditoría aprobada de TikTok. Desactivado: los vídeos se envían como borrador a tu bandeja y los terminas de publicar en la app.</span>
                                    </span>
                                </label>
                                <p v-if="a.status === 'requiere_reconexion'" class="mt-1 text-xs text-amber-700">
                                    Las publicaciones programadas se conservan, pero no se enviarán hasta que reconectes.
                                    <span v-if="a.last_error">({{ a.last_error }})</span>
                                </p>
                            </div>
                            <div class="flex items-center gap-3 text-sm">
                                <button
                                    v-if="a.status !== 'activa'"
                                    type="button"
                                    class="link"
                                    @click="connect(a.provider, a.provider === 'meta')"
                                >
                                    {{ a.status === 'requiere_reconexion' ? 'Reconectar' : 'Conectar de nuevo' }}
                                </button>
                                <button v-if="a.status !== 'desconectada'" type="button" class="font-medium text-red-600 hover:underline" @click="disconnect(a)">
                                    Desconectar
                                </button>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
