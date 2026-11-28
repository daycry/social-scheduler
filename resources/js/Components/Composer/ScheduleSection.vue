<script setup>
import { useAiStatus } from '@/Composables/useAiStatus';
import { HttpError } from '@/lib/http';
import { computed, ref } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import DateTimePicker from '@/Components/Composer/DateTimePicker.vue';
import BestTimeSuggestion from '@/Components/BestTimeSuggestion.vue';
import { useComposerContext } from '@/Composables/useComposer';

const { brand, form, accounts, editable, toggleAccount } = useComposerContext();

const bestTimeNetworks = computed(() => {
    const usable = accounts.value.filter((account) => account.status === 'activa' && account.available !== false);
    const selected = usable.filter((account) => form.targets.some((target) => target.account_id === account.id));
    return [...new Map((selected.length ? selected : usable).map((account) => [
        account.network, { value: account.network, label: account.network_label ?? account.network },
    ])).values()];
});
/** @param {import('@/types/best-time').AvailableBestTimeSuggestion} value */
const applyBestTime = (value) => {
    form.scheduled_at = value.scheduled_at;
};

// Respaldo: `?selector=nativo` pinta el `datetime-local` del navegador en lugar del selector propio.
const native = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('selector') === 'nativo';

/**
 * «Escribe cuándo» (C-04c, D6): una frase («el viernes a las 18 en Instagram y Bluesky») se traduce con la IA a UNA fecha en la zona de la
 * marca y unas redes. La propuesta se MUESTRA y solo se aplica al pulsar «Aplicar»; «Descartar» la tira. Comparte el estado y la cuota con `AiSuggest`. La frase se envía redactada (sin correos, teléfonos ni enlaces).
 */
const { enabled, remaining, call } = useAiStatus();
const phrase = ref('');
const loading = ref(false);
const error = ref('');
const proposal = ref(null);
const applied = ref('');

const propose = async () => {
    error.value = '';
    applied.value = '';
    proposal.value = null;
    loading.value = true;
    try {
        const data = await call('ai.schedule', { phrase: phrase.value, brand_id: brand.value.id }, {
            errorMessage: data => data?.message ?? data?.errors?.phrase?.[0] ?? 'No se pudo interpretar la fecha.',
        });
        proposal.value = data?.proposal ?? null;
    } catch (failure) {
        if (failure instanceof HttpError) {
            error.value = failure.message;
        } else {
            error.value = 'No se pudo contactar con el asistente.';
        }
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
        <DateTimePicker
            id="scheduled_at"
            v-model="form.scheduled_at"
            :timezone="brand.timezone"
            :disabled="!editable"
            :native="native"
        />
        <BestTimeSuggestion
            v-if="editable"
            class="mt-3"
            :brand="brand"
            :networks="bestTimeNetworks"
            :value="form.scheduled_at"
            :disabled="form.processing"
            @apply="applyBestTime"
        />

        <div v-if="enabled && editable" class="mt-4 border-t border-border-subtle pt-3" data-testid="nl-schedule">
            <label for="nl-when" class="block text-sm font-medium text-foreground">Escribe cuándo</label>
            <p id="nl-when-help" class="text-xs text-foreground-muted"
                >Por ejemplo «el viernes a las 18 en Instagram y Bluesky». Lo propone la IA y tú confirmas; no se
                programa nada solo.</p
            >
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
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="loading || phrase.trim() === ''"
                    data-testid="nl-propose"
                    @click="propose"
                    >{{ loading ? 'Pensando…' : 'Proponer fecha' }}</button
                >
            </div>
            <p class="mt-1 text-xs text-foreground-muted"
                >Te quedan {{ remaining }} usos de IA hoy (compartidos con «Sugerir texto»).</p
            >

            <p v-if="error" class="mt-2 text-sm text-danger" role="alert" data-testid="nl-error">{{ error }}</p>

            <div
                v-if="proposal"
                class="mt-3 rounded-lg border border-accent-marker bg-accent-soft p-3"
                role="group"
                aria-label="Propuesta de la IA"
                data-testid="nl-proposal"
            >
                <p class="text-sm font-semibold text-foreground-strong">Propuesta</p>
                <p class="mt-1 text-sm text-foreground-strong" data-testid="nl-proposal-date"
                    >{{ proposal.label }} <span class="text-foreground-muted">({{ proposal.timezone }})</span></p
                >
                <p class="text-sm text-foreground-strong" data-testid="nl-proposal-networks">
                    <template v-if="proposal.networks.length">Redes: {{ networksText }}.</template>
                    <template v-else>No has mencionado ninguna red conectada: elige las redes a mano.</template>
                </p>
                <p v-if="ignoredText" class="text-xs text-warning-ink" data-testid="nl-proposal-ignored"
                    >No se aplican porque la marca no las tiene conectadas: {{ ignoredText }}.</p
                >
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="btn btn-primary" data-testid="nl-apply" @click="apply">Aplicar</button>
                    <button type="button" class="btn btn-secondary" data-testid="nl-discard" @click="discard"
                        >Descartar</button
                    >
                </div>
            </div>

            <p
                v-if="applied"
                class="mt-2 text-sm text-success-ink"
                role="status"
                data-testid="nl-applied"
                >{{ applied }}</p
            >
        </div>
    </section>
</template>
