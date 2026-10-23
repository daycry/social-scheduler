<script setup>
import { ref } from 'vue';

/**
 * Sube un fichero de media. En produccion el navegador envia el binario directamente a R2 con URLs
 * prefirmadas (un PUT, o multipart por partes para videos grandes); la app solo prefirma y finaliza.
 * Emite `uploaded` con el asset listo (y el File original, para previsualizar) (metadatos extraidos) o `error` con el motivo.
 */
const emit = defineEmits(['uploaded', 'error']);
defineProps({ label: { type: String, default: 'Subir imagen o video' } });

const busy = ref(false);
const progress = ref(0);
const message = ref('');

const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) || [])[1] || '');

const api = async (url, method, body) => {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: body ? JSON.stringify(body) : undefined,
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(json.message || json.asset?.error || 'No se pudo subir el fichero.');
    }
    return json;
};

const putWithProgress = (url, blob, headers, onProgress) =>
    new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', url);
        Object.entries(headers).forEach(([k, v]) => xhr.setRequestHeader(k, v));
        xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded);
        xhr.onload = () => (xhr.status >= 200 && xhr.status < 300 ? resolve(xhr) : reject(new Error('La subida fue rechazada.')));
        xhr.onerror = () => reject(new Error('Error de red durante la subida.'));
        xhr.send(blob);
    });

const onFile = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    busy.value = true;
    progress.value = 0;
    message.value = '';

    try {
        const { asset, upload } = await api(route('media.store'), 'POST', {
            name: file.name,
            mime: file.type,
            size: file.size,
        });
        let ready;

        if (upload.mode === 'multipart') {
            const parts = [];
            let done = 0;
            for (const part of upload.parts) {
                const start = (part.number - 1) * upload.part_size;
                const blob = file.slice(start, Math.min(start + upload.part_size, file.size));
                const xhr = await putWithProgress(part.url, blob, {}, (loaded) => {
                    progress.value = Math.round(((done + loaded) / file.size) * 100);
                });
                done += blob.size;
                parts.push({ number: part.number, etag: xhr.getResponseHeader('ETag') });
            }
            ready = (await api(route('media.complete', asset.id), 'POST', { parts })).asset;
        } else if (upload.mode === 'single') {
            await putWithProgress(upload.url, file, upload.headers, (loaded) => {
                progress.value = Math.round((loaded / file.size) * 100);
            });
            ready = (await api(route('media.complete', asset.id), 'POST', {})).asset;
        } else {
            // Disco local (solo dev): el PUT a la app ya finaliza y devuelve el asset con sus metadatos.
            const xhr = await putWithProgress(upload.url, file, { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, (loaded) => {
                progress.value = Math.round((loaded / file.size) * 100);
            });
            ready = JSON.parse(xhr.responseText).asset;
        }

        if (!ready || ready.status !== 'listo') throw new Error(ready?.error || 'El fichero no es valido.');
        emit('uploaded', ready, file);
    } catch (e) {
        message.value = e.message;
        emit('error', e.message);
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div>
        <label class="inline-flex cursor-pointer focus-within:ring-2 focus-within:ring-indigo-500 items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <span>{{ busy ? `Subiendo… ${progress}%` : label }}</span>
            <input type="file" class="sr-only" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" :disabled="busy" @change="onFile" />
        </label>
        <p v-if="message" class="mt-1 text-sm text-red-600">{{ message }}</p>
    </div>
</template>
