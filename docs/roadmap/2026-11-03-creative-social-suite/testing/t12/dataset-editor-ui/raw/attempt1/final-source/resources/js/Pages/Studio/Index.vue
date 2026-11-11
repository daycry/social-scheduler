<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import TemplatePreview from '@/Components/Studio/TemplatePreview.vue';
import StudioDialog from '@/Components/Studio/StudioDialog.vue';
import TemplateInstanceDialog from '@/Components/Studio/TemplateInstanceDialog.vue';
import StudioFontsDialog from '@/Components/Studio/StudioFontsDialog.vue';
import StudioDatasetsDialog from '@/Components/Studio/StudioDatasetsDialog.vue';
import { blankDocument } from '@/Studio/document';
import { STUDIO_TEMPLATES, createFromTemplate } from '@/Studio/templates';
import { studioRequest } from '@/Studio/api';
import { STUDIO_FORMAT_PRESETS } from '@/Studio/format-presets';

const props = defineProps({ brand: Object, designs: Object, kit: Object, assets: Array, folders: Array, filters: Object, fonts: { type: Array, default: () => [] } });
const createOpen = ref(false);
const kitOpen = ref(!!props.brand && new URLSearchParams(location.search).get('tab') === 'brand');
const error = ref('');
const busy = ref(false);
const query = ref(props.filters?.query ?? '');
const createName = ref('Diseño sin título');
const width = ref(1080);
const height = ref(1080);
const selectedFolder = ref(props.filters?.folder_id ?? '');
const newFolder = ref('');
const folderOpen = ref(false);
const instanceOpen = ref(false);
const instanceTemplate = ref(null);
const paletteText = ref((props.kit?.palette ?? ['#7c3aed', '#111827', '#ffffff']).join(', '));
const fontsText = ref((props.kit?.fonts ?? ['Arial']).join(', '));
const fontsOpen = ref(false);
const datasetsOpen = ref(false);
const localFonts = ref([...props.fonts]);
const selectedKitFonts = ref((props.kit?.font_ids ?? []).filter(id => Number.isInteger(id) && id > 0));
const kitFontChoices = computed(() => localFonts.value.filter(font => !font.archived_at || selectedKitFonts.value.includes(font.id)));
const selectedLogos = ref([...(props.kit?.logos ?? [])]);
const logoAssets = computed(() => (props.assets ?? []).filter(asset => asset.kind === 'image'));
const guidelines = ref(props.kit?.guidelines ?? '');
const voice = ref(props.kit?.voice ?? '');
const rows = computed(() => props.designs?.data ?? []);
const archived = computed(() => !!props.filters?.archived);
const templatesOnly = computed(() => !!props.filters?.templates);
const presets = STUDIO_FORMAT_PRESETS;

const filter = (patch = {}) => router.get(route('studio.index'), {
    brand_id: props.brand?.id, query: query.value || undefined, folder_id: selectedFolder.value || undefined,
    templates: templatesOnly.value ? 1 : undefined, archived: archived.value ? 1 : undefined, ...patch,
}, { preserveScroll: true, preserveState: true });

const create = async (template = null) => {
    if (!props.brand || busy.value) return;
    busy.value = true; error.value = '';
    try {
        const document = template ? createFromTemplate(template.key, props.kit) : blankDocument(Number(width.value), Number(height.value));
        const result = await studioRequest('POST', route('studio.designs.store', props.brand.id), {
            name: template ? template.name : createName.value.trim() || 'Diseño sin título', document,
            folder_id: selectedFolder.value ? Number(selectedFolder.value) : null, is_template: false,
        });
        router.visit(route('studio.designs.edit', result.design.id));
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
};
const mutate = async (design, action) => {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try {
        const result = await studioRequest(action === 'archive' ? 'DELETE' : 'POST', route(`studio.designs.${action === 'archive' ? 'destroy' : action}`, design.id), action === 'duplicate' ? { name: `${design.name} — copia` } : undefined);
        if (action === 'duplicate') router.visit(route('studio.designs.edit', result.design.id));
        else router.reload({ only: ['designs'] });
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
};
const useTemplate = async design => {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try {
        const result = await studioRequest('GET', route('studio.designs.show', design.id));
        instanceTemplate.value = result.design; instanceOpen.value = true;
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
};
const instantiateTemplate = async payload => {
    if (busy.value || !instanceTemplate.value) return;
    busy.value = true; error.value = '';
    try {
        const result = await studioRequest('POST', route('studio.designs.instantiate', instanceTemplate.value.id), payload);
        instanceOpen.value = false; router.visit(route('studio.designs.edit', result.design.id));
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
};
const saveKit = async () => {
    busy.value = true; error.value = '';
    try {
        const families = fontsText.value.split(',').map(s => s.trim()).filter(Boolean);
        await studioRequest('PUT', route('studio.kit.update', props.brand.id), {
            palette: paletteText.value.split(',').map(s => s.trim()).filter(Boolean), fonts: families,
            font_ids: families.map(family => localFonts.value.find(font => font.family === family && selectedKitFonts.value.includes(font.id))?.id ?? null),
            logos: selectedLogos.value, guidelines: guidelines.value, voice: voice.value,
        });
        kitOpen.value = false; router.reload({ only: ['kit'] });
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
};
function updateFonts({ fonts = [], deletedIds = [] }) {
    const next = new Map(localFonts.value.map(font => [font.id, font]));
    fonts.forEach(font => next.set(font.id, font)); deletedIds.forEach(id => next.delete(id));
    localFonts.value = [...next.values()];
    selectedKitFonts.value = selectedKitFonts.value.filter(id => !deletedIds.includes(id));
}
function toggleKitFont(font, checked) {
    const familyIds = localFonts.value.filter(item => item.family === font.family).map(item => item.id);
    selectedKitFonts.value = selectedKitFonts.value.filter(id => !familyIds.includes(id));
    let families = fontsText.value.split(',').map(value => value.trim()).filter(Boolean);
    if (checked) { selectedKitFonts.value.push(font.id); if (!families.includes(font.family)) families.push(font.family); }
    else families = families.filter(family => family !== font.family);
    fontsText.value = families.join(', ');
}
const createFolder = async () => {
    if (!newFolder.value.trim() || busy.value) return;
    busy.value = true; error.value = '';
    try {
        await studioRequest('POST', route('studio.folders.store', props.brand.id), { name: newFolder.value.trim() });
        folderOpen.value = false; newFolder.value = ''; router.reload({ only: ['folders'] });
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
};
const date = value => new Intl.DateTimeFormat('es', { day: 'numeric', month: 'short' }).format(new Date(value));
</script>

<template>
    <Head title="Estudio creativo" />
    <AuthenticatedLayout>
        <div class="mx-auto max-w-[1500px] space-y-8">
            <section class="relative overflow-hidden rounded-3xl bg-[#ede8ff] px-6 py-8 sm:px-9">
                <div class="pointer-events-none absolute -right-14 -top-24 h-80 w-80 rounded-full bg-violet-300/30 blur-3xl" aria-hidden="true" />
                <div class="relative flex flex-wrap items-center justify-between gap-5">
                    <div>
                        <p class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-violet-800"><Icon name="palette" class="h-4 w-4" /> {{ brand?.name ?? 'Tu espacio de diseño' }}</p>
                        <h1 class="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">Estudio creativo</h1>
                        <p class="mt-3 max-w-xl text-sm leading-relaxed text-slate-600">Dale forma a tu próxima publicación. Diseños, plantillas y recursos en el espacio de cada marca.</p>
                    </div>
                    <button v-if="brand" type="button" class="inline-flex items-center gap-2 rounded-xl bg-violet-700 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-violet-800" @click="createOpen = true"><Icon name="plus" class="h-5 w-5" />Nuevo diseño</button>
                    <Link v-else :href="route('brands.create')" class="btn-primary">Crear una marca</Link>
                </div>
            </section>

            <p v-if="error" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ error }}</p>
            <template v-if="brand">
                <section class="space-y-4" aria-label="Formatos de diseño">
                    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold tracking-tight">¿Qué vas a crear?</h2><div class="flex flex-wrap gap-4"><button type="button" class="text-sm font-medium text-violet-700" @click="datasetsOpen = true">Tablas de datos</button><button type="button" class="text-sm font-medium text-violet-700" @click="fontsOpen = true">Tipografías de marca</button><button type="button" class="text-sm font-medium text-violet-700" @click="kitOpen = true">Identidad de marca <span aria-hidden="true">↗</span></button></div></div>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                        <button v-for="preset in presets" :key="preset.label" type="button" class="group flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 text-left transition hover:-translate-y-0.5 hover:border-violet-300 hover:shadow-sm" @click="width = preset.w; height = preset.h; createOpen = true">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-slate-50 group-hover:bg-violet-50"><span class="block max-h-8 max-w-8 rounded border-2 border-violet-500 bg-violet-100" :style="{ width: preset.w >= preset.h ? '28px' : `${28 * preset.w / preset.h}px`, height: preset.h >= preset.w ? '28px' : `${28 * preset.h / preset.w}px` }" /></span>
                            <span><span class="block text-xs font-semibold leading-snug">{{ preset.label }}</span><span class="mt-1 block text-[10px] text-slate-500">{{ preset.w }} × {{ preset.h }}</span></span>
                        </button>
                    </div>
                </section>

                <section class="space-y-5" aria-labelledby="studio-designs-title">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-5">
                            <h2 id="studio-designs-title" class="text-xl font-semibold tracking-tight">{{ archived ? 'Papelera' : templatesOnly ? 'Tus plantillas' : 'Mis diseños' }}</h2>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600">{{ designs?.total ?? 0 }}</span>
                        </div>
                        <form class="flex w-full max-w-sm items-center gap-2" role="search" @submit.prevent="filter()"><label class="sr-only" for="studio-search">Buscar diseños</label><input id="studio-search" v-model="query" class="field min-w-0" type="search" placeholder="Buscar en esta marca…" /><button type="submit" class="btn-secondary px-3">Buscar</button></form>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <button type="button" class="rounded-full border px-3 py-2" :class="!templatesOnly && !archived ? 'border-violet-300 bg-violet-50 text-violet-800' : 'border-slate-200 bg-white'" @click="filter({ templates: undefined, archived: undefined })">Todos</button>
                        <button type="button" class="rounded-full border px-3 py-2" :class="templatesOnly ? 'border-violet-300 bg-violet-50 text-violet-800' : 'border-slate-200 bg-white'" @click="filter({ templates: 1, archived: undefined })">Plantillas</button>
                        <button type="button" class="rounded-full border px-3 py-2" :class="archived ? 'border-violet-300 bg-violet-50 text-violet-800' : 'border-slate-200 bg-white'" @click="filter({ archived: 1, templates: undefined })">Papelera</button>
                        <label class="sr-only" for="studio-folder-filter">Carpeta</label><select id="studio-folder-filter" v-model="selectedFolder" class="rounded-full border-slate-200 py-2 pl-3 pr-8 text-xs" @change="filter()"><option value="">Todas las carpetas</option><option v-for="folder in folders" :key="folder.id" :value="folder.id">{{ folder.name }}</option></select>
                        <button type="button" class="ml-auto inline-flex items-center gap-1 rounded-full px-3 py-2 text-slate-600 hover:bg-slate-100" @click="folderOpen = true"><Icon name="folder" class="h-4 w-4" />Nueva carpeta</button>
                    </div>
                    <div v-if="rows.length" class="grid grid-cols-1 gap-5 min-[440px]:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <article v-for="design in rows" :key="design.id" class="group min-w-0">
                            <Link v-if="!archived" :href="route('studio.designs.edit', design.id)" class="flex aspect-[4/3] items-center justify-center rounded-2xl border border-slate-200 bg-white p-6 transition group-hover:border-violet-300 group-hover:shadow-sm" :aria-label="`Editar ${design.name}`">
                                <div class="flex h-full max-w-full items-center justify-center rounded border border-slate-200 bg-slate-50 px-5 text-center" :style="{ aspectRatio: `${design.width ?? 1080} / ${design.height ?? 1080}` }"><span class="text-xs font-medium leading-relaxed text-slate-500">{{ design.name }}<span class="mt-2 block text-[10px] font-normal">{{ design.width }} × {{ design.height }} · {{ design.page_count }} {{ design.page_count === 1 ? 'página' : 'páginas' }}</span></span></div>
                            </Link>
                            <div v-else class="flex aspect-[4/3] items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-100"><Icon name="trash" class="h-8 w-8 text-slate-400" /></div>
                            <div class="mt-3 flex items-start gap-2"><div class="min-w-0 flex-1"><h3 class="truncate text-sm font-semibold">{{ design.name }}</h3><p class="mt-1 text-xs text-slate-500">{{ date(design.updated_at) }}<span v-if="design.is_template"> · Plantilla</span></p></div><details class="relative"><summary class="flex h-8 w-8 cursor-pointer list-none items-center justify-center rounded-lg hover:bg-slate-100" :aria-label="`Opciones de ${design.name}`">•••</summary><div class="absolute right-0 z-20 mt-1 min-w-40 rounded-xl border border-slate-200 bg-white p-1 shadow-lg"><button v-if="!archived" type="button" class="block w-full rounded-lg px-3 py-2 text-left text-xs hover:bg-slate-50" :disabled="busy" @click="mutate(design, 'duplicate')">Duplicar diseño</button><button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-xs hover:bg-slate-50" :disabled="busy" @click="mutate(design, archived ? 'restore' : 'archive')">{{ archived ? 'Restaurar' : 'Mover a papelera' }}</button></div></details></div>
                            <button v-if="design.is_template && !archived" type="button" class="mt-3 w-full rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-800 hover:border-violet-300 hover:bg-violet-100 disabled:opacity-50" :disabled="busy" @click="useTemplate(design)">Usar plantilla</button>
                        </article>
                    </div>
                    <div v-else class="rounded-2xl border border-dashed border-slate-200 bg-white px-6 py-12 text-center"><Icon :name="archived ? 'trash' : 'palette'" class="mx-auto mb-4 h-8 w-8 text-violet-400" /><h3 class="text-base font-semibold">{{ query ? 'No hay diseños con esa búsqueda' : archived ? 'La papelera está vacía' : 'Aquí empieza tu próxima idea' }}</h3><p class="mt-2 text-sm text-slate-500">{{ query ? 'Prueba con otro nombre o carpeta.' : 'Crea un diseño o elige una plantilla para empezar.' }}</p><button v-if="!archived" type="button" class="mt-5 rounded-xl bg-violet-700 px-4 py-2.5 text-sm font-semibold text-white" @click="createOpen = true">Crear mi primer diseño</button></div>
                    <nav v-if="(designs?.last_page ?? 1) > 1" class="flex flex-wrap justify-center gap-2" aria-label="Paginación de diseños"><template v-for="(link, i) in designs.links" :key="i"><Link v-if="link.url" :href="link.url" class="rounded-lg border px-3 py-2 text-xs" :class="link.active ? 'border-violet-300 bg-violet-50' : 'border-slate-200 bg-white'">{{ i === 0 ? 'Anterior' : i === designs.links.length - 1 ? 'Siguiente' : i }}</Link></template></nav>
                </section>

                <section v-if="!archived" class="space-y-4" aria-labelledby="studio-templates-title"><div><h2 id="studio-templates-title" class="text-xl font-semibold tracking-tight">Una idea para empezar</h2><p class="mt-1 text-sm text-slate-500">Plantillas editables que puedes adaptar a tu marca.</p></div><div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5"><button v-for="template in STUDIO_TEMPLATES" :key="template.key" type="button" class="text-left transition hover:-translate-y-1" :disabled="busy" @click="create(template)"><TemplatePreview :template="template" :kit="kit" :fonts="localFonts" /><span class="mt-2 block text-sm font-semibold">{{ template.name }}</span><span class="mt-1 block text-xs text-slate-500">{{ template.category }} · {{ template.width }} × {{ template.height }}</span></button></div></section>
            </template>
        </div>

        <StudioDialog v-model="createOpen" title="Nuevo diseño">
            <form class="space-y-5" @submit.prevent="create()"><div><label class="mb-1 block text-sm font-medium" for="studio-create-name">Nombre</label><input id="studio-create-name" v-model="createName" class="field" maxlength="255" autofocus required /></div><div class="grid grid-cols-2 gap-4"><label class="space-y-1 text-sm font-medium">Ancho<input v-model.number="width" type="number" min="40" max="8000" class="field" required /></label><label class="space-y-1 text-sm font-medium">Alto<input v-model.number="height" type="number" min="40" max="8000" class="field" required /></label></div><p class="text-xs text-slate-500">Dimensiones en píxeles. Podrás adaptarlas desde el editor.</p><p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p><div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="createOpen = false">Cancelar</button><button type="submit" class="btn-primary bg-violet-700" :disabled="busy">{{ busy ? 'Creando…' : 'Crear diseño' }}</button></div></form>
        </StudioDialog>
        <StudioDialog v-model="folderOpen" title="Nueva carpeta"><form class="space-y-4" @submit.prevent="createFolder"><label class="block text-sm font-medium">Nombre de la carpeta<input v-model="newFolder" class="field mt-1" maxlength="255" required autofocus /></label><p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p><button type="submit" class="btn-primary" :disabled="busy">Crear carpeta</button></form></StudioDialog>
        <TemplateInstanceDialog v-model="instanceOpen" :template="instanceTemplate" :busy="busy" :error="error" @create="instantiateTemplate" />
        <StudioFontsDialog v-if="brand" v-model="fontsOpen" :brand-id="brand.id" @changed="updateFonts" />
        <StudioDatasetsDialog v-if="brand" v-model="datasetsOpen" :brand-id="brand.id" :brand-name="brand.name" />
        <StudioDialog v-model="kitOpen" title="Identidad de marca">
            <form class="space-y-4" @submit.prevent="saveKit">
                <p class="text-sm text-slate-500">Los estilos pertenecen a {{ brand?.name }} y se aplican a los diseños de esta marca.</p>
                <fieldset class="min-w-0 space-y-4" :disabled="busy">
                    <label class="block text-sm font-medium">Paleta de colores<input v-model="paletteText" class="field mt-1" placeholder="#7c3aed, #111827, #ffffff" /><span class="mt-1 block text-xs font-normal text-slate-500">Colores hexadecimales separados por comas.</span></label>
                    <div class="flex flex-wrap gap-2"><span v-for="color in paletteText.split(',').map(s => s.trim()).filter(s => /^#[0-9a-f]{3,8}$/i.test(s))" :key="color" class="h-8 w-8 rounded-full border border-slate-200" :style="{ background: color }" /></div>
                    <label class="block text-sm font-medium">Tipografías<input v-model="fontsText" aria-label="Tipografías" class="field mt-1" /><span class="mt-1 block text-xs font-normal text-slate-500">Familias locales separadas por comas. Selecciona debajo los archivos propios de esta marca.</span></label>
                    <fieldset class="space-y-2 rounded-xl border border-slate-200 p-3"><legend class="px-1 text-sm font-medium">Archivos tipográficos del kit</legend><label v-for="font in kitFontChoices" :key="font.id" class="flex min-w-0 items-start gap-2 text-sm"><input type="checkbox" class="mt-1" :checked="selectedKitFonts.includes(font.id)" :aria-label="`Incluir tipografía ${font.name} en la identidad`" @change="toggleKitFont(font,$event.target.checked)" /><span class="min-w-0 break-words">{{ font.name }}<span class="block text-xs text-slate-500">{{ font.family }} · {{ font.style }} · {{ font.weight }}{{ font.archived_at ? ' · Archivada, ya vinculada' : '' }}</span></span></label><p v-if="!kitFontChoices.length" class="text-xs leading-relaxed text-slate-500">Sube una tipografía desde Tipografías de marca para vincular el archivo a tu identidad.</p><p v-else class="text-xs leading-relaxed text-slate-500">Un archivo por familia en la identidad; puedes elegir otras variantes en cada texto del editor.</p></fieldset>
                    <fieldset class="min-w-0 space-y-3 rounded-xl border border-slate-200 p-4" aria-label="Logotipos de marca">
                        <legend class="px-1 text-sm font-medium">Logotipos de marca</legend>
                        <p class="text-xs leading-relaxed text-slate-500">Selecciona hasta 30 imágenes de esta marca, en el orden en que quieras vincularlas al kit. Puedes subirlas desde Recursos en cualquier diseño.</p>
                        <div v-if="logoAssets.length" class="grid max-h-64 grid-cols-1 gap-2 overflow-y-auto sm:grid-cols-2">
                            <label v-for="asset in logoAssets" :key="asset.id" class="flex min-w-0 cursor-pointer items-center gap-2 rounded-lg border border-slate-200 p-2" :class="selectedLogos.includes(asset.id) ? 'border-violet-300 bg-violet-50' : ''"><input v-model="selectedLogos" type="checkbox" :value="asset.id" :aria-label="`Logo de marca ${asset.name}`" :disabled="!selectedLogos.includes(asset.id) && selectedLogos.length >= 30" /><img :src="asset.preview_url" alt="" class="h-10 w-10 shrink-0 rounded bg-white object-contain" loading="lazy" /><span class="min-w-0 break-words text-xs">{{ asset.name }}<span v-if="selectedLogos.includes(asset.id)" class="mt-1 block text-[10px] font-medium text-violet-700">Posición {{ selectedLogos.indexOf(asset.id) + 1 }}</span></span></label>
                        </div>
                        <p v-else class="rounded-lg bg-slate-50 p-3 text-xs text-slate-500">Todavía no hay imágenes en la biblioteca de esta marca.</p>
                        <p class="text-xs text-slate-500">{{ selectedLogos.length }}/30 seleccionados</p>
                    </fieldset>
                    <label class="block text-sm font-medium">Guía de estilo<textarea v-model="guidelines" class="field mt-1" rows="3" maxlength="10000" /></label>
                    <label class="block text-sm font-medium">Voz de la marca<textarea v-model="voice" class="field mt-1" rows="2" maxlength="5000" /></label>
                </fieldset>
                <p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p><div class="flex justify-end"><button type="submit" class="btn-primary bg-violet-700" :disabled="busy">Guardar identidad</button></div>
            </form>
        </StudioDialog>
    </AuthenticatedLayout>
</template>
