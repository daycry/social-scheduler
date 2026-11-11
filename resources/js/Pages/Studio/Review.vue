<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PublicShareLayout from '@/Layouts/PublicShareLayout.vue';
import StudioCollabPanel from '@/Components/Studio/StudioCollabPanel.vue';
import { renderStudioPage } from '@/Studio/canvas';

const props = defineProps({
    design: Object, brand: Object, assets: { type: Array, default: () => [] }, fonts: { type: Array, default: () => [] },
    access: Object, link: { type: Object, default: null },
});
const Shell = props.link ? PublicShareLayout : AuthenticatedLayout;
const pages = computed(() => props.design.document.pages.map((page, index) => ({ id: page.id, name: page.name || `Página ${index + 1}` })));
const pageId = ref(pages.value[0].id);
const elementId = ref('');
const images = ref({});
const failed = ref({});
const rendering = ref(true);
let disposed = false;

const page = computed(() => props.design.document.pages.find(item => item.id === pageId.value));
const elements = computed(() => (page.value?.elements ?? []).map(element => ({ id: element.id, label: `${element.id} (${element.type})` })));
watch(pageId, () => { elementId.value = ''; });

async function draw(id) {
    if (images.value[id] || failed.value[id]) return;
    let rendered;
    try {
        const doc = props.design.document;
        rendered = await renderStudioPage(doc, id, props.assets, props.fonts, { renderScale: Math.min(1, 1000 / doc.width) });
        if (!disposed) images.value = { ...images.value, [id]: rendered.canvas.getElement().toDataURL('image/png') };
    } catch {
        if (!disposed) failed.value = { ...failed.value, [id]: true };
    } finally {
        await rendered?.dispose();
    }
}
onMounted(async () => {
    for (const item of pages.value) { if (disposed) break; await draw(item.id); }
    rendering.value = false;
});
onBeforeUnmount(() => { disposed = true; });
const roleText = computed(() => props.access.role_label.toLowerCase());
</script>

<template>
    <Head :title="`${design.name} · Revisión`" />
    <component :is="Shell">
        <div class="space-y-4" data-testid="studio-review">
            <header class="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                <Link v-if="!link" :href="route('studio.index', { brand_id: brand.id })" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50" aria-label="Volver al estudio">←</Link>
                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-lg font-semibold tracking-tight">{{ design.name }}</h1>
                    <p class="text-xs text-slate-500" role="status">Acceso de {{ roleText }}{{ access.is_guest ? ' por enlace' : '' }} · {{ access.is_guest ? brand.name : `de ${access.owner_name} (${brand.name})` }} · Versión {{ design.revision }}</p>
                </div>
                <p class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">Solo {{ access.can_comment ? 'lectura y comentarios' : 'lectura' }}</p>
            </header>
            <div class="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
                <section class="min-w-0 space-y-3" aria-label="Diseño">
                    <nav v-if="pages.length > 1" class="flex flex-wrap gap-2" aria-label="Páginas">
                        <button v-for="item in pages" :key="item.id" type="button" class="rounded-lg border px-3 py-2 text-xs font-medium" :class="item.id === pageId ? 'border-violet-600 bg-violet-50 text-violet-900' : 'border-slate-200 bg-white text-slate-700'" :aria-pressed="item.id === pageId" @click="pageId = item.id">{{ item.name }}</button>
                    </nav>
                    <div class="overflow-auto rounded-2xl border border-slate-200 bg-slate-100 p-3 sm:p-6">
                        <img v-if="images[pageId]" :src="images[pageId]" :alt="`${design.name}, ${pages.find(item => item.id === pageId)?.name}`" class="mx-auto h-auto max-w-full bg-white shadow-lg" data-testid="studio-review-page" />
                        <p v-else-if="failed[pageId]" role="alert" class="py-10 text-center text-sm text-red-800">No se ha podido dibujar esta página.</p>
                        <p v-else role="status" class="py-10 text-center text-sm text-slate-600">{{ rendering ? 'Preparando la página…' : 'Página no disponible.' }}</p>
                    </div>
                    <div v-if="access.can_comment && elements.length" class="space-y-1 text-xs"><label class="block" for="review-element">Elemento al que se refiere el comentario (opcional)</label><select id="review-element" v-model="elementId" class="field w-full text-xs"><option value="">Ninguno</option><option v-for="item in elements" :key="item.id" :value="item.id">{{ item.label }}</option></select></div>
                </section>
                <aside class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4" aria-label="Comentarios y versiones">
                    <StudioCollabPanel :design-id="design.id" :link="link" :access="access" :pages="pages" :page-id="pageId" :element-id="elementId || null" :revision="design.revision" id-prefix="review" />
                </aside>
            </div>
        </div>
    </component>
</template>
