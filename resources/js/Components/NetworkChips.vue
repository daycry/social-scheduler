<script setup>
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

/** @typedef {{ label: string, href: string }} NetworkLink */
/** @typedef {NetworkLink & { value: string, connected?: boolean, connectHref?: string | null, testid?: string }} NetworkChip */

/**
 * Chips de red: una fila horizontal de enlaces con el icono y el nombre de cada red (Resumen + redes del Dashboard; redes de
 * Competidores). `items`: `{ value, label, href, connected?, connectHref?, testid? }`; una red sin conectar (`connected: false`) lleva a
 * `connectHref` («+ Conectar»). `summary` añade el primer chip («Resumen»). Con flechas, Inicio y Fin se mueve el foco entre los enlaces.
 * Los estilos son los de la barra de redes del Dashboard: ninguna pantalla repite este patrón.
 */
const props = defineProps({
    items: { type: /** @type {import('vue').PropType<NetworkChip[]>} */ (Array), required: true },
    active: { type: /** @type {import('vue').PropType<string | null>} */ (String), default: null },
    summary: { type: /** @type {import('vue').PropType<NetworkLink | null>} */ (Object), default: null },
    label: { type: String, default: 'Redes' },
});

const nav = ref(/** @type {HTMLElement | null} */ (null));

/** @param {KeyboardEvent} e */
const onKey = (e) => {
    if (!nav.value) return;
    const links = [...nav.value.querySelectorAll('a')];
    const i = links.findIndex((link) => link === document.activeElement);
    let to = null;
    if (e.key === 'ArrowRight') to = links[(i + 1) % links.length];
    else if (e.key === 'ArrowLeft') to = links[(i - 1 + links.length) % links.length];
    else if (e.key === 'Home') to = links[0];
    else if (e.key === 'End') to = links[links.length - 1];
    if (!to) return;
    e.preventDefault();
    to.focus();
};

const on = 'bg-accent-fill text-on-accent';
const off = 'bg-surface text-foreground-secondary ring-1 ring-border';
</script>

<template>
    <nav ref="nav" class="flex gap-2 overflow-x-auto pb-1 text-sm" :aria-label="label" @keydown="onKey">
        <Link
            v-if="summary"
            :href="summary.href"
            :aria-current="active === 'summary' ? 'page' : undefined"
            :class="['shrink-0 rounded-full px-3 py-1.5 font-medium', active === 'summary' ? on : off]"
        >
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
                class="flex shrink-0 items-center gap-1.5 rounded-full bg-surface px-3 py-1.5 font-medium text-foreground-muted ring-1 ring-dashed ring-control-border"
            >
                <NetworkIcon :network="n.value" size="sm" />{{ n.label }} <span aria-hidden="true">+</span>
            </Link>
        </template>
    </nav>
</template>
