export interface ReportBrand {
    id: number;
    name: string;
    timezone: string | null;
}

export interface ReportDefaults {
    brand_id: number | null;
    from: string;
    to: string;
    compare: string;
}

export interface ReportBlock {
    type: string;
    title: string | null;
    text: string | null;
}

export interface ReportTemplate {
    id: number;
    brand_id: number;
    name: string;
    blocks: ReportBlock[];
    range_days: number;
    compare: string;
}

export interface ReportSchedule {
    id: number;
    brand_id: number;
    brand: string;
    template: string | null;
    frequency: 'weekly' | 'monthly';
    recipients: string[];
    active: boolean;
    next_run_at: string | null;
    last_run_at: string | null;
    last_error: string | null;
}

export interface ReportRow {
    id: number;
    brand: string;
    format: 'pdf' | 'csv';
    status: 'pending' | 'processing' | 'ready' | 'failed';
    from: string;
    to: string;
    compare: string;
    size: number | null;
    error: string | null;
    created_at: string | null;
    download_url: string | null;
    deletable: boolean;
}

interface Competitor {
    username: string;
    followers_end: number | null;
}

type Ads = {available: false; message: string}
    | {available: true; account: string | null; campaigns: {name: string}[]};

type PreviewBlock = {type: 'text' | 'kpis' | 'evolution' | 'top_posts'; title: string | null; text: string | null}
    | {type: 'competitors'; title: string | null; data: {own: Competitor | null; competitors: Competitor[]}}
    | {type: 'ads'; title: string | null; data: Ads};

export interface ReportPreview {
    brand: {id: number; name: string};
    period: {from: string; to: string};
    blocks: PreviewBlock[];
    cards: {key: string; label: string; total: number | null}[];
    timeline: {label: string | null; granularity: 'week' | 'day'; points: unknown[]};
    networks: {label: string; posts: unknown[]}[];
}
