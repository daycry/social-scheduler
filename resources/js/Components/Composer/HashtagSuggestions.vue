<script setup>
import { computed, nextTick, ref } from 'vue';
import { useComposerContext } from '@/Composables/useComposer';

/**
 * Menú «Hashtags» (C-04a) de la barra del editor: sugerencias del HISTÓRICO PROPIO de la marca (sin IA), ordenadas por la mejora de
 * interacciones sobre su media. Cada chip inserta `#tag` en el cursor del campo activo. Si el texto supera el máximo de hashtags de
 * alguna red elegida (Instagram 30, …) se avisa; el contador por red del compositor ya lo marca en rojo.
 */
const emit = defineEmits(['insert']);
const { brand, form, accounts, limits, hashtagsOf, insertIntoActive } = useComposerContext();

const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const result = ref(null);
const button = ref(null);
const root = ref(null);

const accountOf = (id) => accounts.value.find((a) => a.id === id);

// Máximo de hashtags por destino elegido (los tipos sin tope no aparecen).
const maxima = computed(() =>
    form.targets
        .map((t) => ({ account: accountOf(t.account_id), target: t, max: limits.value?.[t.content_type]?.max_hashtags ?? null }))
        .filter((r) => r.max != null),
);
const exceeded = computed(() => maxima.value.filter((r) => hashtagsOf(r.target) > r.max));
const label = (r) => `${r.account?.network_label ?? r.account?.name ?? 'Esta red'}`;

const load = async () => {
    loading.value = true;
    failed.value = false;
    try {
        const res = await fetch(route('brands.hashtags', brand.value.id), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error(String(res.status));
        result.value = await res.json();
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
};

const close = () => {
    open.value = false;
    nextTick(() => button.value?.focus());
};
const toggle = async () => {
    open.value = !open.value;
    if (!open.value) return;
    await load();
    await nextTick();
    root.value?.querySelector('button')?.focus();
};
const pick = (tag) => {
    const text = `#${tag.tag}`;
    if (!insertIntoActive(text, { pad: true })) emit('insert', text, { pad: true });
};
const onKeydown = (e) => {
    if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        close();
    }
};
const onTab = (e) => {
    const focusable = [...root.value.querySelectorAll('button')];
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
};
const lift = (t) => (t.lift_pct == null ? null : `${t.lift_pct > 0 ? '+' : ''}${String(t.lift_pct).replace('.', ',')} %`);
</script>

<template>
    <!-- En móvil el menú se ancla a la barra del editor (ancho completo); desde `sm` cuelga del botón. -->
    <div class="sm:relative">
        <button
            ref="button"
            type="button"
            class="btn btn-secondary"
            aria-haspopup="dialog"
            :aria-expanded="open"
            data-testid="hashtags-open"
            @click="toggle"
        ><span aria-hidden="true"># </span>Hashtags</button>

        <div
            v-if="open"
            ref="root"
            role="dialog"
            aria-label="Hashtags sugeridos"
            class="absolute inset-x-0 top-full z-30 mt-1 rounded-lg border border-slate-200 bg-white p-3 shadow-lg sm:inset-x-auto sm:left-0 sm:w-96"
            data-testid="hashtags-menu"
            @keydown="onKeydown"
            @keydown.tab="onTab"
        >
            <p class="text-xs text-slate-600">De tu propio histórico (sin IA): los que has usado al menos {{ result?.min_uses ?? 2 }} veces, de mejor a peor rendimiento.</p>

            <p v-if="loading" class="mt-2 text-sm text-slate-600" role="status">Cargando…</p>
            <p v-else-if="failed" class="mt-2 text-sm text-rose-700" role="alert">No se pudieron cargar las sugerencias.</p>
            <p v-else-if="result && !result.available" class="mt-2 text-sm text-slate-600" data-testid="hashtags-empty">{{ result.reason }}</p>
            <ul v-else-if="result" class="mt-2 flex max-h-60 flex-wrap gap-1.5 overflow-y-auto" aria-label="Hashtags">
                <li v-for="t in result.tags" :key="t.tag">
                    <button
                        type="button"
                        class="rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-sm font-medium text-indigo-800 hover:bg-indigo-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                        data-testid="hashtag-chip"
                        :title="`${t.uses} usos · ${Math.round(t.avg_interactions)} interacciones de media`"
                        @click="pick(t)"
                    >#{{ t.tag }}<span class="ms-1.5 text-xs font-normal text-indigo-900/80">{{ t.uses }}×<template v-if="lift(t)"> · {{ lift(t) }}</template></span></button>
                </li>
            </ul>

            <p v-if="maxima.length" class="mt-2 text-xs text-slate-600" data-testid="hashtags-max">
                Máximo por red: <template v-for="(r, i) in maxima" :key="r.target.account_id + r.target.content_type"><template v-if="i">, </template>{{ label(r) }} {{ r.max }}</template>.
            </p>
            <p v-if="exceeded.length" class="mt-1 text-xs font-medium text-rose-700" role="status" data-testid="hashtags-over">
                Supera el máximo de hashtags en <template v-for="(r, i) in exceeded" :key="r.target.account_id + r.target.content_type"><template v-if="i">, </template>{{ label(r) }} ({{ hashtagsOf(r.target) }} de {{ r.max }})</template>.
            </p>

            <p class="mt-2 border-t border-slate-100 pt-2 text-right"><button type="button" class="text-xs font-medium text-indigo-700 underline" @click="close">Cerrar</button></p>
        </div>
    </div>
</template>
