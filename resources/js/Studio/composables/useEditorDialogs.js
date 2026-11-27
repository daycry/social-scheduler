import { defineAsyncComponent, getCurrentInstance, onErrorCaptured, ref, watch } from 'vue';

const loadExportDialog = () => import('@/Components/Studio/ExportDialog.vue');
const ExportDialog = defineAsyncComponent(loadExportDialog);
const loadBatchExportDialog = () => import('@/Components/Studio/BatchExportDialog.vue');
const BatchExportDialog = defineAsyncComponent(loadBatchExportDialog);
const loadPresentationDialog = () => import('@/Components/Studio/PresentationDialog.vue');
const PresentationDialog = defineAsyncComponent(loadPresentationDialog);
const loadStudioDialog = () => import('@/Components/Studio/StudioDialog.vue');
const StudioDialog = defineAsyncComponent(loadStudioDialog);
const loadStudioCollabPanel = () => import('@/Components/Studio/StudioCollabPanel.vue');
const StudioCollabPanel = defineAsyncComponent(loadStudioCollabPanel);
const loadVariantsDialog = () => import('@/Components/Studio/VariantsDialog.vue');
const VariantsDialog = defineAsyncComponent(loadVariantsDialog);
const loadStudioFontsDialog = () => import('@/Components/Studio/StudioFontsDialog.vue');
const StudioFontsDialog = defineAsyncComponent(loadStudioFontsDialog);
const loadStudioChartsDialog = () => import('@/Components/Studio/StudioChartsDialog.vue');
const StudioChartsDialog = defineAsyncComponent(loadStudioChartsDialog);
const loadStudioDatasetsDialog = () => import('@/Components/Studio/StudioDatasetsDialog.vue');
const StudioDatasetsDialog = defineAsyncComponent(loadStudioDatasetsDialog);

export function useEditorDialogs({ error, isDisposed, templateSaveOpen, variantsOpen, datasetsOpen, chartsOpen }) {
    const exporter = ref(null);
    const exportOpen = ref(false);
    const exportBusy = ref(false);
    const batchOpen = ref(false);
    const fontsOpen = ref(false);
    const collabOpen = ref(false);

    // Al volver a abrir un diálogo se reutilizan sus opciones y el cierre nativo restaura el foco.
    function mountDialogOnOpen(open, load) {
        const mounted = ref(false);
        watch(open, async (value) => {
            if (!value || mounted.value) return;
            try {
                await load();
                if (!isDisposed() && open.value) mounted.value = true;
            } catch {
                if (!isDisposed() && open.value) {
                    open.value = false;
                    error.value = 'No se pudo cargar el diálogo. Recarga la página.';
                }
            }
        });
        return mounted;
    }

    const exportMounted = mountDialogOnOpen(exportOpen, loadExportDialog);
    const batchMounted = mountDialogOnOpen(batchOpen, loadBatchExportDialog);
    const templateSaveMounted = mountDialogOnOpen(templateSaveOpen, loadStudioDialog);
    const fontsMounted = mountDialogOnOpen(fontsOpen, loadStudioFontsDialog);
    const datasetsMounted = mountDialogOnOpen(datasetsOpen, loadStudioDatasetsDialog);
    const chartsMounted = mountDialogOnOpen(chartsOpen, loadStudioChartsDialog);
    const variantsMounted = mountDialogOnOpen(variantsOpen, loadVariantsDialog);
    const collabMounted = mountDialogOnOpen(collabOpen, () =>
        Promise.all([loadStudioDialog(), loadStudioCollabPanel()]),
    );

    const exportLoading = ref(false);
    const publishPending = ref(false);
    const editor = getCurrentInstance()?.proxy;
    if (editor) {
        onErrorCaptured((_, source) => {
            if (!publishPending.value || isDisposed()) return;
            for (let child = source; child && child !== editor; child = child.$parent) {
                if (child.$options?.name !== 'StudioExportDialog') continue;
                publishPending.value = false;
                error.value = 'No se pudo abrir la exportación. Recarga la página.';
                break;
            }
        });
    }

    function closeEditorDialogs() {
        publishPending.value = false;
        exportOpen.value = false;
        batchOpen.value = false;
        fontsOpen.value = false;
        collabOpen.value = false;
    }
    async function publishWithDialog(dialog) {
        if (isDisposed()) return;
        try {
            await dialog.publish();
        } catch {
            if (!isDisposed()) error.value = 'No se pudo iniciar la publicación desde el editor.';
        }
    }

    watch(exporter, (dialog) => {
        if (!dialog || !publishPending.value) return;
        publishPending.value = false;
        void publishWithDialog(dialog);
    });

    async function publishFromEditor() {
        if (isDisposed() || exportLoading.value || publishPending.value) return;
        if (exporter.value) return publishWithDialog(exporter.value);
        exportLoading.value = true;
        try {
            await loadExportDialog();
            if (!isDisposed()) {
                publishPending.value = true;
                exportMounted.value = true;
            }
        } catch {
            if (!isDisposed()) error.value = 'No se pudo abrir la exportación. Recarga la página.';
        } finally {
            exportLoading.value = false;
        }
    }

    return {
        ExportDialog,
        BatchExportDialog,
        PresentationDialog,
        StudioDialog,
        StudioCollabPanel,
        VariantsDialog,
        StudioFontsDialog,
        StudioChartsDialog,
        StudioDatasetsDialog,
        loadPresentationDialog,
        exportMounted,
        batchMounted,
        templateSaveMounted,
        fontsMounted,
        datasetsMounted,
        chartsMounted,
        variantsMounted,
        collabMounted,
        exporter,
        exportOpen,
        batchOpen,
        exportBusy,
        fontsOpen,
        collabOpen,
        exportLoading,
        publishPending,
        publishFromEditor,
        closeEditorDialogs,
    };
}
