<script setup>
import FormField from '@/Components/FormField.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { router, useForm } from '@inertiajs/vue3';

/**
 * Acortador propio (T-89): enlaces `/s/{código}` que redirigen al destino y cuentan clics agregados por día (UTC), sin IP ni datos
 * personales. Un enlace desactivado deja de redirigir y muestra una página de aviso.
 */
defineProps({
    links: { type: Array, default: () => [] },
});

const ask = useConfirm();
const form = useForm({ url: '', label: '' });
const submit = () => form.post(route('shortlinks.store'), { preserveScroll: true, onSuccess: () => form.reset() });
const setActive = (link, active) => router.patch(route('shortlinks.update', link.id), { active }, { preserveScroll: true });
const remove = async (link) => {
    const target = route('shortlinks.destroy', link.id);
    if (await ask({ message: `¿Eliminar el enlace corto ${link.code}? Dejará de funcionar y se borrarán sus clics.`, confirmLabel: 'Eliminar enlace' })) {
        router.delete(target, { preserveScroll: true });
    }
};
</script>

<template>
    <section class="card space-y-4 p-5 text-sm" data-testid="short-links" aria-labelledby="short-links-title">
        <div>
            <h2 id="short-links-title" class="text-base font-semibold text-slate-900">Enlaces cortos</h2>
            <p class="mt-1 text-xs text-slate-600"
                >Acorta una dirección y cuenta cuántas personas la abren. Solo se guarda el total de clics de cada día
                (UTC), sin IP ni datos personales.</p
            >
        </div>

        <form class="grid items-start gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]" @submit.prevent="submit">
            <div>
                <FormField id="short-url" :error="form.errors.url" v-slot="{ control }">
                    <label for="short-url" class="block text-xs font-medium text-slate-600">Dirección de destino</label>
                    <input
                        v-bind="control"
                        v-model="form.url"
                        type="url"
                        inputmode="url"
                        autocomplete="off"
                        required
                        class="field mt-1"
                        placeholder="https://ejemplo.com/oferta"
                    />
                </FormField>
            </div>
            <div>
                <FormField id="short-label" :error="form.errors.label" v-slot="{ control }">
                    <label for="short-label" class="block text-xs font-medium text-slate-600">Nombre (opcional)</label>
                    <input
                        v-bind="control"
                        v-model="form.label"
                        type="text"
                        maxlength="100"
                        autocomplete="off"
                        class="field mt-1"
                        placeholder="Campaña de otoño"
                    />
                </FormField>
            </div>
            <PrimaryButton class="sm:mt-5" :disabled="form.processing">Acortar</PrimaryButton>
        </form>

        <p v-if="!links.length" class="text-xs text-slate-600" data-testid="short-links-empty"
            >Todavía no has acortado ningún enlace.</p
        >
        <ul v-else class="divide-y">
            <li
                v-for="link in links"
                :key="link.id"
                class="space-y-2 py-3"
                :data-testid="`short-link-${link.code}`"
                :aria-label="`Enlace corto ${link.code}`"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="break-all font-medium text-slate-900">
                            <a
                                :href="link.short_url"
                                target="_blank"
                                rel="noopener"
                                class="link"
                                >{{ link.short_url }}</a
                            >
                            <span
                                v-if="!link.active"
                                class="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-700"
                                >Desactivado</span
                            >
                        </p>
                        <p v-if="link.label" class="text-xs text-slate-700">{{ link.label }}</p>
                        <p class="break-all text-xs text-slate-600">→ {{ link.url }}</p>
                    </div>
                    <dl class="flex gap-4 text-right">
                        <div
                            ><dt class="text-xs text-slate-600">Hoy</dt
                            ><dd
                                class="font-semibold tabular-nums"
                                data-testid="short-link-today"
                                >{{ link.today }}</dd
                            ></div
                        >
                        <div
                            ><dt class="text-xs text-slate-600">7 días</dt
                            ><dd class="font-semibold tabular-nums">{{ link.last_7_days }}</dd></div
                        >
                        <div
                            ><dt class="text-xs text-slate-600">Total</dt
                            ><dd
                                class="font-semibold tabular-nums"
                                data-testid="short-link-total"
                                >{{ link.total }}</dd
                            ></div
                        >
                    </dl>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-xs">
                    <button
                        type="button"
                        class="font-medium underline"
                        :aria-label="`${link.active ? 'Desactivar' : 'Reactivar'} el enlace ${link.code}`"
                        @click="setActive(link, !link.active)"
                        >{{ link.active ? 'Desactivar' : 'Reactivar' }}</button
                    >
                    <button
                        type="button"
                        class="font-medium text-red-700 underline"
                        :aria-label="`Eliminar el enlace ${link.code}`"
                        @click="remove(link)"
                        >Eliminar</button
                    >
                </div>
                <details v-if="link.daily.length" class="text-xs text-slate-700">
                    <summary class="cursor-pointer font-medium">Clics por día (UTC)</summary>
                    <ul class="mt-1 grid gap-x-6 sm:grid-cols-2">
                        <li v-for="d in link.daily" :key="d.date" class="flex justify-between tabular-nums">
                            <span>{{ d.date }}</span>
                            <span>{{ d.clicks }}</span>
                        </li>
                    </ul>
                </details>
            </li>
        </ul>
    </section>
</template>
