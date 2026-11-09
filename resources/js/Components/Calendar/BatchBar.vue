<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { newIdempotencyKey, workflowError, workflowRequest } from '@/Composables/useWorkflow';

/**
 * Acciones masivas sobre las publicaciones seleccionadas de la lista (T-19). Cada elemento se autoriza y se ejecuta aparte: el resultado
 * detalla ok o error de cada uno sin parar el lote. Una clave de idempotencia por intento evita repetir el lote si la respuesta se pierde.
 */
const props = defineProps({ selected: { type: Array, required: true }, items: { type: Array, required: true } });
const emit = defineEmits(['clear']);

const ACTIONS = [
    ['schedule', 'Programar'],
    ['reschedule', 'Reprogramar (desplazar)'],
    ['cancel', 'Cancelar programación'],
    ['approve', 'Aprobar'],
    ['reject', 'Rechazar'],
    ['delete', 'Borrar'],
];
const action = ref('reschedule');
const shiftDays = ref(1);
const shiftHours = ref(0);
const comment = ref('');
const busy = ref(false);
const error = ref('');
const result = ref(null);
// La clave se mantiene mientras el cuerpo no cambie: reenviar el mismo lote repite la respuesta sin ejecutarlo otra vez.
let lastBody = '';
let lastKey = newIdempotencyKey();

const label = computed(() => ACTIONS.find((a) => a[0] === action.value)?.[1]);
const textOf = (id) => props.items.find((i) => i.id === id)?.text || `Publicación ${id}`;

const run = async () => {
    if (busy.value || props.selected.length === 0) return;
    if (action.value === 'delete' && !confirm(`¿Borrar ${props.selected.length} publicaciones? Irán a la papelera.`)) return;
    busy.value = true;
    error.value = '';
    result.value = null;
    const body = {
        action: action.value,
        post_ids: [...props.selected],
        ...(action.value === 'reschedule' ? { shift_days: Number(shiftDays.value) || 0, shift_hours: Number(shiftHours.value) || 0 } : {}),
        ...(action.value === 'reject' ? { comment: comment.value } : {}),
        // Aprobar o rechazar exige la versión que se ve en la lista: si alguien editó el contenido, ese elemento falla con 409.
        ...(['approve', 'reject'].includes(action.value) ? { signatures: Object.fromEntries(props.selected.map((id) => [id, props.items.find((i) => i.id === id)?.approval_signature ?? ''])) } : {}),
    };
    const serialized = JSON.stringify(body);
    if (serialized !== lastBody) {
        lastBody = serialized;
        lastKey = newIdempotencyKey();
    }
    const response = await workflowRequest('POST', route('workflow.batch'), body, { 'Idempotency-Key': lastKey });
    busy.value = false;
    if (!response.ok) {
        error.value = workflowError(response);
        return;
    }
    result.value = response.data;
    router.reload({ preserveScroll: true, only: ['items', 'undated', 'flash'] });
};
</script>

<template>
    <section v-if="selected.length || result" class="card space-y-3 p-3" aria-label="Acciones masivas" data-testid="batch-bar">
        <div class="flex flex-wrap items-end gap-3 text-sm">
            <p class="font-medium text-slate-800" data-testid="batch-count">{{ selected.length }} seleccionadas</p>
            <div>
                <label for="batch-action" class="block text-xs font-medium text-slate-700">Acción</label>
                <select id="batch-action" v-model="action" class="field w-auto py-1.5" data-testid="batch-action">
                    <option v-for="a in ACTIONS" :key="a[0]" :value="a[0]">{{ a[1] }}</option>
                </select>
            </div>
            <template v-if="action === 'reschedule'">
                <div>
                    <label for="batch-days" class="block text-xs font-medium text-slate-700">Días</label>
                    <input id="batch-days" v-model="shiftDays" type="number" min="-365" max="365" class="field w-20 py-1.5" data-testid="batch-days" />
                </div>
                <div>
                    <label for="batch-hours" class="block text-xs font-medium text-slate-700">Horas</label>
                    <input id="batch-hours" v-model="shiftHours" type="number" min="-8760" max="8760" class="field w-20 py-1.5" data-testid="batch-hours" />
                </div>
            </template>
            <div v-if="action === 'reject'">
                <label for="batch-comment" class="block text-xs font-medium text-slate-700">Motivo</label>
                <input id="batch-comment" v-model="comment" type="text" maxlength="2000" class="field w-56 py-1.5" data-testid="batch-comment" />
            </div>
            <button type="button" class="btn btn-primary px-3 py-1.5" :disabled="busy || selected.length === 0" data-testid="batch-run" @click="run">{{ busy ? 'Aplicando…' : `Aplicar: ${label}` }}</button>
            <button type="button" class="btn btn-secondary px-3 py-1.5" @click="emit('clear'); result = null">Limpiar selección</button>
        </div>
        <p v-if="error" class="text-sm text-red-700" role="alert" data-testid="batch-error">{{ error }}</p>
        <div v-if="result" role="status" data-testid="batch-result">
            <p class="text-sm font-medium text-slate-900">
                {{ result.ok }} correctas, {{ result.failed }} con error<span v-if="result.replayed"> (respuesta repetida, no se ha vuelto a ejecutar)</span>.
            </p>
            <ul class="mt-1 space-y-0.5 text-xs">
                <li v-for="r in result.results" :key="r.post_id" :class="r.ok ? 'text-emerald-800' : 'text-red-700'" :data-testid="`batch-item-${r.post_id}`">
                    <span class="font-medium">{{ r.ok ? 'OK' : 'Error' }}</span> · {{ textOf(r.post_id).slice(0, 60) }}<span v-if="!r.ok"> — {{ r.error }}</span>
                </li>
            </ul>
        </div>
    </section>
</template>
