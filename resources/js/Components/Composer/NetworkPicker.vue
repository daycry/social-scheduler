<script setup>
import NetworkIcon from '@/Components/NetworkIcon.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { Link } from '@inertiajs/vue3';
import { useComposerContext } from '@/Composables/useComposer';

const { brand, accounts, editable, targetFor, typesOf, typeInfo, toggleAccount } = useComposerContext();
</script>

<template>
    <section class="card p-4" aria-labelledby="picker-title">
        <h2 id="picker-title" tabindex="-1" class="mb-3 font-medium text-slate-900">Dónde publicar</h2>
        <p v-if="accounts.length === 0" class="text-sm text-slate-600">
            Esta marca no tiene cuentas conectadas.
            <Link :href="route('brands.connections.index', brand.id)" class="link">Conectar redes</Link>
        </p>

        <!-- Una pastilla por cuenta: botón de alternancia con aria-pressed -->
        <ul class="flex flex-wrap gap-2" aria-label="Cuentas de la marca">
            <li v-for="account in accounts" :key="account.id" class="max-w-full">
                <button
                    :id="`account-pill-${account.id}`"
                    type="button"
                    :aria-pressed="!!targetFor(account.id)"
                    :aria-label="`${account.network_label}: ${account.name}${account.status !== 'activa' ? '. Requiere reconexión' : ''}`"
                    :disabled="!editable"
                    class="inline-flex max-w-full items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-60"
                    :class="targetFor(account.id) ? 'border-indigo-600 bg-indigo-50 text-indigo-800' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'"
                    @click="toggleAccount(account, !targetFor(account.id))"
                >
                    <NetworkIcon :network="account.network" size="sm" />
                    <span class="truncate">{{ account.name }}</span>
                    <span v-if="targetFor(account.id)" aria-hidden="true">✓</span>
                    <span v-if="account.status !== 'activa'" class="rounded bg-amber-100 px-1.5 text-xs text-amber-800">Requiere reconexión</span>
                </button>
            </li>
        </ul>

        <!-- Tipo de contenido de cada cuenta activa -->
        <ul class="mt-3 space-y-2">
            <template v-for="account in accounts" :key="`type-${account.id}`">
                <li v-if="targetFor(account.id)" class="rounded border border-slate-200 p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <NetworkIcon :network="account.network" size="sm" />
                        <InputLabel :for="`type-${account.id}`" :value="`Tipo de contenido en ${account.network_label} (${account.name})`" />
                        <select
                            :id="`type-${account.id}`"
                            v-model="targetFor(account.id).content_type"
                            :disabled="!editable"
                            class="field w-auto max-w-full"
                        >
                            <option v-for="t in typesOf(account.network)" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </div>
                    <ul v-if="typeInfo(targetFor(account.id), account.network)?.limitations.length" class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-amber-700">
                        <li v-for="l in typeInfo(targetFor(account.id), account.network).limitations" :key="l">{{ l }}</li>
                    </ul>
                </li>
            </template>
        </ul>
    </section>
</template>
