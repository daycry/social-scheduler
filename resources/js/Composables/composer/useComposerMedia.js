import { computed, onBeforeUnmount, ref } from 'vue';

const targetMediaItem = id => ({ id, alt: '', cover_id: null });

/** Trabaja con el formulario reactivo del compositor y refs para el post y la configuración de medios. */
export function useComposerMedia({ form, post, mediaMetaSource, altMaxSource }) {
    // Metadatos de la media elegida (para mostrar nombre/duración y la previsualización).
    const mediaItems = ref([...(post.value?.media ?? [])]);
    // Archivos que solo usa un destino o que se quitaron de la media común.
    const extraAssets = ref([...(post.value?.target_media_assets ?? [])]);
    const objectUrls = [];
    const previews = ref({});
    onBeforeUnmount(() => objectUrls.forEach((u) => URL.revokeObjectURL(u)));

    const onUploaded = (asset, file) => {
        // Idempotente: un sondeo repetido (o un doble evento) no duplica la media en la lista ni en la publicacion.
        if (form.media.includes(asset.id) || mediaItems.value.some((m) => m.id === asset.id)) return;
        mediaItems.value.push(asset);
        form.media.push(asset.id);
        if (file && (asset.kind === 'image' || asset.kind === 'video')) {
            const url = URL.createObjectURL(file);
            objectUrls.push(url);
            previews.value[asset.id] = url;
        }
    };
    const removeMedia = (id) => {
        if (post.value?.studio_origin_url && post.value.media?.some(asset => asset.id === id) && !form.detached_media.includes(id)) {
            form.detached_media.push(id);
        }
        const gone = mediaItems.value.find((m) => m.id === id);
        if (gone && !extraAssets.value.some((m) => m.id === id)) extraAssets.value.push(gone);
        mediaItems.value = mediaItems.value.filter((m) => m.id !== id);
        form.media = form.media.filter((m) => m !== id);
    };
    const move = (index, delta) => {
        const to = index + delta;
        if (to < 0 || to >= form.media.length) return;
        [form.media[index], form.media[to]] = [form.media[to], form.media[index]];
    };
    const mediaById = (id) => mediaItems.value.find((m) => m.id === id) ?? extraAssets.value.find((m) => m.id === id);

    // Media por destino (T-17). Un destino sin `media` hereda la común; «Personalizar» la copia para editarla sola; «Volver a la común»
    // la descarta. La media EFECTIVA de un destino es lo que se valida, se previsualiza y se publica.
    const mediaMeta = computed(() => mediaMetaSource.value ?? {});
    const altMax = computed(() => altMaxSource.value ?? 1000);
    const hasOwnMedia = (target) => Array.isArray(target?.media);
    const effectiveMedia = (target) => (hasOwnMedia(target) ? target.media : form.media.map(targetMediaItem));
    const customizeMedia = (target) => { target.media = form.media.map(targetMediaItem); };
    const unlinkRemovedTargetMedia = (target, ids) => {
        if (!post.value?.studio_origin_url) return;
        const saved = post.value.targets.find(item => item.account_id === target.account_id && item.content_type === target.content_type);
        if (!saved) return;
        const inherited = saved.media ?? post.value.media;
        const removed = inherited.map(asset => asset.id).filter(id => ids.includes(id));
        if (removed.length) target.detached_media = [...new Set([...(target.detached_media ?? []), ...removed])];
    };
    const resetMedia = (target) => {
        if (post.value?.studio_origin_url) {
            const common = new Set(form.media);
            const removed = (target.media ?? []).map(asset => asset.id).filter(id => !common.has(id));
            unlinkRemovedTargetMedia(target, removed);
            // Al volver a heredar, se descartan las marcas de los archivos que siguen en la lista común.
            target.detached_media = (target.detached_media ?? []).filter(id => !common.has(id));
        }
        target.media = null;
    };
    const moveTargetMedia = (target, index, delta) => {
        const to = index + delta;
        if (!hasOwnMedia(target) || to < 0 || to >= target.media.length) return;
        [target.media[index], target.media[to]] = [target.media[to], target.media[index]];
    };
    const removeTargetMedia = (target, index) => {
        const [removed] = target.media.splice(index, 1);
        if (removed) unlinkRemovedTargetMedia(target, [removed.id]);
    };
    // Biblioteca de media del usuario (la misma lista `media.index` del selector): se carga una vez, al abrir el selector de una red.
    const libraryItems = ref([]);
    const libraryState = ref('idle'); // idle | loading | ready | error
    const loadLibrary = async () => {
        if (libraryState.value === 'loading' || libraryState.value === 'ready') return;
        libraryState.value = 'loading';
        try {
            const response = await fetch(route('media.index', { limit: 100 }), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(String(response.status));
            libraryItems.value = (await response.json()).media ?? [];
            libraryState.value = 'ready';
        } catch {
            libraryState.value = 'error';
        }
    };
    const addTargetMedia = (target, id) => {
        if (!id || target.media.some((m) => m.id === id)) return;
        const asset = mediaById(id) ?? libraryItems.value.find((m) => m.id === id);
        if (asset && !mediaById(id)) extraAssets.value.push(asset);
        target.media.push(targetMediaItem(id));
    };
    // Archivos que se pueden añadir a la media propia de un destino: los de la común y los de la biblioteca que aún no están en su lista.
    const addableMedia = (target) => [...mediaItems.value, ...libraryItems.value.filter((m) => !mediaItems.value.some((x) => x.id === m.id))]
        .filter((m) => !target.media?.some((x) => x.id === m.id));

    return {
        mediaItems, previews, onUploaded, removeMedia, move, mediaById,
        mediaMeta, altMax, hasOwnMedia, effectiveMedia, customizeMedia, resetMedia,
        moveTargetMedia, removeTargetMedia, addTargetMedia, addableMedia, loadLibrary, libraryState,
    };
}
