import { ref, shallowRef } from 'vue';
import { createChartResources } from '../chart-resources.js';

export function useChartFlow(
    { brandId, designId, pageId, selection, busy, ready, run, isDisposed, getAdapter, refreshLayers },
    { createResources = createChartResources } = {},
) {
    const datasetsOpen = ref(false);
    const chartsOpen = ref(false);
    const chartResources = shallowRef(null);
    const chartDraft = ref(null);
    const chartSource = ref(null);
    let chartTarget = null;
    function editChart() {
        if (selection.value.length !== 1 || selection.value[0].type !== 'chart' || busy.value) return;
        const item = selection.value[0];
        chartTarget = { id: item.id, pageId: pageId.value, brandId: brandId(), designId: designId() };
        chartDraft.value = JSON.parse(JSON.stringify(item.chart));
        chartSource.value = null;
        chartsOpen.value = true;
    }
    function chooseChartData(draft = null) {
        if (draft) chartDraft.value = JSON.parse(JSON.stringify(draft));
        chartsOpen.value = false;
        datasetsOpen.value = true;
    }
    function startChart() {
        chartTarget = null;
        chartDraft.value = null;
        chartSource.value = null;
        chooseChartData();
    }
    function selectChartData(source) {
        chartSource.value = source;
        if (chartDraft.value)
            chartDraft.value = {
                ...chartDraft.value,
                binding: { ...chartDraft.value.binding, dataset: { ...source.dataset } },
            };
        datasetsOpen.value = false;
        chartsOpen.value = true;
    }
    async function applyChart(chart) {
        const target = chartTarget,
            resources = chartResources.value;
        return run(async () => {
            if (isDisposed() || busy.value || !ready.value || !resources) return;
            if (
                target &&
                (target.brandId !== brandId() ||
                    target.designId !== designId() ||
                    target.pageId !== pageId.value ||
                    selection.value.length !== 1 ||
                    selection.value[0].id !== target.id)
            )
                throw new Error('La selección cambió. Abre de nuevo el gráfico para editarlo.');
            busy.value = true;
            try {
                if (target) await getAdapter().updateSelection({ chart });
                else await getAdapter().addChart(chart);
                refreshLayers();
            } finally {
                busy.value = false;
            }
        });
    }

    function initChartResources() {
        disposeChartResources();
        chartResources.value = createResources({ brandId: brandId() });
    }

    function disposeChartResources() {
        chartResources.value?.dispose();
        chartResources.value = null;
    }

    function resetChartFlow() {
        chartsOpen.value = false;
        datasetsOpen.value = false;
        chartTarget = null;
        chartSource.value = null;
        chartDraft.value = null;
    }

    return {
        datasetsOpen,
        chartsOpen,
        chartResources,
        chartDraft,
        chartSource,
        editChart,
        chooseChartData,
        startChart,
        selectChartData,
        applyChart,
        initChartResources,
        disposeChartResources,
        resetChartFlow,
    };
}
