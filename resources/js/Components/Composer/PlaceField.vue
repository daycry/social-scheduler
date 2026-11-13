<script setup>
import { computed, ref } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import { useComposerContext } from '@/Composables/useComposer';
import { PLACE_ID_PATTERN, searchPlaces } from '@/Composables/placeSearch';

// Lugar de una publicación: `location_id` en Instagram y `place` en Facebook (id de la Página del lugar). Busca por nombre con la búsqueda de
// lugares de Meta; si Meta la restringe para esta app, explica el motivo y deja pegar el id a mano.
const props = defineProps({
    account: { type: Object, required: true },
    optionKey: { type: String, required: true },
    label: { type: String, required: true },
});
const { brand, editable, targetFor } = useComposerContext();
const options = computed(() => targetFor(props.account.id).options);
const value = computed(() => options.value[props.optionKey] ?? '');
const query = ref('');
const results = ref([]);
const searching = ref(false);
const searched = ref(false);
const error = ref('');
const chosen = ref(null); // { id, name, detail } del lugar elegido en esta sesión (el post solo guarda el id)
const base = computed(() => `place-${props.optionKey}-${props.account.id}`);
const invalidId = computed(() => value.value !== '' && !PLACE_ID_PATTERN.test(String(value.value)));
const chosenLabel = computed(() => (chosen.value && chosen.value.id === String(value.value) ? chosen.value : null));

async function search() {
    if (!editable.value || searching.value) return;
    searching.value = true;
    error.value = '';
    const outcome = await searchPlaces(brand.value.id, props.account.id, query.value);
    results.value = outcome.places;
    error.value = outcome.error ?? '';
    searched.value = outcome.error === null;
    searching.value = false;
}
function choose(place) {
    options.value[props.optionKey] = place.id;
    chosen.value = place;
    results.value = [];
    searched.value = false;
}
function setManual(event) {
    const text = event.target.value.trim();
    chosen.value = null;
    if (text) options.value[props.optionKey] = text;
    else delete options.value[props.optionKey];
}
function clear() {
    delete options.value[props.optionKey];
    chosen.value = null;
}
</script>

<template>
    <div class="space-y-2" :data-testid="`place-field-${optionKey}`">
        <InputLabel :for="`${base}-query`" :value="label" />
        <div class="flex flex-wrap gap-2">
            <input :id="`${base}-query`" v-model="query" :disabled="!editable" type="search" class="field min-w-0 flex-1 basis-40" placeholder="Busca un lugar por nombre" autocomplete="off" @keydown.enter.prevent.stop="search" />
            <button type="button" class="btn btn-secondary" :disabled="!editable || searching" @click="search">{{ searching ? 'Buscando…' : 'Buscar lugar' }}</button>
        </div>
        <p v-if="error" role="alert" class="rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900" :data-testid="`${base}-error`">{{ error }}</p>
        <p v-else-if="searched && !results.length" role="status" class="text-xs text-slate-600">Sin resultados. Prueba con otro nombre o pega el id del lugar.</p>
        <ul v-if="results.length" class="divide-y divide-slate-100 rounded-xl border border-slate-200" :aria-label="`Resultados de lugares para ${account.network_label}`">
            <li v-for="place in results" :key="place.id">
                <button type="button" class="flex w-full flex-col items-start px-3 py-2 text-left text-sm hover:bg-slate-50" :disabled="!editable" @click="choose(place)">
                    <span class="font-medium text-slate-900">{{ place.name }}</span>
                    <span class="text-xs text-slate-600">{{ place.detail ?? 'Sin dirección' }} · id {{ place.id }}</span>
                </button>
            </li>
        </ul>
        <p v-if="chosenLabel" class="flex flex-wrap items-center gap-2 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-sm text-violet-900" :data-testid="`${base}-chosen`">
            <span>Lugar elegido: <strong>{{ chosenLabel.name }}</strong> (id {{ chosenLabel.id }})</span>
            <button type="button" class="text-xs underline" :disabled="!editable" @click="clear">Quitar</button>
        </p>
        <div>
            <label :for="`${base}-id`" class="block text-xs font-medium text-slate-900">O pega el id del lugar</label>
            <input :id="`${base}-id`" :value="value" :disabled="!editable" type="text" inputmode="numeric" maxlength="20" pattern="[0-9]*" class="field mt-1" :aria-invalid="invalidId" @input="setManual" @keydown.enter.prevent />
            <p v-if="invalidId" role="alert" class="mt-1 text-xs text-red-700">El id debe ser numérico (el de la Página del lugar en Facebook).</p>
            <p v-else class="mt-1 text-xs text-slate-600">Id numérico de la Página de Facebook del lugar. Lo encuentras en la URL o en «Información» de esa página. La búsqueda de Meta puede no estar disponible para esta app: en ese caso pega el id.</p>
        </div>
    </div>
</template>
