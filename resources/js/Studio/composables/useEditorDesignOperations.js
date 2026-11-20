import { ref } from 'vue';
import { validateDocument, findPage } from '../document.js';
import { downloadStudioBlob } from '../api.js';

export function useEditorDesignOperations({
    request,
    documentData,
    name,
    template,
    kitRevision,
    pageId,
    snapshot,
    error,
    run,
    fit,
    reloadCanvas,
    updateFonts,
    revision,
    templateFields,
    conflict,
    dirty,
    status,
    automationMessage,
    savedOperation,
    commitHistory,
    resetHistory,
}) {
    const designWidth = ref(documentData.value.width);
    const designHeight = ref(documentData.value.height);
    const resizeMode = ref('contain');
    const templateSaveError = ref('');
    const templateSaveOpen = ref(false);
    const templateName = ref('');
    const templateCreated = ref(null);
    const variantsOpen = ref(false);
    const variantsCreated = ref([]);

    async function acceptDesignMutation(design) {
        if (design.fonts) await updateFonts({ fonts: design.fonts });
        documentData.value = validateDocument(design.document);
        revision.value = design.revision;
        kitRevision.value = design.revision;
        name.value = design.name;
        template.value = design.is_template;
        templateFields.value = design.template_fields ?? [];
        designWidth.value = documentData.value.width;
        designHeight.value = documentData.value.height;
        if (!findPage(documentData.value, pageId.value)) pageId.value = documentData.value.pages[0].id;
        commitHistory();
        dirty.value = false;
        status.value = 'Guardado';
        await reloadCanvas();
        fit();
    }
    function resizeDesign() {
        return savedOperation(async () => {
            const result = await request('POST', 'studio.designs.resize', {
                revision: revision.value,
                width: Number(designWidth.value),
                height: Number(designHeight.value),
                mode: resizeMode.value,
            });
            await acceptDesignMutation(result.design);
            automationMessage.value = 'Tamaño actualizado en todas las páginas.';
        });
    }
    function openTemplateSave() {
        templateName.value = `${name.value.slice(0, 240)} — plantilla`;
        templateCreated.value = null;
        templateSaveError.value = '';
        templateSaveOpen.value = true;
    }
    async function saveTemplate() {
        const result = await savedOperation(async () => {
            const response = await request('POST', 'studio.designs.template', {
                revision: revision.value,
                name: templateName.value.trim(),
            });
            templateCreated.value = response.design;
        }, templateSaveError);
        if (conflict.value) templateSaveOpen.value = false;
        return result;
    }
    function openVariants() {
        return savedOperation(async () => {
            variantsCreated.value = [];
            variantsOpen.value = true;
        });
    }
    function createVariants(payload) {
        return savedOperation(async () => {
            const result = await request('POST', 'studio.designs.variants', { ...payload, revision: revision.value });
            variantsCreated.value = result.designs;
        });
    }
    function applyBrandKit() {
        return savedOperation(async () => {
            const result = await request('POST', 'studio.designs.apply-kit', { revision: revision.value });
            await acceptDesignMutation(result.design);
            automationMessage.value = 'Identidad de marca aplicada. Puedes deshacer el cambio.';
        });
    }

    function downloadLocal() {
        downloadStudioBlob(
            new Blob([JSON.stringify(snapshot(), null, 2)], { type: 'application/json' }),
            `${name.value}-copia-local.json`,
        );
    }
    async function recover() {
        await run(async () => {
            const latest = await request('GET', 'studio.designs.show');
            if (latest.design.fonts || latest.fonts) await updateFonts({ fonts: latest.design.fonts ?? latest.fonts });
            documentData.value = validateDocument(latest.design.document);
            name.value = latest.design.name;
            revision.value = latest.design.revision;
            template.value = latest.design.is_template;
            kitRevision.value = latest.design.revision;
            templateFields.value = latest.design.template_fields ?? [];
            pageId.value = documentData.value.pages[0].id;
            designWidth.value = documentData.value.width;
            designHeight.value = documentData.value.height;
            conflict.value = false;
            dirty.value = false;
            status.value = 'Guardado';
            error.value = '';
            resetHistory();
            await reloadCanvas();
        });
    }

    return {
        designWidth,
        designHeight,
        resizeMode,
        templateSaveError,
        templateSaveOpen,
        templateName,
        templateCreated,
        variantsOpen,
        variantsCreated,
        acceptDesignMutation,
        resizeDesign,
        openTemplateSave,
        saveTemplate,
        openVariants,
        createVariants,
        applyBrandKit,
        downloadLocal,
        recover,
    };
}
