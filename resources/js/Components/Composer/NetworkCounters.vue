<script setup>
import { computed } from 'vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { useComposerContext } from '@/Composables/useComposer';
import { formatThousands } from '@/Composables/textCount';

const { form, accounts, limits, charsOf, hashtagsOf } = useComposerContext();

/** Un contador por destino activo, con los máximos del servidor para su tipo de contenido. */
const rows = computed(() => form.targets.map((t) => {
    const account = accounts.value.find((a) => a.id === t.account_id);
    const limit = limits.value?.[t.content_type] ?? {};
    const chars = charsOf(t);
    const hashtags = hashtagsOf(t);
    return {
        key: `${t.account_id}-${t.content_type}`,
        network: account?.network,
        name: account ? `${account.network_label} · ${account.name}` : 'Cuenta eliminada',
        chars,
        maxChars: limit.max_chars ?? null,
        overChars: limit.max_chars != null && chars > limit.max_chars,
        hashtags,
        maxHashtags: limit.max_hashtags ?? null,
        overHashtags: limit.max_hashtags != null && hashtags > limit.max_hashtags,
    };
}));
</script>

<template>
    <ul v-if="rows.length" class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-label="Contadores por red" data-testid="network-counters">
        <li v-for="r in rows" :key="r.key" class="flex min-w-0 items-center gap-1.5">
            <NetworkIcon v-if="r.network" :network="r.network" size="sm" />
            <span class="truncate text-slate-600">{{ r.name }}</span>
            <span
                data-testid="counter-chars"
                class="rounded px-1.5 py-0.5 font-medium"
                :class="r.overChars ? 'bg-red-100 text-red-800' : 'bg-slate-100 text-slate-700'"
            >{{ formatThousands(r.chars) }}<template v-if="r.maxChars != null"> / {{ formatThousands(r.maxChars) }}</template><template v-if="r.overChars"> · supera el máximo</template></span>
            <span
                v-if="r.maxHashtags != null"
                data-testid="counter-hashtags"
                class="rounded px-1.5 py-0.5 font-medium"
                :class="r.overHashtags ? 'bg-red-100 text-red-800' : 'bg-slate-100 text-slate-700'"
            >{{ r.hashtags }} / {{ r.maxHashtags }} #<template v-if="r.overHashtags"> · supera el máximo</template></span>
        </li>
    </ul>
</template>
