import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const DEFAULT_CAMPAIGN_COLOR = '#4f46e5';

/** Lee el post, la marca, la edición y la lista de campañas desde refs del compositor. */
export function useComposerCampaigns({ form, post, brand, editable, campaignSource }) {
    // Campañas (C-03): selector del encabezado con alta en línea. El alta recarga solo la lista y deja elegida la nueva.
    const campaigns = computed(() => campaignSource.value ?? []);
    // En un post ya no editable (destinos publicados o en vuelo) la campaña es metadato: se guarda al elegirla con
    // `PATCH posts/{post}/campaign`, igual que las notas, sin tocar los destinos.
    const savingCampaign = ref(false);
    const creatingCampaign = ref(false);
    const campaignError = ref('');
    let savedCampaign = post.value?.campaign_id ?? null;
    const saveCampaign = () => {
        if (!post.value || editable.value || savingCampaign.value) return;
        const value = form.campaign_id ?? null;
        router.patch(route('posts.campaign', post.value.id), { campaign_id: value }, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => { savingCampaign.value = true; campaignError.value = ''; },
            onSuccess: () => { savedCampaign = value; form.defaults('campaign_id', value); },
            onError: (errors) => { campaignError.value = errors.campaign_id ?? 'No se pudo guardar la campaña.'; form.campaign_id = savedCampaign; },
            onFinish: () => (savingCampaign.value = false),
        });
    };
    const createCampaign = (name, { onCreated } = {}) => {
        const clean = (name ?? '').trim();
        if (!clean || creatingCampaign.value) return;
        const known = new Set(campaigns.value.map((c) => c.id));
        router.post(route('campaigns.store'), { brand_id: brand.value.id, name: clean, color: DEFAULT_CAMPAIGN_COLOR }, {
            preserveScroll: true,
            preserveState: true,
            only: ['campaigns', 'flash'],
            onStart: () => { creatingCampaign.value = true; campaignError.value = ''; },
            onSuccess: () => {
                const created = campaigns.value.find((c) => !known.has(c.id));
                if (created) form.campaign_id = created.id;
                onCreated?.();
            },
            onError: (errors) => (campaignError.value = errors.name ?? errors.brand_id ?? 'No se pudo crear la campaña.'),
            onFinish: () => (creatingCampaign.value = false),
        });
    };

    return { campaigns, creatingCampaign, campaignError, createCampaign, savingCampaign, saveCampaign };
}
