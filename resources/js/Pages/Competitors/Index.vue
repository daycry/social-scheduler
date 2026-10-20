<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Competidores de Instagram (PA-08): seguidores y publicaciones de otras cuentas profesionales (Business Discovery). */
const props = defineProps({
    brand: Object,
    has_instagram: Boolean,
    comparison: Object,
    max: Number,
    flash: Object,
});

const form = useForm({ brand_id: props.brand?.id ?? '', username: '' });
const submit = () => form.post(route('competitors.store'), { preserveScroll: true, onSuccess: () => form.reset('username') });
const remove = (c) => {
    if (confirm(`¿Dejar de seguir a @${c.username}?`)) router.delete(route('competitors.destroy', c.id), { preserveScroll: true });
};

const rows = computed(() => {
    const own = props.comparison?.own ? [{ ...props.comparison.own, own: true, id: 'own' }] : [];
    return [...own, ...(props.comparison?.competitors ?? [])];
});

const n = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('es-ES').format(v));
const delta = (v) => (v === null || v === undefined ? '—' : `${v > 0 ? '+' : ''}${new Intl.NumberFormat('es-ES').format(v)}`);
const maxFollowers = computed(() => Math.max(1, ...rows.value.map((r) => r.followers ?? 0)));
</script>

<template>
    <Head title="Competidores" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Competidores de Instagram</h2>
        </template>

        <div class="space-y-5">
            <p v-if="flash?.success" class="text-sm text-emerald-700" role="status">{{ flash.success }}</p>
            <div v-if="!brand" class="card p-6 text-sm text-slate-700">Crea una marca para comparar tu cuenta con otras.</div>
            <div v-else-if="!has_instagram" class="card p-6 text-sm text-slate-700">Conecta una cuenta de Instagram profesional en esta marca para comparar con otras cuentas.</div>

            <template v-else>
                <form class="card space-y-3 p-5" @submit.prevent="submit">
                    <label class="block text-sm">
                        <span class="block text-xs font-medium text-slate-500">Usuario de Instagram (cuenta Business o Creator)</span>
                        <input v-model="form.username" type="text" maxlength="31" required placeholder="@usuario" class="field max-w-xs" />
                    </label>
                    <InputError :message="form.errors.username || form.errors.brand_id" />
                    <PrimaryButton :disabled="form.processing">Seguir cuenta</PrimaryButton>
                    <p class="text-xs text-slate-500">Hasta {{ max }} cuentas por marca. Solo se leen datos públicos: seguidores y número de publicaciones, una vez al día.</p>
                </form>

                <section class="card overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Comparativa de seguidores y publicaciones</caption>
                        <thead class="border-b text-xs text-slate-500">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">Cuenta</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Seguidores</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">7 días</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">30 días</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Publicaciones</th>
                                <th scope="col" class="px-3 py-3"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-if="rows.length === 0">
                                <td colspan="6" class="px-5 py-4 text-slate-500">Aún no sigues a ninguna cuenta.</td>
                            </tr>
                            <tr v-for="r in rows" :key="r.id">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900">@{{ r.username }} <span v-if="r.own" class="text-xs font-normal text-indigo-600">(tú)</span></p>
                                    <div class="mt-1 h-1.5 w-40 rounded bg-slate-100" aria-hidden="true">
                                        <div class="h-1.5 rounded bg-indigo-500" :style="{ width: `${((r.followers ?? 0) / maxFollowers) * 100}%` }"></div>
                                    </div>
                                    <p v-if="r.error" class="mt-1 text-xs text-red-600">{{ r.error }}</p>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ n(r.followers) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ delta(r.growth_7d) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ delta(r.growth_30d) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ n(r.media) }}</td>
                                <td class="px-3 py-3 text-right">
                                    <button v-if="!r.own" type="button" class="text-xs text-red-600 underline" @click="remove(r)">Dejar de seguir</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
