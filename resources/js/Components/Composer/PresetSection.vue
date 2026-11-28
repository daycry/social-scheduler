<script setup>
defineProps({
    id: { type: String, required: true },
    title: { type: String, required: true },
    label: { type: String, required: true },
    subtitle: { type: String, default: '' },
    badge: { type: String, default: '' },
});
</script>

<template>
    <section :aria-label="label" class="composer-preset-section">
        <details open class="group">
            <summary :aria-controls="`${id}-content`" class="composer-preset-heading">
                <span
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-surface-hover text-foreground-muted"
                >
                    <slot name="icon">
                        <svg
                            class="h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                            aria-hidden="true"
                        >
                            <path d="M4 7h16M4 17h16" />
                            <circle cx="9" cy="7" r="3" class="fill-surface" />
                            <circle cx="15" cy="17" r="3" class="fill-surface" />
                        </svg>
                    </slot>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold text-foreground-strong">{{ title }}</span>
                    <span
                        v-if="subtitle"
                        class="mt-0.5 block truncate text-xs font-normal text-foreground-subtle"
                        >{{ subtitle }}</span
                    >
                </span>
                <span
                    v-if="badge"
                    class="max-w-[30%] truncate rounded-md bg-accent-secondary-soft px-2 py-1 text-[11px] font-medium text-accent-secondary-ink"
                    >{{ badge }}</span
                >
                <svg
                    class="h-4 w-4 shrink-0 text-foreground-subtle transition-transform group-open:rotate-180"
                    viewBox="0 0 20 20"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    aria-hidden="true"
                >
                    <path d="m5 8 5 5 5-5" />
                </svg>
            </summary>
            <div :id="`${id}-content`" class="composer-preset-body"><slot /></div>
        </details>
    </section>
</template>
