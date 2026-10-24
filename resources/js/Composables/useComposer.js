import { computed, inject, onBeforeUnmount, provide, ref, shallowRef, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { normalizeErrors, useLiveValidation } from '@/Composables/useLiveValidation';
import { countChars, countHashtags } from '@/Composables/textCount';
import { MANAGED_KEYS, switchesFor } from '@/Composables/composerOptions';

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
        // Notas internas (C-09): texto plano que nunca se publica. En un post no editable se guardan con su propio endpoint.
        notes: (props.post?.notes ?? '').replace(/\r\n?/g, '\n'),
        scheduled_at: props.post?.scheduled_at ?? '',
        schedule: true,
        media: (props.post?.media ?? []).map((m) => m.id),
        targets: (props.post?.targets ?? []).map((t) => ({
            account_id: t.account_id,
            content_type: t.content_type,
            // `null` = vinculado al texto común (plantilla); una cadena = texto propio de esta red (C-03).
            text: t.text ?? null,
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
        if (file && (asset.kind === 'image' || asset.kind === 'video')) {
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
    const typeLabel = (value) => props.typeLabels?.[value] ?? '';
    const typesOf = (network) => props.contentTypes[network] ?? [];
    const typeInfo = (target, network) => typesOf(network).find((t) => t.value === target.content_type);

    // Presets (C-04): los interruptores recordados de (marca, red) son el valor inicial al activar una red. Solo se aplican los
    // que esa cuenta ofrece (en modo borrador de TikTok no hay ninguno), así nunca se envía una opción que la cuenta rechaza.
    const presetOptions = (account, type) => {
        const saved = props.presets?.[account.network] ?? {};
        const out = {};
        for (const s of switchesFor(account, { content_type: type })) {
            if (s.remember && typeof saved[s.key] === 'boolean') out[s.key] = saved[s.key];
        }
        if (account.network === 'instagram' && type === 'instagram_reel' && typeof saved.share_to_feed === 'boolean') out.share_to_feed = saved.share_to_feed;
        return out;
    };

    // Al cambiar el tipo o la cuenta, se podan las opciones de C-04 que ya no aplican (p. ej. Duet al pasar de vídeo a fotos).
    const pruneOptions = () => {
        for (const t of form.targets) {
            const account = props.accounts.find((a) => a.id === t.account_id);
            const keep = new Set(switchesFor(account, t).map((s) => s.key));
            if (account?.network === 'youtube') ['category_id', 'tags'].forEach((k) => keep.add(k));
            for (const key of MANAGED_KEYS) {
                if (key in t.options && !keep.has(key)) delete t.options[key];
            }
        }
    };
    // Al cambiar de tipo también se aplican los interruptores recordados que ese tipo ofrece (`share_to_feed` en un reel de Instagram,
    // `shorts_hashtag` en un Short, Duet y Stitch al volver a vídeo), pero solo los que el post aún no tiene: lo que el usuario ya
    // tocó en este post (activado o desactivado, la clave existe) no se pisa.
    const applyPresets = (only = null) => {
        for (const t of only ?? form.targets) {
            const account = props.accounts.find((a) => a.id === t.account_id);
            if (!account) continue;
            for (const [key, value] of Object.entries(presetOptions(account, t.content_type))) {
                if (!(key in t.options)) t.options[key] = value;
            }
        }
    };
    pruneOptions();
    // Los presets solo se aplican al destino que se activa o cuyo tipo cambia: los demás no se tocan.
    let seen = new Map(form.targets.map((t) => [t.account_id, t.content_type]));
    watch(() => form.targets.map((t) => `${t.account_id}:${t.content_type}`).join('|'), () => {
        const changed = form.targets.filter((t) => seen.get(t.account_id) !== t.content_type);
        seen = new Map(form.targets.map((t) => [t.account_id, t.content_type]));
        pruneOptions();
        applyPresets(changed);
    });

    const toggleAccount = (account, on) => {
        if (on && !targetFor(account.id)) {
            const type = typesOf(account.network)[0]?.value;
            form.targets.push({ account_id: account.id, content_type: type, text: null, options: presetOptions(account, type) });
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
    // Fecha ya pasada (en la zona de la marca): se valida en el cliente, el servidor la revalida al enviar. Bloquea Programar, no
    // Guardar borrador ni Publicar ahora (que pone la fecha el servidor).
    const clock = ref(Date.now());
    const clockTimer = setInterval(() => (clock.value = Date.now()), 15000);
    onBeforeUnmount(() => clearInterval(clockTimer));
    const dateErrorList = computed(() => {
        const at = form.scheduled_at;
        if (!editable.value || !at || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(at)) return [];
        let zone = props.brand.timezone || 'UTC';
        try { new Intl.DateTimeFormat('en-CA', { timeZone: zone }); } catch { zone = 'UTC'; }
        const p = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(clock.value)).map((x) => [x.type, x.value]));
        const nowLocal = `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
        return at.slice(0, 16) <= nowLocal ? normalizeErrors({ scheduled_at: ['La fecha de publicacion debe ser futura.'] }) : [];
    });
    const errorList = computed(() => {
        const seen = new Set();
        return [...liveErrorList.value, ...dateErrorList.value, ...normalizeErrors(form.errors, describeTarget)].filter((e) => !seen.has(e.id) && seen.add(e.id));
    });
    // Subida de media en curso (la reporta el uploader): no se programa, publica ni guarda en la biblioteca con la media a medias.
    const uploadingMedia = ref(false);
    const hasBlockingErrors = computed(() => liveErrorList.value.length > 0 || uploadingMedia.value);
    const scheduleBlocked = computed(() => hasBlockingErrors.value || dateErrorList.value.length > 0);
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
        // Con una subida en curso no se guarda nada (ni el borrador): `ActionBar` deshabilita las acciones y avisa.
        if (sendingNow.value || form.processing || uploadingMedia.value) return;
        if (schedule && dateErrorList.value.length > 0) return;
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
        if (!clean || savingToLibrary.value || uploadingMedia.value) return;
        router.post(route('brands.library.store', props.brand.id), {
            name: clean,
            text: form.text,
            media: form.media,
            targets: form.targets.map((t) => ({ account_id: t.account_id, content_type: t.content_type, text: t.text })),
        }, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => { savingToLibrary.value = true; libraryErrors.value = {}; },
            onSuccess: () => onSaved?.(),
            onError: (errors) => (libraryErrors.value = errors),
            onFinish: () => (savingToLibrary.value = false),
        });
    };

    // Notas (C-09): `PATCH posts/{post}/notes` funciona aunque el post ya no sea editable y no toca los destinos. Sin post guardado
    // las notas viajan con el formulario al crearlo. `defaults` deja las notas guardadas como valor limpio (no cuenta como cambio sin guardar).
    const NOTES_MAX = 5000;
    const savingNotes = ref(false);
    const notesError = ref('');
    const notesSaved = ref(false);
    const saveNotes = () => {
        if (!props.post || savingNotes.value || [...(form.notes ?? '')].length > NOTES_MAX) return;
        const value = form.notes;
        router.patch(route('posts.notes', props.post.id), { notes: value }, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => { savingNotes.value = true; notesError.value = ''; notesSaved.value = false; },
            onSuccess: () => { form.defaults('notes', value); notesSaved.value = true; },
            onError: (errors) => (notesError.value = errors.notes ?? 'No se pudieron guardar las notas.'),
            onFinish: () => (savingNotes.value = false),
        });
    };
    watch(() => form.notes, () => { notesSaved.value = false; });

    const selectedNetwork = computed(() => {
        const first = form.targets[0];
        return first ? (props.accounts.find((a) => a.id === first.account_id)?.network ?? null) : null;
    });

    // La vista previa muestra el texto EFECTIVO de la red de la pestaña activa (el propio si lo tiene; si no, el común); con la pestaña
    // «Texto común» (o sin pestañas) muestra el común. Las funciones de abajo se resuelven al evaluarse, ya declaradas.
    const previewText = computed(() => {
        const tab = form.targets.find((t) => t.account_id === currentTab.value);
        return textOf(tab) || 'Aquí verás el texto de tu publicación.';
    });
    // Primera media con vista previa local (imagen o vídeo recién subidos): { url, kind }.
    const firstPreview = computed(() => {
        const id = form.media.find((m) => previews.value[m]);
        return id === undefined ? null : { url: previews.value[id], kind: mediaById(id)?.kind ?? 'image' };
    });
    const charCount = computed(() => countChars(form.text));

    // Texto por red (C-03). Un destino está VINCULADO si su `text` es `null` y se publica el texto común (la plantilla); si no, tiene
    // texto propio. Texto efectivo = el propio si no está vacío y, si no, el común: es lo que calcula `PostTarget::effectiveText()` en
    // el servidor, que además recorta los espacios y convierte una cadena vacía en `null` (la web no puede guardar un texto propio
    // vacío; el MCP sí). Los contadores y la validación en vivo cuentan SIEMPRE sobre este texto.
    const isUnlinked = (target) => target.text !== null && target.text !== undefined;
    const hasOwnText = (target) => isUnlinked(target) && target.text.trim() !== '';
    const textOf = (target) => (target && hasOwnText(target) ? target.text : form.text);
    const unlinkedCount = computed(() => form.targets.filter(isUnlinked).length);
    // «Editar por red»: abre las pestañas (Texto común y una por red). Se abre sola si algún destino ya tiene texto propio.
    const byNetwork = ref(form.targets.some(isUnlinked));
    const activeTab = ref('template'); // 'template' o el id de la cuenta de la pestaña activa
    const currentTab = computed(() => (byNetwork.value && form.targets.some((t) => t.account_id === activeTab.value) ? activeTab.value : 'template'));
    const unlink = (target) => { target.text = form.text; }; // el texto común sirve de punto de partida
    const relink = (target) => { target.text = null; };
    const openTargetText = (accountId) => { byNetwork.value = true; activeTab.value = accountId; };
    const charsOf = (target) => countChars(textOf(target));
    const hashtagsOf = (target) => countHashtags(textOf(target));
    const accountName = (id) => props.accounts.find((a) => a.id === id)?.name ?? 'Cuenta eliminada';

    // Textos guardados (C-01a) y «campo activo»: el último textarea del compositor que tuvo el foco (texto común, texto propio de una
    // red o, más adelante, el primer comentario). Cada campo se registra aquí, no en cada componente; `insertIntoActive` inserta en SU cursor.
    const savedTexts = computed(() => props.savedTexts ?? []);
    const activeField = shallowRef(null); // { insertAtCursor(text) }
    const registerField = (field) => { activeField.value = field; };
    const unregisterField = (field) => { if (activeField.value === field) activeField.value = null; };
    const insertIntoActive = (text) => {
        if (!activeField.value) return false;
        activeField.value.insertAtCursor(text);
        return true;
    };

    const api = {
        post, brand, accounts, contentTypes, limits, editable, form,
        mediaItems, previews, onUploaded, removeMedia, move, mediaById,
        targetFor, targetIndex, typesOf, typeLabel, typeInfo, toggleAccount,
        liveErrors, errorsFor, errorList, hasBlockingErrors, scheduleBlocked, dateErrorList, uploadingMedia, submit, publishNow, sendingNow, remove,
        NOTES_MAX, savingNotes, notesError, notesSaved, saveNotes,
        libraryName, savingToLibrary, libraryErrors, saveToLibrary,
        selectedNetwork, previewText, firstPreview, charCount, accountName,
        textOf, charsOf, hashtagsOf,
        savedTexts, activeField, registerField, unregisterField, insertIntoActive,
        isUnlinked, hasOwnText, unlinkedCount, byNetwork, activeTab, currentTab, unlink, relink, openTargetText,
    };
    provide(KEY, api);

    return api;
}

export function useComposerContext() {
    return inject(KEY);
}
