import type { PublicMediaAsset } from './media-asset';
import type { BrandWithTimezone } from './shell';

type ByName<T> = Partial<Record<string, T>>;

// PostController::compose entrega las capacidades publicables de cada cuenta.
interface ComposerAccount {
    id: number;
    network: string;
    network_label: string;
    name: string;
    username: string | null;
    status: string;
    available: boolean;
    direct_post: boolean;
    can_comment: boolean;
    comment_note: string | null;
    audio: {
        embedded_video_types: string[];
        catalog_types: string[];
        recommended_music_types: string[];
        note: string;
    };
    instagram_presets: {
        max_collaborators: number;
        content_types: string[];
        ai_disclosure: boolean;
        location_id: boolean;
        user_tags: { content_types: string[]; max: number; coordinates: string };
    } | null;
    instagram_audio: { available: boolean; reason: string | null } | null;
    linkedin: {
        kind: 'page' | 'profile';
        visibilities: ('PUBLIC' | 'CONNECTIONS')[];
        text_max: number;
        first_comment: boolean;
        alt_text: boolean;
    } | null;
    pinterest: {
        boards: { id: string; name: string }[];
        board_required: boolean;
        title_max: number;
        description_max: number;
        link: boolean;
        alt_text: boolean;
        cover: boolean;
    } | null;
}

interface ComposerTarget {
    id: number;
    account_id: number;
    content_type: string;
    text: string | null;
    manual: boolean;
    media: { id: number; alt?: string | null; cover_id?: number | null }[] | null;
    options: Record<string, unknown> | [];
    status: string;
    status_label: string;
    error_message: string | null;
    status_note: string | null;
    external_url: string | null;
    first_comment: string | null;
    first_comment_status: string | null;
    first_comment_status_label: string | null;
    first_comment_error: string | null;
    first_comment_may_exist: boolean;
}

interface ComposerPost {
    id: number;
    text: string | null;
    notes: string | null;
    campaign_id: number | null;
    approval_status: 'pendiente' | 'aprobado' | 'rechazado' | null;
    approval_comment: string | null;
    series_id: number | null;
    studio_origin_url: string | null;
    status: string;
    status_label: string;
    scheduled_at: string | null;
    editable: boolean;
    media: PublicMediaAsset[];
    target_media_assets: PublicMediaAsset[];
    targets: ComposerTarget[];
}

interface ContentChoice {
    value: string;
    label: string;
    description: string;
    limitations: string[];
}

interface TextLimit {
    max_chars: number;
    max_hashtags: number | null;
    count?: 'graphemes' | 'threads';
    advise_hashtags?: number;
}

interface SavedText {
    id: number;
    kind: 'fragmento' | 'hashtags';
    title: string;
    body: string;
    brand_id: number | null;
    brand: string | null;
}

export interface ComposePageProps {
    brand: BrandWithTimezone;
    accounts: ComposerAccount[];
    // Se construye con todas las redes del enum, aunque esta marca no tenga cuentas.
    contentTypes: ByName<ContentChoice[]>;
    // Cubre todo ContentType. limits y mediaMeta incluyen por lo menos los tipos base de Instagram y YouTube.
    typeLabels: ByName<string>;
    limits: ByName<TextLimit>;
    firstCommentMax: ByName<number> | [];
    mediaMeta: ByName<{ alt: boolean; cover: boolean }>;
    altMax: number;
    presets: ByName<ByName<boolean>> | [];
    drivePicker: { api_key: string; app_id: string; client_id: string } | null;
    giphy: { enabled: boolean };
    savedTexts: SavedText[];
    campaigns: { id: number; name: string; color: string | null; archived: boolean }[];
    post: ComposerPost | null;
    initialScheduledAt: string | null;
    bestTimeNotice: string | null;
    csrfToken: string;
}
