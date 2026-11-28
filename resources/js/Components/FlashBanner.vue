<script setup>
import { computed } from 'vue';

const props = defineProps({
    success: { type: /** @type {import('vue').PropType<string | null>} */ (String), default: '' },
    error: { type: /** @type {import('vue').PropType<string | null>} */ (String), default: '' },
    info: { type: /** @type {import('vue').PropType<string | null>} */ (String), default: '' },
    warning: { type: /** @type {import('vue').PropType<string | null>} */ (String), default: '' },
    dismissibleError: Boolean,
});
defineEmits(['dismiss']);

const styles = {
    success: 'border-success-border bg-success-soft text-success-ink',
    error: 'border-danger-border bg-danger-soft text-danger-ink',
    info: 'border-border bg-surface-hover text-foreground',
    warning: 'border-warning-border bg-warning-soft text-warning-ink',
};
const kinds = /** @type {(keyof typeof styles)[]} */ (Object.keys(styles));
const hasMessages = computed(() => kinds.some(kind => props[kind]));
/** @param {keyof typeof styles} kind */
const liveRole = kind => kind === 'error' || kind === 'warning' ? 'alert' : 'status';
</script>

<template>
    <div :class="hasMessages ? 'grid gap-2' : 'sr-only'">
        <div
            v-for="kind in kinds"
            :key="kind"
            :data-flash-kind="kind"
            :class="props[kind] ? [styles[kind], 'flex items-start justify-between gap-3 rounded-xl border px-4 py-3 text-sm'] : 'sr-only'"
        >
            <div class="min-w-0 space-y-3">
                <p :role="liveRole(kind)" aria-atomic="true">{{ props[kind] }}</p>
                <slot v-if="props[kind]" :name="kind" />
            </div>
            <button
                v-if="dismissibleError && kind === 'error' && props.error"
                type="button"
                aria-label="Cerrar error"
                class="shrink-0 rounded px-1 focus-visible:outline focus-visible:outline-2"
                @click="$emit('dismiss')"
                >×</button
            >
        </div>
    </div>
</template>
