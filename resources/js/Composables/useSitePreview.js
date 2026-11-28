import { computed, ref } from 'vue';

const SIZES = Object.freeze({ mobile: ['Móvil', '375px'], tablet: ['Tablet', '768px'], desktop: ['Escritorio', '100%'] });

export function useSitePreview(getSite, getActivePage) {
    const size = ref('mobile');
    const previewSrc = computed(() => {
        const site = getSite();
        const home = site.pages[0];
        const saved = site.pages[getActivePage()] ?? home;
        return route('sites.preview', { site: site.id, page: home?.key === saved?.key ? undefined : saved?.slug, v: site.revision }, false);
    });

    return { size, previewSrc, sizes: SIZES };
}
