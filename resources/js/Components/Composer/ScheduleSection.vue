<script setup>
import { computed, onMounted, ref } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import DateTimePicker from '@/Components/Composer/DateTimePicker.vue';
import { useComposerContext } from '@/Composables/useComposer';

const { brand, form, accounts, editable, toggleAccount } = useComposerContext();

// Respaldo: `?selector=nativo` pinta el `datetime-local` del navegador en lugar del selector propio.
const native = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('selector') === 'nativo';

/**
 * «Escribe cuándo» (C-04c, D6): una frase («el viernes a las 18 en Instagram y Bluesky») se traduce con la IA a UNA fecha en la zona de la
 * marca y unas redes. La propuesta se MUESTRA y solo se aplica al pulsar «Aplicar»; «Descartar» la tira. Es autónomo como `AiSuggest`: consulta
 * `ai.status` al montarse y no pinta nada si el asistente no está configurado. La frase se envía redactada (sin emails, teléfonos ni enlaces).
 */
const enabled = ref(false);
const remaining = ref(0);
const phrase = ref('');
const loading = ref(false);
const error = ref('');
const proposal = ref(null);
const applied = ref('');

const csrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
const call = (method, url, body) =>
    fetch(url, {
        method,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrf() },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });

onMounted(async () => {
    try {
        const res = await call('GET', route('ai.status'));
        if (res.ok) {
            const data = await res.json();
            enabled.value = !!data.enabled;
            remaining.value = data.remaining ?? 0;
        }
    } catch {
        enabled.value = false;
    }
});

const propose = async () => {
    error.value = '';
    applied.value = '';
    proposal.value = null;
    loading.value = true;
    try {
        const res = await call('POST', route('ai.schedule'), { phrase: phrase.value, brand_id: brand.value.id });
        const data = await res.json().catch(() => ({}));
        if (res.ok) proposal.value = data.proposal;
        else error.value = data.message ?? data.errors?.phrase?.[0] ?? 'No se pudo interpretar la fecha.';
        remaining.value = data.remaining ?? remaining.value;
    } catch {
        error.value = 'No se pudo contactar con el asistente.';
    } finally {
        loading.value = false;
    }
};

// Cuentas de la marca que se marcarán al aplicar: las activas de las redes propuestas y disponibles en esta instalación.
const accountsOf = (network) => accounts.value.filter((a) => a.network === network && a.status === 'activa' && a.available !== false);
const networkLabel = (network) => accounts.value.find((a) => a.network === network)?.network_label ?? network;
const networksText = computed(() => (proposal.value?.networks ?? []).map(networkLabel).join(', '));
const ignoredText = computed(() => (proposal.value?.ignored_networks ?? []).map(networkLabel).join(', '));

const apply = () => {
    const p = proposal.value;
    if (!p) return;
    form.scheduled_at = p.scheduled_at;
    p.networks.forEach((n) => accountsOf(n).forEach((a) => toggleAccount(a, true)));
    applied.value = `Aplicado: ${p.label}${p.networks.length ? ` · ${networksText.value}` : ''}.`;
    proposal.value = null;
    phrase.value = '';
};
const discard = () => {
    proposal.value = null;
    applied.value = '';
};
const onEnter = (e) => {
    e.preventDefault(); // Enter propone; nunca envía el formulario del compositor
    if (!loading.value && phrase.value.trim() !== '') propose();
};
</script>

<template>
    <!-- Fecha -->
    <section class="card p-4">
        <InputLabel for="scheduled_at" :value="`Fecha y hora (${brand.timezone})`" />
        <DateTimePicker id="scheduled_at" v-model="form.scheduled_at" :timezone="brand.timezone" :disabled="!editable" :native="native" />

        <div v-if="enabled && editable" class="mt-4 border-t border-slate-100 pt-3" data-testid="nl-schedule">
            <label for="nl-when" class="block text-sm font-medium text-slate-800">Escribe cuándo</label>
            <p id="nl-when-help" class="text-xs text-slate-600">Por ejemplo «el viernes a las 18 en Instagram y Bluesky». Lo propone la IA y tú confirmas; no se programa nada solo.</p>
            <div class="mt-1 flex flex-col gap-2 sm:flex-row">
                <input
                    id="nl-when"
                    v-model="phrase"
                    type="text"
                    maxlength="200"
                    class="field min-w-0 flex-1"
                    autocomplete="off"
                    aria-describedby="nl-when-help"
                    data-testid="nl-input"
                    @keydown.enter="onEnter"
                />
                <button type="button" class="btn btn-secondary" :disabled="loading || phrase.trim() === ''" data-testid="nl-propose" @click="propose">{{ loading ? 'Pensando…' : 'Proponer fecha' }}</button>
            </div>
            <p class="mt-1 text-xs text-slate-600">Te quedan {{ remaining }} usos de IA hoy (compartidos con «Sugerir texto»).</p>

            <p v-if="error" class="mt-2 text-sm text-rose-700" role="alert" data-testid="nl-error">{{ error }}</p>

            <div v-if="proposal" class="mt-3 rounded-lg border border-indigo-200 bg-indigo-50 p-3" role="group" aria-label="Propuesta de la IA" data-testid="nl-proposal">
                <p class="text-sm font-semibold text-slate-900">Propuesta</p>
                <p class="mt-1 text-sm text-slate-900" data-testid="nl-proposal-date">{{ proposal.label }} <span class="text-slate-600">({{ proposal.timezone }})</span></p>
                <p class="text-sm text-slate-900" data-testid="nl-proposal-networks">
                    <template v-if="proposal.networks.length">Redes: {{ networksText }}.</template>
                    <template v-else>No has mencionado ninguna red conectada: elige las redes a mano.</template>
                </p>
                <p v-if="ignoredText" class="text-xs text-amber-800" data-testid="nl-proposal-ignored">No se aplican porque la marca no las tiene conectadas: {{ ignoredText }}.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="btn btn-primary" data-testid="nl-apply" @click="apply">Aplicar</button>
                    <button type="button" class="btn btn-secondary" data-testid="nl-discard" @click="discard">Descartar</button>
                </div>
            </div>

            <p v-if="applied" class="mt-2 text-sm text-emerald-800" role="status" data-testid="nl-applied">{{ applied }}</p>
        </div>
    </section>
</template>
