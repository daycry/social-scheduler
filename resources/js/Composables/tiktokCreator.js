import { reactive } from 'vue';

// Datos de la cuenta de TikTok en Direct Post (`creator_info`), consultados al abrir el compositor como piden las Content Sharing
// Guidelines de TikTok: nombre del creador, visibilidades que admite e interacciones que tiene desactivadas. Una consulta por cuenta.
const state = reactive({});

export function tiktokCreator(accountId) {
    return state[accountId] ?? { loading: false, data: null, error: null };
}

export async function loadTiktokCreator(brandId, accountId, retry = false) {
    if (state[accountId]?.loading || state[accountId]?.data || (!retry && state[accountId]?.error != null)) return;
    state[accountId] = { loading: true, data: null, error: null };
    try {
        const response = await fetch(route('brands.connections.tiktok-creator', { brand: brandId, account: accountId }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const body = await response.json().catch(() => ({}));
        const message = typeof body?.message === 'string' && body.message.trim()
            ? body.message : 'No se pudo consultar tu cuenta de TikTok.';
        const valid = response.ok && body && !Array.isArray(body)
            && typeof body.nickname === 'string' && Array.isArray(body.privacy_level_options);
        state[accountId] = valid
            ? { loading: false, data: body, error: null }
            : { loading: false, data: null, error: message };
    } catch {
        state[accountId] = { loading: false, data: null, error: 'No se pudo consultar tu cuenta de TikTok.' };
    }
}

/** Visibilidades de Direct Post con su nombre en el compositor. */
export const PRIVACY_LABELS = {
    PUBLIC_TO_EVERYONE: 'Todo el mundo',
    MUTUAL_FOLLOW_FRIENDS: 'Amigos',
    FOLLOWER_OF_CREATOR: 'Seguidores',
    SELF_ONLY: 'Solo yo',
};

/** Interruptor -> campo de `creator_info` que lo bloquea cuando la cuenta lo tiene desactivado en TikTok. */
export const CREATOR_LOCKS = { disable_comment: 'comment_disabled', disable_duet: 'duet_disabled', disable_stitch: 'stitch_disabled' };

export const MUSIC_USAGE_URL = 'https://www.tiktok.com/legal/page/global/music-usage-confirmation/en';
export const BRANDED_CONTENT_URL = 'https://www.tiktok.com/legal/page/global/bc-policy/en';
