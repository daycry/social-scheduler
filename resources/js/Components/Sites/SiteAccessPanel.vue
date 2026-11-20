<script setup>
import FormField from '@/Components/FormField.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps({ site: { type: Object, required: true }, tokens: { type: Array, required: true }, newToken: Object });
const ask = useConfirm();
const MODES = { public: 'Público', password: 'Con contraseña', token: 'Por enlace con token' };
const access = useForm({ access_mode: props.site.access_mode, password: '', noindex: props.site.noindex });
const saveAccess = () => access.put(route('sites.access', props.site.id), { preserveScroll: true, onSuccess: () => access.reset('password') });
const tokenForm = useForm({ label: '', days: 30 });
const createToken = () => tokenForm.post(route('sites.tokens.store', props.site.id), { preserveScroll: true, onSuccess: () => tokenForm.reset('label') });
const revoke = async (t) => {
    const target = route('sites.tokens.destroy', [props.site.id, t.id]);
    if (await ask({ message: `¿Revocar el enlace «${t.label}»?`, confirmLabel: 'Revocar enlace' })) router.delete(target, { preserveScroll: true });
};
const revokeAll = async () => {
    const target = route('sites.revoke-access', props.site.id);
    if (await ask({ message: 'Se cerrarán todas las sesiones de acceso y se revocarán todos los enlaces. ¿Continuar?', confirmLabel: 'Revocar accesos' })) router.post(target, {}, { preserveScroll: true });
};
</script>

<template>
    <section class="card space-y-3 p-4 text-sm" aria-labelledby="s-acceso">
        <h2 id="s-acceso" class="text-base font-semibold text-slate-900">Acceso</h2>
        <form class="space-y-3" @submit.prevent="saveAccess">
            <FormField :error="access.errors.access_mode" v-slot="{ control }">
                <label class="block"
                    ><span class="block text-xs font-medium text-slate-600">Quién puede verlo</span>
                    <select
                        v-bind="control"
                        v-model="access.access_mode"
                        class="field"
                        data-testid="access-mode"
                        ><option v-for="(l, k) in MODES" :key="k" :value="k">{{ l }}</option></select
                    >
                </label>
            </FormField>
            <FormField
                v-if="access.access_mode === 'password'"
                :error="access.errors.password"
                v-slot="{ control }"
            >
                <label class="block"
                    ><span class="block text-xs font-medium text-slate-600"
                        >Contraseña
                        {{ site.has_password ? '(déjala vacía para conservar la actual)' : '' }}</span
                    >

                    <input
                        v-bind="control"
                        v-model="access.password"
                        type="password"
                        autocomplete="new-password"
                        maxlength="128"
                        class="field"
                        data-testid="access-password"
                    />
                </label>
            </FormField>
            <label class="flex items-center gap-2"
                ><input
                    v-model="access.noindex"
                    type="checkbox"
                    class="rounded border-slate-300"
                    data-testid="access-noindex"
                />
                No indexar en buscadores (noindex)</label
            >
            <p class="text-xs text-slate-600"
                >Cambiar el modo o la contraseña cierra las sesiones de acceso ya abiertas. Los sitios
                con contraseña o token nunca se indexan.</p
            >
            <button
                type="submit"
                class="btn btn-secondary"
                :disabled="access.processing"
                data-testid="access-save"
                >Guardar acceso</button
            >
        </form>

        <div v-if="access.access_mode === 'token' || tokens.length" class="space-y-2 border-t pt-3">
            <h3 class="text-sm font-semibold text-slate-900">Enlaces con token</h3>
            <div
                v-if="newToken"
                class="rounded border border-emerald-300 bg-emerald-50 p-2"
                data-testid="new-token"
            >
                <p class="text-xs font-medium"
                    >Enlace «{{ newToken.label }}» (cópialo ahora; no se vuelve a mostrar):</p
                >
                <input
                    :value="newToken.url"
                    readonly
                    class="field mt-1 font-mono text-xs"
                    aria-label="Enlace con token"
                    data-testid="new-token-url"
                />
            </div>
            <form class="grid gap-2 sm:grid-cols-[1fr_6rem_auto]" @submit.prevent="createToken">
                <div class="min-w-0"
                    ><FormField :error="tokenForm.errors.label" v-slot="{ control }">
                        <input
                            v-bind="control"
                            v-model="tokenForm.label"
                            type="text"
                            maxlength="60"
                            required
                            class="field"
                            aria-label="Nombre del enlace"
                            placeholder="Nombre (p. ej. Cliente)"
                            data-testid="token-label"
                        /> </FormField
                ></div>
                <div class="min-w-0"
                    ><FormField :error="tokenForm.errors.days" v-slot="{ control }">
                        <input
                            v-bind="control"
                            v-model.number="tokenForm.days"
                            type="number"
                            min="1"
                            max="365"
                            class="field"
                            aria-label="Días de validez"
                        /> </FormField
                ></div>
                <button
                    type="submit"
                    class="btn btn-secondary"
                    :disabled="tokenForm.processing"
                    data-testid="token-create"
                    >Crear enlace</button
                >
            </form>
            <ul class="divide-y" data-testid="token-list">
                <li
                    v-for="t in tokens"
                    :key="t.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-1"
                >
                    <span
                        >{{ t.label }} ·
                        <span
                            :data-state="t.state"
                            >{{ t.state === 'active' ? 'Activo' : t.state === 'revoked' ? 'Revocado' : 'Caducado' }}</span><span
                            v-if="t.expires_at"
                            class="text-xs text-slate-600"
                        >
                            · caduca {{ t.expires_at.slice(0, 10) }}</span
                        ></span
                    >
                    <button
                        v-if="t.state === 'active'"
                        type="button"
                        class="text-xs font-medium text-red-700 underline"
                        :aria-label="`Revocar el enlace ${t.label}`"
                        @click="revoke(t)"
                        >Revocar</button
                    >
                </li>
            </ul>
        </div>
        <button
            type="button"
            class="text-xs font-medium text-red-700 underline"
            data-testid="revoke-all"
            @click="revokeAll"
            >Revocar todo el acceso</button
        >
    </section>
</template>
