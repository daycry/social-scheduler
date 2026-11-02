<script setup>
import { computed, ref } from 'vue';
import { useComposerContext } from '@/Composables/useComposer';

const props = defineProps({ account: { type: Object, required: true } });
const { editable, form, targetFor } = useComposerContext();
const names = computed(() => targetFor(props.account.id)?.options.collaborators ?? []);
const max = computed(() => props.account.instagram_presets?.max_collaborators ?? 3);
const open = ref(false);
const username = ref('');
const error = ref('');
function add() {
    if (!editable.value) return;
    const name = username.value.trim().replace(/^@/, '').toLowerCase();
    if (!/^[a-z0-9._]{1,30}$/.test(name)) { error.value = 'Indica un usuario de Instagram válido, sin enlaces.'; return; }
    if (names.value.includes(name)) { error.value = 'Ese colaborador ya está añadido.'; return; }
    if (names.value.length >= max.value) { error.value = `La API admite hasta ${max.value} colaboradores.`; return; }
    targetFor(props.account.id).options.collaborators = [...names.value, name];
    username.value = '';
    error.value = '';
}
function remove(name) {
    if (editable.value) targetFor(props.account.id).options.collaborators = names.value.filter(value => value !== name);
}
</script>

<template>
    <div class="space-y-2" data-testid="instagram-collaborators">
        <p class="text-sm font-medium text-slate-900">Colaboradores</p>
        <ul v-if="names.length" class="flex flex-wrap gap-2" aria-label="Colaboradores de Instagram">
            <li v-for="name in names" :key="name" class="inline-flex max-w-full items-center gap-1 rounded-lg border border-violet-200 bg-violet-50 px-2 py-1 text-sm text-violet-900">
                <span class="min-w-0 break-all">@{{ name }}</span>
                <button type="button" :disabled="!editable" :aria-label="`Quitar colaborador ${name}`" class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded hover:bg-violet-100 disabled:opacity-50" @click="remove(name)">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m4 4 8 8M12 4l-8 8" /></svg>
                </button>
            </li>
        </ul>
        <button type="button" :disabled="!editable || !form.media.length || names.length >= max" :aria-expanded="open" :aria-controls="`collaborator-editor-${account.id}`" class="btn btn-secondary border-dashed px-3 font-medium" @click="open = !open">Añadir colaborador</button>
        <div v-if="open" :id="`collaborator-editor-${account.id}`" class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <label :for="`collaborator-${account.id}`" class="block text-sm font-medium">Usuario de Instagram</label>
            <div class="flex flex-wrap gap-2">
                <input :id="`collaborator-${account.id}`" v-model="username" :disabled="!editable" class="field min-w-0 flex-1 basis-40" type="text" maxlength="31" placeholder="@usuario" autocomplete="off" @keydown.enter.prevent.stop="add" @input="error = ''" />
                <button type="button" :disabled="!editable || names.length >= max" class="btn btn-secondary" @click="add">Añadir usuario</button>
            </div>
            <p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p>
        </div>
        <p class="text-xs leading-relaxed text-slate-600"><span v-if="!form.media.length">Adjunta una imagen o un vídeo para añadir colaboradores. </span>Hasta {{ max }} mediante esta API. La invitación se envía al publicar y cada cuenta debe aceptarla en Instagram; las cuentas privadas o con restricciones pueden ser rechazadas.</p>
    </div>
</template>
