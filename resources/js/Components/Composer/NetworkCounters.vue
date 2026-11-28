<script setup>
import { computed } from 'vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { useComposerContext } from '@/Composables/useComposer';
import { formatThousands } from '@/Composables/textCount';

const { form, accounts, limits, charsOf, hashtagsOf, isUnlinked } = useComposerContext();

/** Un contador por destino activo, con los máximos del servidor para su tipo de contenido. */
const rows = computed(() => form.targets.map((t) => {
    const account = accounts.value.find((a) => a.id === t.account_id);
    const limit = limits.value?.[t.content_type] ?? {};
    // El máximo propio de la cuenta (Mastodon: el de su instancia) manda sobre el del tipo de contenido.
    const maxChars = account?.limits?.max_chars ?? limit.max_chars ?? null;
    const chars = charsOf(t);
    const hashtags = hashtagsOf(t);
    return {
        key: `${t.account_id}-${t.content_type}`,
        network: account?.network,
        name: account ? `${account.network_label} · ${account.name}` : 'Cuenta eliminada',
        own: isUnlinked(t),
        chars,
        maxChars,
        overChars: maxChars != null && chars > maxChars,
        hashtags,
        maxHashtags: limit.max_hashtags ?? null,
        overHashtags: limit.max_hashtags != null && hashtags > limit.max_hashtags,
        adviseHashtags: limit.advise_hashtags ?? null,
        adviseOver: limit.advise_hashtags != null && hashtags > limit.advise_hashtags,
    };
}));
</script>

<template>
    <ul
        v-if="rows.length"
        class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs"
        aria-label="Contadores por red"
        data-testid="network-counters"
    >
        <li v-for="r in rows" :key="r.key" class="flex min-w-0 items-center gap-1.5">
            <NetworkIcon v-if="r.network" :network="r.network" size="sm" />
            <span class="truncate text-foreground-muted">{{ r.name }}</span>
            <span v-if="r.own" class="shrink-0 rounded bg-accent-marker px-1.5 text-accent-strong">texto propio</span>
            <span
                data-testid="counter-chars"
                aria-live="polite"
                class="rounded px-1.5 py-0.5 font-medium"
                :class="r.overChars ? 'bg-danger-soft text-danger-ink' : 'bg-surface-hover text-foreground-secondary'"
                >{{ formatThousands(r.chars)
                }}<template v-if="r.maxChars != null"> / {{ formatThousands(r.maxChars) }}</template
                ><template v-if="r.overChars"> · supera el máximo</template></span
            >
            <span
                v-if="r.maxHashtags != null"
                data-testid="counter-hashtags"
                aria-live="polite"
                class="rounded px-1.5 py-0.5 font-medium"
                :class="r.overHashtags ? 'bg-danger-soft text-danger-ink' : 'bg-surface-hover text-foreground-secondary'"
                >{{ r.hashtags }} / {{ r.maxHashtags }} #<template v-if="r.overHashtags">
                    · supera el máximo</template
                ></span
            >
            <span
                v-if="r.adviseOver"
                data-testid="counter-hashtags-advice"
                role="status"
                class="rounded bg-warning-soft px-1.5 py-0.5 font-medium text-warning-ink"
                >Threads solo admite {{ r.adviseHashtags }} etiqueta de tema por publicación: se usa la primera.</span
            >
        </li>
    </ul>
</template>
