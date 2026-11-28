<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { uploadMedia } from '@/lib/http';

/**
 * Sube un fichero de media. En produccion el navegador envia el binario directamente a R2 con URLs
 * prefirmadas (un PUT, o multipart por partes para videos grandes); la app solo prefirma y finaliza.
 * Emite `uploaded` con el asset listo (y el File original, para previsualizar) (metadatos extraidos) o `error` con el motivo.
 */
const emit = defineEmits(['uploaded', 'error', 'busy']);
defineProps({ label: { type: String, default: 'Subir imagen o video' } });

const busy = ref(false);
const progress = ref(0);
const message = ref('');
const fileInput = ref(null);
defineExpose({ focusPicker: () => fileInput.value?.focus() });
// Quien lo usa (el compositor) se entera de que hay una subida en curso.
watch(busy, (b) => emit('busy', b));

let upload = null;
onBeforeUnmount(() => {
    if (!upload) return;
    upload.abort();
    emit('busy', false);
});

async function withUpload(run) {
    if (busy.value) return;
    busy.value = true;
    const current = new AbortController();
    upload = current;
    try {
        await run(current.signal);
    } finally {
        upload = null;
        busy.value = false;
    }
}

const onFile = (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    return withUpload(async signal => {
        progress.value = 0;
        message.value = '';
        try {
            const ready = await uploadMedia(file, {
                signal,
                onProgress: (loaded, total) => { progress.value = total > 0 ? Math.round((loaded / total) * 100) : 0; },
            });
            if (signal.aborted) return;
            emit('uploaded', ready, file);
        } catch (error) {
            if (signal.aborted) return;
            message.value = error.message;
            emit('error', error.message);
        }
    });
};
</script>

<template>
    <div>
        <label
            class="inline-flex cursor-pointer focus-within:ring-2 focus-within:ring-focus items-center rounded-md border border-control-border bg-surface px-3 py-2 text-sm font-medium text-foreground-secondary hover:bg-canvas"
        >
            <span>{{ busy ? `Subiendo… ${progress}%` : label }}</span>
            <input
                ref="fileInput"
                type="file"
                class="sr-only"
                accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime"
                :disabled="busy"
                @change="onFile"
            />
        </label>
        <p v-if="message" class="mt-1 text-sm text-danger">{{ message }}</p>
    </div>
</template>
