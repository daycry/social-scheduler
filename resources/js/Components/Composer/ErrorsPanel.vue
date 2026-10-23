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
    if (!el.matches('button, input, select, textarea, a[href], [tabindex]')) el.setAttribute('tabindex', '-1');
    el.scrollIntoView({ block: 'center' });
    el.focus({ preventScroll: true });
};
</script>

<template>
    <div aria-live="polite" data-testid="errors-panel">
        <section v-if="errorList.length" class="rounded-lg border border-red-200 bg-red-50 p-3" aria-labelledby="errors-title">
            <h2 id="errors-title" class="text-sm font-semibold text-red-900">{{ errorList.length === 1 ? 'Hay 1 error que corregir' : `Hay ${errorList.length} errores que corregir` }}</h2>
            <ul class="mt-2 space-y-1.5">
                <li v-for="e in errorList" :key="e.id" class="flex min-w-0 items-start gap-2 text-sm text-red-900" data-testid="error-item">
                    <NetworkIcon v-if="e.network" :network="e.network" size="sm" class="mt-0.5" />
                    <span class="min-w-0 flex-1 break-words">
                        <strong v-if="e.accountName" class="font-semibold">{{ e.networkLabel }} · {{ e.accountName }}: </strong>{{ e.message }}
                    </span>
                    <button v-if="e.focusId" type="button" class="link shrink-0 text-xs" :aria-label="`Ir al control con el error${e.accountName ? ' de ' + e.accountName : ''}: ${e.message}`" @click="focusError(e)">Ir</button>
                </li>
            </ul>
        </section>
    </div>
</template>
