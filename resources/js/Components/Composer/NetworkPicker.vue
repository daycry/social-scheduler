<script setup>
import NetworkIcon from '@/Components/NetworkIcon.vue';
import ContentTypeSelect from '@/Components/Composer/ContentTypeSelect.vue';
import { Link } from '@inertiajs/vue3';
import { useComposerContext } from '@/Composables/useComposer';

const { brand, accounts, editable, targetFor, typesOf, typeLabel, typeInfo, toggleAccount } = useComposerContext();
</script>

<template>
    <section class="card p-4" aria-labelledby="picker-title">
        <h2 id="picker-title" tabindex="-1" class="mb-3 font-medium text-slate-900">Dónde publicar</h2>
        <p v-if="accounts.length === 0" class="text-sm text-slate-600">
            Esta marca no tiene cuentas conectadas.
            <Link :href="route('brands.connections.index', brand.id)" class="link">Conectar redes</Link>
        </p>

        <!-- Una pastilla por cuenta (botón de alternancia con aria-pressed) y, junto a cada cuenta activa, su tipo de publicación -->
        <ul class="flex flex-wrap gap-2" aria-label="Cuentas de la marca">
            <li v-for="account in accounts" :key="account.id" class="relative flex max-w-full flex-wrap items-center gap-1.5">
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
                <ContentTypeSelect
                    v-if="targetFor(account.id)"
                    v-model="targetFor(account.id).content_type"
                    :button-id="`type-${account.id}`"
                    :types="typesOf(account.network)"
                    :saved-label="typeLabel(targetFor(account.id).content_type)"
                    :label="`Tipo de contenido en ${account.network_label} (${account.name})`"
                    :disabled="!editable"
                />
            </li>
        </ul>

        <!-- Limitaciones del tipo elegido en cada cuenta activa -->
        <ul class="mt-3 space-y-1">
            <template v-for="account in accounts" :key="`lim-${account.id}`">
                <li v-if="targetFor(account.id) && typeInfo(targetFor(account.id), account.network)?.limitations.length" class="flex items-start gap-2 text-xs text-amber-700">
                    <NetworkIcon :network="account.network" size="sm" />
                    <ul class="list-disc space-y-0.5 pl-4">
                        <li v-for="l in typeInfo(targetFor(account.id), account.network).limitations" :key="l">{{ l }}</li>
                    </ul>
                </li>
            </template>
        </ul>
    </section>
</template>
