<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
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
    activa: { label: 'Conectada', classes: 'bg-green-100 text-green-800' },
    requiere_reconexion: { label: 'Requiere reconexión', classes: 'bg-amber-100 text-amber-800' },
    desconectada: { label: 'Desconectada', classes: 'bg-gray-100 text-gray-600' },
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

const selection = useForm({ keys: props.pending ? props.pending.candidates.map((c) => c.key) : [] });
const confirmSelection = () => selection.post(route('brands.connections.select', props.brand.id));
</script>

<template>
    <Head :title="`Conexiones · ${brand.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Conexiones · {{ brand.name }}</h2>
                <Link :href="route('brands.show', brand.id)" class="text-sm text-indigo-600">Volver a la marca</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <p v-if="flash.success" class="rounded bg-green-50 p-3 text-sm text-green-800" role="status">{{ flash.success }}</p>
                <p v-if="flash.error" class="rounded bg-red-50 p-3 text-sm text-red-800" role="alert">{{ flash.error }}</p>

                <!-- Elección de cuentas tras un OAuth que devolvió varias (p. ej. Páginas de Meta) -->
                <section v-if="pending" class="space-y-3 rounded-lg border border-indigo-200 bg-white p-6 shadow-sm">
                    <h3 class="font-medium text-gray-900">Elige qué cuentas conectar</h3>
                    <label v-for="c in pending.candidates" :key="c.key" class="flex items-center gap-3 text-sm text-gray-700">
                        <input v-model="selection.keys" type="checkbox" :value="c.key" class="rounded border-gray-300" />
                        <span class="font-medium">{{ c.network_label }}</span>
                        <span>{{ c.name }}</span>
                        <span v-if="c.username" class="text-gray-500">@{{ c.username }}</span>
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
                    class="rounded-lg bg-white p-6 shadow-sm"
                    :data-network="n.network"
                >
                    <div class="flex items-center justify-between">
                        <h3 class="font-medium text-gray-900">{{ n.label }}</h3>
                        <SecondaryButton v-if="n.available" type="button" @click="connect(n.provider)">+ Conectar</SecondaryButton>
                        <span v-else class="text-xs text-gray-400">No disponible</span>
                    </div>

                    <p v-if="n.accounts.length === 0" class="mt-3 text-sm text-gray-500">Sin cuentas conectadas.</p>

                    <ul v-else class="mt-3 divide-y divide-gray-100">
                        <li v-for="a in n.accounts" :key="a.id" class="flex items-center justify-between py-3" :data-account="a.id">
                            <div>
                                <p class="text-sm font-medium text-gray-900">
                                    {{ a.name }}
                                    <span v-if="a.username" class="font-normal text-gray-500">@{{ a.username }}</span>
                                </p>
                                <span class="mt-1 inline-block rounded px-2 py-0.5 text-xs" :class="STATUS[a.status].classes">
                                    {{ STATUS[a.status].label }}
                                </span>
                                <p v-if="a.status === 'requiere_reconexion'" class="mt-1 text-xs text-amber-700">
                                    Las publicaciones programadas se conservan, pero no se enviarán hasta que reconectes.
                                    <span v-if="a.last_error">({{ a.last_error }})</span>
                                </p>
                            </div>
                            <div class="flex items-center gap-3 text-sm">
                                <button
                                    v-if="a.status !== 'activa'"
                                    type="button"
                                    class="text-indigo-600"
                                    @click="connect(a.provider, a.provider === 'meta')"
                                >
                                    {{ a.status === 'requiere_reconexion' ? 'Reconectar' : 'Conectar de nuevo' }}
                                </button>
                                <button v-if="a.status !== 'desconectada'" type="button" class="text-red-600" @click="disconnect(a)">
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
