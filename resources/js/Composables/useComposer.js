import { computed, inject, onBeforeUnmount, provide, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { normalizeErrors, useLiveValidation } from '@/Composables/useLiveValidation';
import { countChars, countHashtags } from '@/Composables/textCount';

const KEY = Symbol('composer');

/** Estado compartido del compositor: lo crea `Compose.vue` y los componentes lo leen con `useComposerContext()`. */
export function useComposer(props) {
    const post = computed(() => props.post);
    const brand = computed(() => props.brand);
    const accounts = computed(() => props.accounts);
    const contentTypes = computed(() => props.contentTypes);
    const limits = computed(() => props.limits ?? {});
    const editable = computed(() => !props.post || props.post.editable);

    const form = useForm({
        text: (props.post?.text ?? '').replace(/\r\n?/g, '\n'), // LF: el valor de un textarea siempre usa LF y los offsets del cursor deben coincidir
        scheduled_at: props.post?.scheduled_at ?? '',
        schedule: true,
        media: (props.post?.media ?? []).map((m) => m.id),
        targets: (props.post?.targets ?? []).map((t) => ({
            account_id: t.account_id,
            content_type: t.content_type,
            options: { ...(t.options ?? {}) },
        })),
    });

    // Metadatos de la media elegida (para mostrar nombre/duración y la previsualización).
    const mediaItems = ref([...(props.post?.media ?? [])]);
    const objectUrls = [];
    const previews = ref({});
    onBeforeUnmount(() => objectUrls.forEach((u) => URL.revokeObjectURL(u)));

    const onUploaded = (asset, file) => {
        mediaItems.value.push(asset);
        form.media.push(asset.id);
        if (file && asset.kind === 'image') {
            const url = URL.createObjectURL(file);
            objectUrls.push(url);
            previews.value[asset.id] = url;
        }
    };
    const removeMedia = (id) => {
        mediaItems.value = mediaItems.value.filter((m) => m.id !== id);
        form.media = form.media.filter((m) => m !== id);
    };
    const move = (index, delta) => {
        const to = index + delta;
        if (to < 0 || to >= form.media.length) return;
        [form.media[index], form.media[to]] = [form.media[to], form.media[index]];
    };
    const mediaById = (id) => mediaItems.value.find((m) => m.id === id);

    // Cuentas -> destinos
    const targetFor = (accountId) => form.targets.find((t) => t.account_id === accountId);
    const targetIndex = (accountId) => form.targets.findIndex((t) => t.account_id === accountId);
    const typesOf = (network) => props.contentTypes[network] ?? [];
    const typeInfo = (target, network) => typesOf(network).find((t) => t.value === target.content_type);

    const toggleAccount = (account, on) => {
        if (on && !targetFor(account.id)) {
            form.targets.push({ account_id: account.id, content_type: typesOf(account.network)[0]?.value, options: {} });
        } else if (!on) {
            form.targets = form.targets.filter((t) => t.account_id !== account.id);
        }
    };

    // Cuenta de un destino por su posición en `form.targets` (para atribuir cada error a su red y cuenta).
    const describeTarget = (i) => {
        const t = form.targets[i];
        const a = t ? props.accounts.find((x) => x.id === t.account_id) : null;
        return a ? { accountId: a.id, network: a.network, networkLabel: a.network_label, accountName: a.name } : null;
    };

    const { liveErrors, errorList: liveErrorList } = useLiveValidation(form, {
        brandId: () => props.brand.id,
        enabled: () => editable.value,
        immediate: !!props.post,
        describeTarget,
    });

    // Una sola lista: errores en vivo (bloquean Programar y Publicar ahora) y los que devuelve el servidor al enviar.
    // Los del servidor se descartan en cuanto el usuario cambia algo, para que el panel no muestre errores ya corregidos.
    const errorList = computed(() => {
        const seen = new Set();
        return [...liveErrorList.value, ...normalizeErrors(form.errors, describeTarget)].filter((e) => !seen.has(e.id) && seen.add(e.id));
    });
    const hasBlockingErrors = computed(() => liveErrorList.value.length > 0);
    watch(() => [form.text, form.media, form.targets, form.scheduled_at], () => form.clearErrors(), { deep: true });

    const errorsFor = (accountId) => {
        const i = targetIndex(accountId);
        if (i < 0) return [];
        const server = form.errors[`targets.${i}`];
        return [...(liveErrors.value[`targets.${i}`] ?? []), ...(server && !(liveErrors.value[`targets.${i}`] ?? []).includes(server) ? [server] : [])];
    };

    const send = (options = {}) => {
        if (props.post) form.put(route('posts.update', props.post.id), { preserveScroll: true, ...options });
        else form.post(route('brands.posts.store', props.brand.id), { preserveScroll: true, ...options });
    };

    // Un envío ya en curso (clic doble, Enter repetido) no lanza otra petición.
    const sendingNow = ref(false);
    const submit = (schedule) => {
        if (sendingNow.value || form.processing) return;
        form.schedule = schedule;
        send();
    };

    // «Publicar ahora»: se programa con la fecha del servidor (sin `scheduled_at`) y se encola al instante. `sendingNow` se
    // activa de forma síncrona, así un segundo clic o Enter antes de que Inertia marque `processing` no envía otra petición.
    const publishNow = () => {
        if (sendingNow.value || form.processing || hasBlockingErrors.value || form.targets.length === 0) return;
        sendingNow.value = true;
        form.schedule = true;
        form.transform((data) => ({ ...data, schedule: true, publish_now: true, scheduled_at: null }));
        send({
            onFinish: () => {
                sendingNow.value = false;
                form.transform((data) => data);
            },
        });
    };

    const remove = () => {
        if (confirm('¿Eliminar esta publicación?')) router.delete(route('posts.destroy', props.post.id));
    };

    // «Guardar en biblioteca» (CA-26): envía el contenido ACTUAL del compositor (texto, media y destinos), también sin
    // post guardado y con cambios pendientes. No crea ninguna publicación y no saca al usuario del compositor.
    const libraryName = ref((props.post?.text ?? '').slice(0, 60) || 'Mi plantilla');
    const savingToLibrary = ref(false);
    const libraryErrors = ref({});
    const saveToLibrary = (name, { onSaved } = {}) => {
        const clean = (name ?? '').trim();
        if (!clean || savingToLibrary.value) return;
        router.post(route('brands.library.store', props.brand.id), {
            name: clean,
            text: form.text,
            media: form.media,
            targets: form.targets.map((t) => ({ account_id: t.account_id, content_type: t.content_type })),
        }, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => { savingToLibrary.value = true; libraryErrors.value = {}; },
            onSuccess: () => onSaved?.(),
            onError: (errors) => (libraryErrors.value = errors),
            onFinish: () => (savingToLibrary.value = false),
        });
    };

    const selectedNetwork = computed(() => {
        const first = form.targets[0];
        return first ? (props.accounts.find((a) => a.id === first.account_id)?.network ?? null) : null;
    });

    const previewText = computed(() => form.text || 'Aquí verás el texto de tu publicación.');
    const firstPreview = computed(() => form.media.map((id) => previews.value[id]).find(Boolean));
    const charCount = computed(() => countChars(form.text));

    // Texto efectivo de un destino (hoy, el de la plantilla); lo reemplazará el texto por red (C-03).
    const textOf = () => form.text;
    const charsOf = (target) => countChars(textOf(target));
    const hashtagsOf = (target) => countHashtags(textOf(target));
    const accountName = (id) => props.accounts.find((a) => a.id === id)?.name ?? 'Cuenta eliminada';

    const api = {
        post, brand, accounts, contentTypes, limits, editable, form,
        mediaItems, previews, onUploaded, removeMedia, move, mediaById,
        targetFor, targetIndex, typesOf, typeInfo, toggleAccount,
        liveErrors, errorsFor, errorList, hasBlockingErrors, submit, publishNow, sendingNow, remove,
        libraryName, savingToLibrary, libraryErrors, saveToLibrary,
        selectedNetwork, previewText, firstPreview, charCount, accountName,
        textOf, charsOf, hashtagsOf,
    };
    provide(KEY, api);

    return api;
}

export function useComposerContext() {
    return inject(KEY);
}
