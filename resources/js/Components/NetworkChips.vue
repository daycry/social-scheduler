<script setup>
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Chips de red: una fila horizontal de enlaces con el icono y el nombre de cada red (Resumen + redes del Dashboard; redes de
 * Competidores). `items`: `{ value, label, href, connected?, connectHref?, testid? }`; una red sin conectar (`connected: false`) lleva a
 * `connectHref` («+ Conectar»). `summary` añade el primer chip («Resumen»). Con flechas, Inicio y Fin se mueve el foco entre los enlaces.
 * Los estilos son los de la barra de redes del Dashboard: ninguna pantalla repite este patrón.
 */
const props = defineProps({
    items: { type: Array, required: true },
    active: { type: String, default: null },
    summary: { type: Object, default: null },
    label: { type: String, default: 'Redes' },
});

const nav = ref(null);

const onKey = (e) => {
    const links = [...nav.value.querySelectorAll('a')];
    const i = links.indexOf(document.activeElement);
    let to = null;
    if (e.key === 'ArrowRight') to = links[(i + 1) % links.length];
    else if (e.key === 'ArrowLeft') to = links[(i - 1 + links.length) % links.length];
    else if (e.key === 'Home') to = links[0];
    else if (e.key === 'End') to = links[links.length - 1];
    if (!to) return;
    e.preventDefault();
    to.focus();
};

const on = 'bg-indigo-600 text-white';
const off = 'bg-white text-slate-700 ring-1 ring-slate-200';
</script>

<template>
    <nav ref="nav" class="flex gap-2 overflow-x-auto pb-1 text-sm" :aria-label="label" @keydown="onKey">
        <Link v-if="summary" :href="summary.href" :aria-current="active === 'summary' ? 'page' : undefined" :class="['shrink-0 rounded-full px-3 py-1.5 font-medium', active === 'summary' ? on : off]">
            {{ summary.label }}
        </Link>
        <template v-for="n in items" :key="n.value">
            <Link
                v-if="n.connected !== false"
                :href="n.href"
                :aria-current="active === n.value ? 'page' : undefined"
                :data-testid="n.testid"
                :class="['flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 font-medium', active === n.value ? on : off]"
            >
                <NetworkIcon :network="n.value" size="sm" />{{ n.label }}
            </Link>
            <Link
                v-else-if="n.connectHref"
                :href="n.connectHref"
                :aria-label="`Conectar ${n.label}`"
                class="flex shrink-0 items-center gap-1.5 rounded-full bg-white px-3 py-1.5 font-medium text-slate-600 ring-1 ring-dashed ring-slate-300"
            >
                <NetworkIcon :network="n.value" size="sm" />{{ n.label }} <span aria-hidden="true">+</span>
            </Link>
        </template>
    </nav>
</template>
