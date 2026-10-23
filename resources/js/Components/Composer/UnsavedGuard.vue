<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useComposerContext } from '@/Composables/useComposer';

/**
 * Confirmación al salir con cambios sin guardar:
 *  - navegación de Inertia (Cerrar, enlaces): se intercepta con `router.on('before')` y se pregunta en un diálogo propio;
 *  - cierre o recarga de la pestaña: `beforeunload`.
 * No pregunta sin cambios, ni al guardar o eliminar (visitas que no son GET: el guardado deja el formulario limpio).
 */
const { form } = useComposerContext();

const pending = ref(null); // URL a la que se quería ir
const dialog = ref(null);
const keepEditing = ref(null);
let leaving = false;
let opener = null;
let removeBefore = null;

const samePage = (url) => {
    try {
        const target = new URL(url, window.location.href);
        return target.pathname === window.location.pathname && target.search === window.location.search;
    } catch {
        return true;
    }
};

const onBefore = (event) => {
    const visit = event.detail.visit;
    if (leaving || !form.isDirty || visit.method !== 'get' || samePage(visit.url)) return;
    opener = document.activeElement;
    pending.value = visit.url.toString();
    nextTick(() => keepEditing.value?.focus());
    return false; // cancela la visita
};

const onBeforeUnload = (event) => {
    if (leaving || !form.isDirty) return;
    event.preventDefault();
    event.returnValue = '';
};

const stay = () => {
    pending.value = null;
    nextTick(() => opener?.focus?.());
};
const leave = () => {
    const url = pending.value;
    leaving = true;
    pending.value = null;
    router.visit(url);
};

// Escape cancela y el Tab no sale del diálogo.
const onKeydown = (e) => {
    if (e.key === 'Escape') {
        e.preventDefault();
        stay();
    } else if (e.key === 'Tab') {
        const buttons = [...dialog.value.querySelectorAll('button')];
        const first = buttons[0];
        const last = buttons[buttons.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }
};

onMounted(() => {
    removeBefore = router.on('before', onBefore);
    window.addEventListener('beforeunload', onBeforeUnload);
});
onBeforeUnmount(() => {
    removeBefore?.();
    window.removeEventListener('beforeunload', onBeforeUnload);
});
</script>

<template>
    <div v-if="pending" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4" data-testid="unsaved-guard">
        <div
            ref="dialog"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="unsaved-title"
            aria-describedby="unsaved-desc"
            class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl"
            @keydown="onKeydown"
        >
            <h2 id="unsaved-title" class="text-lg font-semibold text-slate-900">¿Salir sin guardar?</h2>
            <p id="unsaved-desc" class="mt-2 text-sm text-slate-700">Tienes cambios sin guardar en esta publicación. Si sales ahora, se perderán.</p>
            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <button ref="keepEditing" type="button" class="btn btn-primary" @click="stay">Seguir editando</button>
                <button type="button" class="btn btn-secondary" @click="leave">Salir sin guardar</button>
            </div>
        </div>
    </div>
</template>
