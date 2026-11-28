<script setup>
import { ref, useId, watch } from 'vue';
import { useBestTimeSuggestion } from '@/Composables/useBestTimeSuggestion';

/** @import { BrandWithTimezone, NetworkOption } from '@/types/shell' */
/** @import { AvailableBestTimeSuggestion } from '@/types/best-time' */
/** @typedef {Pick<NetworkOption, 'value' | 'label'>} NetworkChoice */

const props = defineProps({
    brand: { type: /** @type {import('vue').PropType<BrandWithTimezone | null>} */ (Object), default: null },
    networks: { type: /** @type {import('vue').PropType<NetworkChoice[]>} */ (Array), default: () => [] },
    value: { type: String, default: '' },
    disabled: Boolean,
    requireNetworkChoice: Boolean,
});
const emit = defineEmits({
    /** @param {AvailableBestTimeSuggestion} value */
    apply: (value) => value.available === true,
});
const id = useId();
const network = ref('');
watch(
    [() => props.networks, () => props.requireNetworkChoice],
    ([choices, required]) => {
        if (!choices.some((choice) => choice.value === network.value)) {
            network.value = required ? '' : (choices[0]?.value ?? '');
        }
    },
    { immediate: true, deep: true },
);
const { busy, notice, error, suggest } = useBestTimeSuggestion(() => ({
    brandId: props.brand?.id ?? null,
    timezone: props.brand?.timezone || 'UTC',
    network: network.value,
    value: props.value,
    disabled: props.disabled,
}), (value) => emit('apply', value));
</script>

<template>
    <div class="min-w-0 max-w-full" data-testid="best-time-suggestion">
        <div class="flex flex-wrap items-end gap-2">
            <div v-if="networks.length > 1 || requireNetworkChoice" class="min-w-0 max-w-full">
                <label :for="id" class="block text-xs font-medium text-foreground-secondary"
                    >Red para la mejor hora</label
                >
                <select :id="id" v-model="network" class="field w-auto" :disabled="disabled">
                    <option v-if="requireNetworkChoice" value="" disabled>Elige una red</option>
                    <option
                        v-for="choice in networks"
                        :key="choice.value"
                        :value="choice.value"
                        >{{ choice.label }}</option
                    >
                </select>
            </div>
            <button
                type="button"
                class="btn btn-secondary"
                :disabled="disabled || busy || !brand || !network"
                :aria-busy="busy"
                @click="suggest"
            >
                {{ busy ? 'Consultando…' : 'Mejor hora' }}
            </button>
        </div>
        <p v-if="!networks.length" class="mt-1 text-xs text-foreground-muted"
            >Selecciona una red con cuentas disponibles.</p
        >
        <p v-else-if="requireNetworkChoice && !network" class="mt-1 text-xs text-foreground-muted"
            >Elige una red para consultar sus métricas.</p
        >
        <p role="status" aria-atomic="true" class="mt-1 text-sm text-foreground-muted">{{ notice }}</p>
        <p v-if="error" role="alert" class="mt-1 text-sm text-danger">{{ error }}</p>
    </div>
</template>
