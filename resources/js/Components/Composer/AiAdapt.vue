<script setup>
import { computed, ref, useId } from 'vue';
import AiNotConfigured from '@/Components/AiNotConfigured.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { useAiStatus } from '@/Composables/useAiStatus';
import { useComposerContext } from '@/Composables/useComposer';
import { formatThousands } from '@/Composables/textCount';
import { countText } from '@/Composables/textLength';
import { HttpError } from '@/lib/http';

/**
 * «Adaptar a cada red» (T-82): la IA reescribe el texto común para cada destino seleccionado en UNA petición (una unidad de la cuota
 * diaria compartida con «Sugerir texto»). El servidor valida cada versión contra la regla de texto de su tipo y rechaza la que no cabe
 * (con su motivo). El resultado se MUESTRA por red y solo se aplica al pulsar «Aplicar» (texto propio de cada red, como «Editar por red»);
 * «Descartar» lo tira. Sin IA configurada el botón queda inactivo y explica por qué.
 */
const { brand, form, accounts, limits, applyTargetTexts } = useComposerContext();
const { enabled, loaded, remaining, call } = useAiStatus();

const hintId = useId();
const loading = ref(false);
const error = ref('');
const results = ref(null);
const applied = ref('');

const canAdapt = computed(() => form.text.trim() !== '' && form.targets.length > 0);

const adapt = async () => {
    error.value = '';
    applied.value = '';
    results.value = null;
    loading.value = true;
    // Se recuerda el tipo de contenido con el que se pidió cada destino (ANTES de la petición): si cambia durante ella o antes de aplicar, esa versión ya no vale.
    const types = new Map(form.targets.map((t) => [t.account_id, t.content_type]));
    try {
        const data = await call(
            'ai.adapt',
            {
                brand_id: brand.value.id,
                text: form.text,
                targets: form.targets.map((t) => ({ account_id: t.account_id, content_type: t.content_type })),
            },
            { errorMessage: (data) => data?.message ?? data?.errors?.text?.[0] ?? 'No se pudo adaptar el texto.' },
        );
        results.value = (data?.results ?? []).map((r) => ({ ...r, contentType: types.get(r.account_id) }));
    } catch (failure) {
        error.value = failure instanceof HttpError ? failure.message : 'No se pudo contactar con el asistente.';
    } finally {
        loading.value = false;
    }
};

const rows = computed(() => (results.value ?? []).map((r) => {
    const account = accounts.value.find((a) => a.id === r.account_id);
    const target = form.targets.find((t) => t.account_id === r.account_id);
    const limit = limits.value?.[target?.content_type] ?? {};
    return {
        ...r,
        stale: target?.content_type !== r.contentType,
        network: account?.network,
        label: account ? `${account.network_label} · ${account.name}` : 'Cuenta eliminada',
        chars: r.text ? countText(r.text, limit.count, account?.limits?.url_length) : 0,
        max: account?.limits?.max_chars ?? limit.max_chars ?? null,
    };
}));
const accepted = computed(() => rows.value.filter((r) => r.text && !r.stale));

const apply = () => {
    applyTargetTexts(accepted.value);
    applied.value = `Aplicado a ${accepted.value.length} ${accepted.value.length === 1 ? 'red' : 'redes'}. Puedes editar cada texto en su pestaña.`;
    results.value = null;
};
const discard = () => {
    results.value = null;
    applied.value = '';
};
</script>

<template>
    <div class="min-w-0" data-testid="ai-adapt">
        <button
            type="button"
            class="btn btn-secondary"
            :disabled="!enabled || !canAdapt || loading"
            :aria-describedby="loaded ? hintId : undefined"
            data-testid="ai-adapt-run"
            @click="adapt"
            >{{ loading ? 'Adaptando…' : 'Adaptar a cada red' }}</button
        >
        <AiNotConfigured
            v-if="loaded && !enabled"
            :id="hintId"
            class="mt-1 max-w-sm text-xs"
            data-testid="ai-adapt-unconfigured"
        />
        <p v-else-if="loaded && !canAdapt" :id="hintId" class="mt-1 max-w-xs text-xs text-slate-600"
            >Escribe el texto común y elige al menos una red para adaptarlo.</p
        >
        <p v-else-if="loaded" :id="hintId" class="mt-1 max-w-xs text-xs text-slate-600"
            >La IA propone una versión por red y tú confirmas. Te quedan {{ remaining }} usos hoy.</p
        >
        <p v-if="error" class="mt-1 text-sm text-rose-700" role="alert" data-testid="ai-adapt-error">{{ error }}</p>
        <p
            v-if="applied"
            class="mt-1 text-sm text-emerald-800"
            role="status"
            data-testid="ai-adapt-applied"
            >{{ applied }}</p
        >
    </div>

    <div
        v-if="results"
        class="w-full rounded-lg border border-indigo-200 bg-indigo-50 p-3"
        role="group"
        aria-label="Versiones propuestas por la IA"
        data-testid="ai-adapt-proposal"
    >
        <p class="text-sm font-semibold text-slate-900">Versiones propuestas</p>
        <ul class="mt-2 space-y-2">
            <li
                v-for="r in rows"
                :key="r.account_id"
                class="min-w-0 rounded border border-indigo-100 bg-white p-2 text-sm"
                data-testid="ai-adapt-version"
            >
                <p class="flex min-w-0 items-center gap-1.5 text-xs font-medium text-slate-900">
                    <NetworkIcon v-if="r.network" :network="r.network" size="sm" />
                    <span class="truncate">{{ r.label }}</span>
                    <span
                        v-if="r.text && !r.stale"
                        class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-slate-700"
                        data-testid="ai-adapt-count"
                        >{{ formatThousands(r.chars)
                        }}<template v-if="r.max != null"> / {{ formatThousands(r.max) }}</template></span
                    >
                </p>
                <p v-if="r.stale" class="mt-1 text-xs text-amber-800" data-testid="ai-adapt-stale"
                    >El tipo de contenido de esta red ha cambiado: vuelve a adaptar el texto para ella.</p
                >
                <p v-else-if="r.text" class="mt-1 whitespace-pre-line break-words text-slate-900">{{ r.text }}</p>
                <p v-else class="mt-1 text-xs text-amber-800" data-testid="ai-adapt-rejected"
                    >No se propone texto para esta red: {{ r.error }}</p
                >
            </li>
        </ul>
        <div class="mt-3 flex flex-wrap gap-2">
            <button
                type="button"
                class="btn btn-primary"
                :disabled="!accepted.length"
                data-testid="ai-adapt-apply"
                @click="apply"
                >Aplicar</button
            >
            <button type="button" class="btn btn-secondary" data-testid="ai-adapt-discard" @click="discard"
                >Descartar</button
            >
        </div>
    </div>
</template>
