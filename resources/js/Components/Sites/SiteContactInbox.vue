<script setup>
import { useConfirm } from '@/Composables/useConfirm';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    siteId: { type: Number, required: true },
    messages: { type: Array, required: true },
    messagesMeta: { type: Object, required: true },
});
const ask = useConfirm();
const goMessages = (n) => router.get(route('sites.edit', props.siteId), { messages_page: n }, { preserveScroll: true, preserveState: true, only: ['messages', 'messages_meta'] });
const clearMessages = async (onlyRead) => {
    const target = route('sites.messages.clear', props.siteId);
    if (await ask({ message: onlyRead ? '¿Borrar los mensajes ya leídos?' : '¿Borrar TODOS los mensajes? No se puede deshacer.', confirmLabel: 'Borrar mensajes' })) router.delete(target, { data: { only_read: onlyRead }, preserveScroll: true });
};
const removeMessage = async (m) => {
    const target = route('sites.messages.destroy', [props.siteId, m.id]);
    if (await ask({ message: '¿Eliminar este mensaje?', confirmLabel: 'Eliminar mensaje' })) router.delete(target, { preserveScroll: true });
};
</script>

<template>
    <section v-if="messagesMeta.total" class="card space-y-2 p-4 text-sm" aria-labelledby="s-mensajes">
        <h2 id="s-mensajes" class="text-base font-semibold text-slate-900"
            >Mensajes del formulario ({{ messagesMeta.total }})</h2
        >
        <p class="text-xs text-slate-600"
            >Se guardan los últimos 500; al llegar al tope se descartan los más antiguos. {{ messagesMeta.unread }} sin
            leer.</p
        >
        <div class="flex flex-wrap gap-3 text-xs">
            <button
                type="button"
                class="font-medium text-red-700 underline"
                data-testid="messages-clear-read"
                @click="clearMessages(true)"
                >Borrar leídos</button
            >
            <button
                type="button"
                class="font-medium text-red-700 underline"
                data-testid="messages-clear-all"
                @click="clearMessages(false)"
                >Borrar todos</button
            >
        </div>
        <ul class="divide-y" data-testid="messages">
            <li v-for="m in messages" :key="m.id" class="py-2">
                <p class="font-medium"
                    >{{ m.name }} · <span class="break-all text-xs font-normal">{{ m.email }}</span>&#32;<span
                        v-if="m.unread"
                        class="rounded bg-amber-100 px-1 text-xs"
                        >Nuevo</span
                    ></p
                >
                <p class="whitespace-pre-line">{{ m.message }}</p>
                <button
                    type="button"
                    class="text-xs font-medium text-red-700 underline"
                    :aria-label="`Eliminar el mensaje de ${m.name}`"
                    @click="removeMessage(m)"
                    >Eliminar</button
                >
            </li>
        </ul>
        <nav
            v-if="messagesMeta.last > 1"
            class="flex items-center justify-between text-xs"
            aria-label="Paginación de mensajes"
        >
            <button
                type="button"
                class="btn btn-secondary"
                :disabled="messagesMeta.page <= 1"
                @click="goMessages(messagesMeta.page - 1)"
                >Anteriores</button
            >
            <span>Página {{ messagesMeta.page }} de {{ messagesMeta.last }}</span>
            <button
                type="button"
                class="btn btn-secondary"
                :disabled="messagesMeta.page >= messagesMeta.last"
                @click="goMessages(messagesMeta.page + 1)"
                >Siguientes</button
            >
        </nav>
    </section>
</template>
