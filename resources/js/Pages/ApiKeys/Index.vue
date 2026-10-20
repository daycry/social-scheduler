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
            <h1 class="text-xl font-bold text-slate-900">API keys (MCP)</h1>
        </template>

        <div>
            <div class="max-w-4xl space-y-5">
                <div v-if="newKey" class="rounded-lg border border-amber-300 border-l-4 border-l-amber-500 bg-amber-50 p-4" role="alert">
                    <p class="font-medium text-amber-900">Copia ahora tu API key: no volverás a verla.</p>
                    <code class="mt-2 block break-all rounded bg-white p-2 text-sm" data-testid="new-key">{{ newKey }}</code>
                </div>

                <section class="card p-4 sm:p-8">
                    <h3 class="text-lg font-medium text-slate-900">Conectar un agente</h3>
                    <p class="mt-1 text-sm text-slate-600">
                        Servidor MCP por HTTP en <code class="rounded bg-slate-100 px-1">{{ endpoint }}</code>
                        con la cabecera <code class="rounded bg-slate-100 px-1">Authorization: Bearer &lt;tu key&gt;</code>.
                        El agente solo ve y modifica tus datos.
                    </p>
                </section>

                <section class="card p-4 sm:p-8">
                    <h3 class="text-lg font-medium text-slate-900">Nueva API key</h3>
                    <form class="mt-4 max-w-xl space-y-4" @submit.prevent="create">
                        <div>
                            <InputLabel for="name" value="Nombre (p. ej. Claude Desktop)" />
                            <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>
                        <fieldset>
                            <legend class="text-sm font-medium text-slate-700">Permisos</legend>
                            <label v-for="(label, ability) in abilities" :key="ability" class="mt-2 flex items-start gap-2 text-sm text-slate-700">
                                <input v-model="form.abilities" type="checkbox" :value="ability" class="mt-0.5 rounded border-slate-300" />
                                <span><code>{{ ability }}</code> - {{ label }}</span>
                            </label>
                            <InputError class="mt-2" :message="form.errors.abilities" />
                        </fieldset>
                        <div>
                            <InputLabel for="expires_at" value="Caduca el (opcional; por defecto 1 año)" />
                            <TextInput id="expires_at" v-model="form.expires_at" type="date" class="mt-1 block" />
                            <InputError class="mt-2" :message="form.errors.expires_at" />
                        </div>
                        <PrimaryButton :disabled="form.processing">Crear API key</PrimaryButton>
                    </form>
                </section>

                <section class="card p-4 sm:p-8">
                    <h3 class="text-lg font-medium text-slate-900">Tus API keys</h3>
                    <p v-if="keys.length === 0" class="mt-2 text-sm text-slate-600">Aún no tienes ninguna.</p>
                    <ul v-else class="mt-2 divide-y divide-slate-200">
                        <li v-for="key in keys" :key="key.id" class="flex items-center justify-between py-3">
                            <div>
                                <p class="font-medium text-slate-900">{{ key.name }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ (key.abilities || []).join(', ') }} · último uso: {{ fmt(key.last_used_at) }} · caduca: {{ fmt(key.expires_at) }}
                                </p>
                            </div>
                            <button type="button" class="text-sm font-medium text-red-600 hover:underline" @click="revoke(key)">Revocar</button>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
