<script setup>
import { computed } from 'vue';

const props = defineProps({
    draft: { type: Object, required: true },
    images: { type: Array, default: () => [] },
    defaultColor: { type: String, required: true },
});
// Color de la vista previa: el del formulario si es un #RRGGBB valido; si no, el color por defecto (el mismo que aplica la pagina publica).
const accent = computed(() => (/^#[0-9a-fA-F]{6}$/.test(props.draft.theme_color) ? props.draft.theme_color : props.defaultColor));
const dark = computed(() => props.draft.theme_mode === 'dark');
const previewBlocks = computed(() => props.draft.blocks.filter((b) => b.label?.trim() || ['divider', 'social', 'posts'].includes(b.type)));
const initial = computed(() => (props.draft.title || '?').trim().charAt(0).toUpperCase());
const avatarUrl = computed(() => props.images?.find((m) => m.id === props.draft.avatar_media_id)?.url ?? null);
</script>

<template>
    <aside class="lg:sticky lg:top-4" aria-label="Vista previa de tu página">
        <p class="mb-2 text-xs font-medium text-slate-600">Vista previa en vivo</p>
        <div
            data-testid="smartlink-preview"
            class="mx-auto w-full max-w-xs rounded-3xl border border-slate-300 p-5 text-center shadow-sm"
            :style="{ background: dark ? '#111827' : '#f9fafb', color: dark ? '#f9fafb' : '#111827' }"
        >
            <img
                loading="lazy"
                v-if="avatarUrl"
                :src="avatarUrl"
                alt=""
                class="mx-auto h-20 w-20 rounded-full border-4 object-cover"
                :style="{ borderColor: accent }"
            />
            <span
                v-else
                class="mx-auto inline-flex h-20 w-20 items-center justify-center rounded-full text-3xl font-bold text-white"
                :style="{ background: accent }"
                aria-hidden="true"
                >{{ initial }}</span
            >
            <p class="mt-3 text-lg font-bold">{{ draft.title || 'Tu título' }}</p>
            <p
                v-if="draft.bio"
                class="mt-1 whitespace-pre-line text-sm"
                :style="{ color: dark ? '#9ca3af' : '#6b7280' }"
                >{{ draft.bio }}</p
            >
            <ul class="mt-4 space-y-2">
                <li v-for="(b, i) in previewBlocks" :key="b.id ?? `p${i}`">
                    <p
                        v-if="b.type === 'header'"
                        class="pt-2 text-xs font-bold uppercase tracking-wider"
                        :style="{ color: dark ? '#9ca3af' : '#6b7280' }"
                        >{{ b.label }}</p
                    >
                    <hr v-else-if="b.type === 'divider'" class="my-1 border-slate-400" />
                    <span
                        v-else-if="b.type === 'youtube'"
                        class="block rounded-xl bg-slate-900 px-3 py-6 text-sm font-semibold text-white"
                        >▶ {{ b.label }}</span
                    >
                    <span
                        v-else-if="b.type === 'social'"
                        class="flex flex-wrap justify-center gap-1 text-xs font-semibold"
                        ><span
                            v-for="(it, k) in b.data.items"
                            :key="k"
                            class="rounded-full border-2 px-2 py-1"
                            :style="{ borderColor: accent }"
                            >{{ it.network }}</span
                        ></span
                    >
                    <span
                        v-else-if="b.type === 'email'"
                        class="block rounded-xl border-2 px-3 py-2 text-sm"
                        :style="{ borderColor: accent }"
                        >✉ {{ b.label }}</span
                    >
                    <span
                        v-else-if="b.type === 'posts'"
                        class="block rounded-xl border-2 px-3 py-2 text-sm"
                        :style="{ borderColor: accent }"
                        >{{ b.label || 'Posts recientes' }}</span
                    >
                    <span
                        v-else
                        class="block rounded-xl px-3 py-2.5 text-sm font-semibold text-white"
                        :style="{ background: accent }"
                        >{{ b.label }}</span
                    >
                </li>
                <li v-if="!previewBlocks.length" class="text-xs" :style="{ color: dark ? '#9ca3af' : '#6b7280' }"
                    >Añade bloques para verlos aquí.</li
                >
            </ul>
        </div>
    </aside>
</template>
