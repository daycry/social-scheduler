<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    keys: Array,
    abilities: Object,
    endpoint: String,
    newKey: { type: String, default: null },
});

const form = useForm({
    name: '',
    abilities: Object.keys(props.abilities),
    expires_at: '',
});

const create = () => form.post(route('api-keys.store'), {
    preserveScroll: true,
    onSuccess: () => form.reset('name', 'expires_at'),
});

const revoke = (key) => {
    if (confirm(`Revocar la API key "${key.name}"? El agente que la use dejara de funcionar.`)) {
        router.delete(route('api-keys.destroy', key.id), { preserveScroll: true });
    }
};

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : 'nunca');
</script>

<template>
    <Head title="API keys" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">API keys (MCP)</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="newKey" class="border-l-4 border-amber-500 bg-amber-50 p-4 sm:rounded-lg" role="alert">
                    <p class="font-medium text-amber-900">Copia ahora tu API key: no volveras a verla.</p>
                    <code class="mt-2 block break-all rounded bg-white p-2 text-sm" data-testid="new-key">{{ newKey }}</code>
                </div>

                <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <h3 class="text-lg font-medium text-gray-900">Conectar un agente</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Servidor MCP por HTTP en <code class="rounded bg-gray-100 px-1">{{ endpoint }}</code>
                        con la cabecera <code class="rounded bg-gray-100 px-1">Authorization: Bearer &lt;tu key&gt;</code>.
                        El agente solo ve y modifica tus datos.
                    </p>
                </section>

                <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <h3 class="text-lg font-medium text-gray-900">Nueva API key</h3>
                    <form class="mt-4 max-w-xl space-y-4" @submit.prevent="create">
                        <div>
                            <InputLabel for="name" value="Nombre (p. ej. Claude Desktop)" />
                            <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>
                        <fieldset>
                            <legend class="text-sm font-medium text-gray-700">Permisos</legend>
                            <label v-for="(label, ability) in abilities" :key="ability" class="mt-2 flex items-start gap-2 text-sm text-gray-700">
                                <input v-model="form.abilities" type="checkbox" :value="ability" class="mt-0.5 rounded border-gray-300" />
                                <span><code>{{ ability }}</code> - {{ label }}</span>
                            </label>
                            <InputError class="mt-2" :message="form.errors.abilities" />
                        </fieldset>
                        <div>
                            <InputLabel for="expires_at" value="Caduca el (opcional; por defecto 1 ano)" />
                            <TextInput id="expires_at" v-model="form.expires_at" type="date" class="mt-1 block" />
                            <InputError class="mt-2" :message="form.errors.expires_at" />
                        </div>
                        <PrimaryButton :disabled="form.processing">Crear API key</PrimaryButton>
                    </form>
                </section>

                <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <h3 class="text-lg font-medium text-gray-900">Tus API keys</h3>
                    <p v-if="keys.length === 0" class="mt-2 text-sm text-gray-600">Aun no tienes ninguna.</p>
                    <ul v-else class="mt-2 divide-y divide-gray-200">
                        <li v-for="key in keys" :key="key.id" class="flex items-center justify-between py-3">
                            <div>
                                <p class="font-medium text-gray-900">{{ key.name }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ (key.abilities || []).join(', ') }} · ultimo uso: {{ fmt(key.last_used_at) }} · caduca: {{ fmt(key.expires_at) }}
                                </p>
                            </div>
                            <button type="button" class="text-sm text-red-600" @click="revoke(key)">Revocar</button>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
