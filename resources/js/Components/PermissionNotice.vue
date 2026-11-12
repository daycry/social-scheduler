<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * Aviso único de «falta un permiso» (T-22), común al inbox y a Meta Ads: tarjeta ámbar con el motivo, los pasos (texto de
 * `App\Support\PermissionHelp`) y el enlace a Conexiones. `items`: [{ id, name, help, brand_id }].
 */
defineProps({
    title: { type: String, required: true },
    items: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="card border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert" data-testid="permission-notice">
        <p class="font-medium">{{ title }}</p>
        <ul class="mt-1 list-disc space-y-1 pl-5" data-testid="permission-notice-items">
            <li v-for="i in items" :key="i.id">
                <strong v-if="i.name">{{ i.name }}:</strong> {{ i.help }}
                <Link v-if="i.brand_id" :href="route('brands.connections.index', i.brand_id)" class="link ml-1">Ir a Conexiones</Link>
            </li>
        </ul>
    </div>
</template>
