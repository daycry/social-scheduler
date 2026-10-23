<script setup>
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Estado vacío de una red sin cuenta conectada: banner «Conecta tu cuenta de {Red}» con el botón que inicia
 * el OAuth (POST existente) y tres tarjetas de valor (FeatureEmpty).
 */
const props = defineProps({
    network: { type: Object, required: true }, // { value, label, provider }
    brand: { type: Object, required: true },
});

const busy = ref(false);
const connect = () => {
    busy.value = true;
    router.post(route('brands.connections.connect', { brand: props.brand.id, provider: props.network.provider }), {}, { onFinish: () => (busy.value = false) });
};

const cards = computed(() => [
    { title: 'Mide tu crecimiento', text: `Seguidores, visualizaciones y alcance de ${props.network.label} en un solo panel, con comparación de periodos.`, kind: 'chart' },
    { title: 'Programa sin salir de aquí', text: `Prepara publicaciones para ${props.network.label}, revisa su estado y reprograma arrastrando en el calendario.`, kind: 'calendar' },
    { title: 'Informes a un clic', text: 'Genera PDF o CSV de la marca con los datos de esta red cuando los necesites.', kind: 'report' },
]);
</script>

<template>
    <div data-testid="connect-network">
        <FeatureEmpty
            :title="`Conecta tu cuenta de ${network.label}`"
            :description="`Autoriza el acceso a la cuenta de ${network.label} de «${brand.name}» para ver sus estadísticas y publicar desde Social Scheduler. Puedes desconectarla cuando quieras.`"
            :network="network.value"
            :cards="cards"
        >
            <template #cta>
                <button type="button" class="btn-primary" :disabled="busy" @click="connect">Conectar {{ network.label }}</button>
            </template>
        </FeatureEmpty>
    </div>
</template>
