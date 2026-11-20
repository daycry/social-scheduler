<script setup>
import { useConfirm } from '@/Composables/useConfirm';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    linkId: { type: Number, default: null },
    count: { type: Number, required: true },
    subscribers: { type: Array, required: true },
});
const ask = useConfirm();
const removeSubscriber = async (s) => {
    const target = route('smartlinks.subscribers.destroy', [props.linkId, s.id]);
    if (await ask({ message: '¿Eliminar este suscriptor?', confirmLabel: 'Eliminar suscriptor' })) router.delete(target, { preserveScroll: true });
};
const clearSubscribers = async () => {
    const target = route('smartlinks.subscribers.clear', props.linkId);
    if (await ask({ message: '¿Borrar TODOS los suscriptores? No se puede deshacer.', confirmLabel: 'Borrar suscriptores' })) router.delete(target, { preserveScroll: true });
};
</script>

<template>
    <section
        v-if="linkId && count > 0"
        class="card space-y-2 p-5 text-sm lg:col-span-2"
        data-testid="subscribers"
        aria-label="Suscriptores"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-slate-900"
                >Suscriptores ({{ count }})</h2
            >
            <span class="flex gap-3">
                <a :href="route('smartlinks.subscribers', linkId)" class="underline">Descargar CSV</a>
                <button
                    type="button"
                    class="font-medium text-red-700 underline"
                    data-testid="subscribers-clear"
                    @click="clearSubscribers"
                    >Borrar todos</button
                >
            </span>
        </div>
        <ul class="divide-y">
            <li
                v-for="s in subscribers"
                :key="s.id"
                class="flex flex-wrap items-center justify-between gap-2 py-1"
            >
                <span class="min-w-0 break-all">{{ s.email }}</span>
                <button
                    type="button"
                    class="text-xs font-medium text-red-700 underline"
                    :aria-label="`Eliminar a ${s.email}`"
                    @click="removeSubscriber(s)"
                    >Eliminar</button
                >
            </li>
        </ul>
        <p v-if="count > subscribers.length" class="text-xs text-slate-600"
            >Se muestran los {{ subscribers.length }} más recientes; el CSV incluye todos.</p
        >
    </section>
</template>
