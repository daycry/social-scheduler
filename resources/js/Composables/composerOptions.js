// Interruptores por red del compositor (C-04). Solo se ofrecen las opciones que la API de cada red admite de verdad (informe
// Instagram incorpora is_ai_generated según el contrato actual de Meta, además de YouTube y TikTok.
// `remember: true` = el interruptor se recuerda por (marca, red); la lista del servidor es `ComposerPresetService::REMEMBERED`.

const YOUTUBE = [
    { key: 'notify_subscribers', label: 'Notificar a los suscriptores', default: true, remember: true },
    { key: 'made_for_kids', label: 'Contenido hecho para niños', default: false, remember: true, warning: 'Marcar «hecho para niños» desactiva los comentarios y limita funciones del vídeo en YouTube.' },
    { key: 'contains_synthetic_media', label: 'Contenido alterado o generado con IA (etiqueta de YouTube)', default: false, remember: true },
    { key: 'shorts_hashtag', label: 'Añadir #Shorts a la descripción', default: true, remember: true, only: ['youtube_short'] },
];

const INSTAGRAM = [
    { key: 'is_ai_generated', label: 'Contenido generado con IA', default: false, remember: false, only: ['instagram_image', 'instagram_reel', 'instagram_carousel'], hint: 'Se enviará a Instagram la indicación de uso de IA al publicar. Se decide en esta publicación y no se recuerda para la siguiente.' },
];

const TIKTOK_DIRECT = [
    { key: 'auto_add_music', label: 'Añadir música recomendada (TikTok elige la canción)', default: false, remember: false, only: ['tiktok_photo'], hint: 'Solo para fotos y carruseles con publicación directa. No eliges una canción ni un fragmento. Se decide para esta publicación y no se recuerda.' },
    { key: 'disable_comment', label: 'Desactivar los comentarios', default: false, remember: true },
    { key: 'disable_duet', label: 'Desactivar Duet', default: false, remember: true, only: ['tiktok_video'] },
    { key: 'disable_stitch', label: 'Desactivar Stitch', default: false, remember: true, only: ['tiktok_video'] },
    { key: 'is_aigc', label: 'Contenido generado con IA (etiqueta de TikTok)', default: false, remember: true },
    {
        key: 'brand_organic_toggle', label: 'Tu marca (Your brand): promocionas tu propio negocio', default: false, remember: true,
        hint: 'TikTok etiquetará la publicación como «Promotional content» (contenido promocional). Al publicar aceptas la Confirmación de uso de música de TikTok (Music Usage Confirmation).',
    },
    {
        key: 'brand_content_toggle', label: 'Contenido de marca (Branded content): promocionas a un tercero', default: false, remember: false,
        hint: 'TikTok etiquetará la publicación como «Paid partnership» (colaboración de pago). No puede ser privado (Branded content visibility cannot be set to private) y, al publicar, aceptas la Política de contenido de marca y la Confirmación de uso de música de TikTok (Branded Content Policy y Music Usage Confirmation). No se recuerda: hay que decidirlo en cada publicación.',
    },
];

/** Claves de C-04 que pueden vivir en `options` de un destino (las que hay que podar si dejan de aplicar). */
export const MANAGED_KEYS = ['instagram_audio', 'collaborators', 'is_ai_generated', 'facebook_title', 'auto_add_music', 'category_id', 'tags', 'made_for_kids', 'notify_subscribers', 'contains_synthetic_media', 'shorts_hashtag',
    'disable_comment', 'disable_duet', 'disable_stitch', 'brand_organic_toggle', 'brand_content_toggle', 'is_aigc'];

/** Categorías de vídeo de YouTube más habituales (ID de la Data API). */
export const YOUTUBE_CATEGORIES = [
    { value: '22', label: 'Personas y blogs' }, { value: '27', label: 'Educación' }, { value: '28', label: 'Ciencia y tecnología' },
    { value: '24', label: 'Entretenimiento' }, { value: '23', label: 'Comedia' }, { value: '20', label: 'Videojuegos' },
    { value: '10', label: 'Música' }, { value: '17', label: 'Deportes' }, { value: '19', label: 'Viajes y eventos' },
    { value: '26', label: 'Consejos y estilo' }, { value: '25', label: 'Noticias y política' }, { value: '1', label: 'Cine y animación' },
    { value: '2', label: 'Automoción' }, { value: '15', label: 'Mascotas y animales' },
];

/** Interruptores que se ofrecen para una cuenta y el tipo elegido; lo no admitido (modo borrador de TikTok, red sin API) no sale. */
export function switchesFor(account, target) {
    if (!account || !target) return [];
    const list = account.network === 'instagram' ? INSTAGRAM : account.network === 'youtube' ? YOUTUBE : account.network === 'tiktok' && account.direct_post ? TIKTOK_DIRECT : [];

    return list.filter((s) => !s.only || s.only.includes(target.content_type));
}

/** ¿Lleva esta cuenta la categoría y los tags de YouTube? (campos de texto: no se recuerdan). */
export const hasYoutubeFields = (account) => account?.network === 'youtube';
