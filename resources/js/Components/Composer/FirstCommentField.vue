<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import { useComposerContext } from '@/Composables/useComposer';
import { countChars, padForInsert } from '@/Composables/textCount';

/**
 * Primer comentario de un destino (C-01b): se publica como comentario propio justo después de la publicación. Solo en los tipos que
 * lo admiten (`firstCommentMax`); no en TikTok ni en Stories. Es un «campo activo» más para insertar textos guardados.
 * En YouTube necesita el permiso `youtube.force-ssl`: sin él se desactiva y se pide reconectar el canal.
 */
const props = defineProps({ account: { type: Object, required: true } });
const { brand, editable, targetFor, firstCommentMax, registerField, unregisterField } = useComposerContext();

const target = computed(() => targetFor(props.account.id));
const max = computed(() => (target.value ? (firstCommentMax.value[target.value.content_type] ?? null) : null));
const length = computed(() => countChars(target.value?.first_comment ?? ''));
const over = computed(() => max.value !== null && length.value > max.value);
const blocked = computed(() => props.account.can_comment === false);
const area = ref(null);
const id = computed(() => `first-comment-${props.account.id}`);

const insertAtCursor = async (raw, { pad = false } = {}) => {
    const el = area.value;
    if (!el || !target.value) return;
    const value = el.value;
    const start = el.selectionStart ?? value.length;
    const end = el.selectionEnd ?? start;
    const text = pad ? padForInsert(value, start, end, raw) : raw;
    target.value.first_comment = value.slice(0, start) + text + value.slice(end);
    await nextTick();
    el.setSelectionRange(start + text.length, start + text.length);
};
const self = { insertAtCursor };
onBeforeUnmount(() => unregisterField(self));
</script>

<template>
    <div v-if="target && max !== null" class="space-y-1" data-testid="first-comment">
        <InputLabel :for="id" value="Primer comentario (opcional)" />
        <p :id="`${id}-hint`" class="text-xs text-slate-600">Se publica como comentario de la cuenta justo después de publicar. No se guarda en la biblioteca.</p>
        <p v-if="blocked" class="rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900" role="status" data-testid="first-comment-reconnect">
            Reconecta el canal para comentar: la conexión actual no tiene el permiso de comentarios. En Conexiones, pulsa «+ Conectar» en la tarjeta de YouTube.
            <Link :href="route('brands.connections.index', brand.id)" class="link">Ir a conexiones</Link>
        </p>
        <textarea
            :id="id"
            ref="area"
            v-model="target.first_comment"
            :disabled="!editable || blocked"
            rows="3"
            class="field mt-1 resize-y"
            :aria-describedby="`${id}-hint ${id}-count`"
            :aria-invalid="over ? 'true' : undefined"
            @focus="registerField(self)"
        ></textarea>
        <p :id="`${id}-count`" class="text-right text-xs" :class="over ? 'font-medium text-red-700' : 'text-slate-600'" aria-live="polite" data-testid="first-comment-count">
            {{ length }} / {{ max }}<template v-if="over"> · supera el máximo de {{ account.network_label }}</template>
        </p>
    </div>
</template>
