<script setup>
import { nextTick } from 'vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { useComposerContext } from '@/Composables/useComposer';

/**
 * Una sola lista con todos los errores del compositor (en vivo y del servidor): icono de la red, cuenta y mensaje, con un
 * enlace que lleva al control afectado. La región `aria-live` educada está siempre montada: así los lectores de pantalla
 * anuncian los errores cuando aparecen. Mientras haya errores en vivo, Programar y Publicar ahora se deshabilitan
 * (`ActionBar`); Guardar borrador y Guardar en biblioteca siguen disponibles.
 */
const { errorList, openTargetText, hasOwnText, form } = useComposerContext();

// El error de un texto propio (máximo de caracteres o de hashtags) lleva a la pestaña de esa red; el resto, al control de siempre.
const focusError = (e) => {
    const target = e.scope === 'target' ? form.targets[e.targetIndex] : null;
    if (target && e.accountId != null && hasOwnText(target)) {
        openTargetText(e.accountId);
        nextTick(() => focusControl(`text-target-${e.accountId}`));
        return;
    }
    focusControl(e.focusId);
};

const focusControl = (id) => {
    const el = id ? document.getElementById(id) : null;
    if (!el) return;
    for (let panel = el.closest('details'); panel; panel = panel.parentElement?.closest('details')) panel.open = true;
    if (!el.matches('button, input, select, textarea, a[href], [tabindex]')) el.setAttribute('tabindex', '-1');
    el.scrollIntoView({ block: 'center' });
    el.focus({ preventScroll: true });
};
</script>

<template>
    <div aria-live="polite" data-testid="errors-panel" class="sticky bottom-3 z-30 min-w-0">
        <section v-if="errorList.length" class="rounded-xl border border-red-200 bg-white shadow-lg shadow-slate-900/5" aria-labelledby="errors-title">
            <details open class="group">
                <summary class="flex cursor-pointer list-none items-center gap-2 rounded-xl bg-red-50 px-3 py-3 text-red-900 [&::-webkit-details-marker]:hidden">
                    <svg class="h-5 w-5 shrink-0 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="m12 3 10 18H2L12 3Z" /><path d="M12 9v4m0 4v.1" /></svg>
                    <h2 id="errors-title" class="min-w-0 flex-1 text-sm font-semibold">{{ errorList.length === 1 ? 'Hay 1 error que corregir' : `Hay ${errorList.length} errores que corregir` }}</h2>
                    <svg class="h-4 w-4 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m5 8 5 5 5-5" /></svg>
                </summary>
                <ul class="max-h-[35vh] space-y-3 overflow-y-auto border-t border-red-100 px-3 py-3">
                <li v-for="e in errorList" :key="e.id" class="grid min-w-0 grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-2 text-sm text-slate-800" data-testid="error-item">
                    <NetworkIcon v-if="e.network" :network="e.network" size="sm" class="mt-1" />
                    <svg v-else class="mt-1 h-5 w-5 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 11v6m0-10v1" /></svg>
                    <span class="min-w-0 break-words pt-1">
                        <strong v-if="e.accountName" class="font-semibold">{{ e.networkLabel }} · {{ e.accountName }}: </strong>{{ e.message }}
                    </span>
                    <button v-if="e.focusId" type="button" class="inline-flex min-h-8 min-w-8 items-center justify-center rounded-lg border border-slate-200 bg-white px-2 text-xs font-medium text-indigo-700 hover:bg-indigo-50" :aria-label="`Ir al control con el error${e.accountName ? ' de ' + e.accountName : ''}: ${e.message}`" @click="focusError(e)">Ir</button>
                </li>
                </ul>
            </details>
        </section>
    </div>
</template>
