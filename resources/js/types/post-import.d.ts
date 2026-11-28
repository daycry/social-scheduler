import type { BrandWithTimezone } from './shell';

export interface PostImportAccountHelp {
    id: number;
    network: string;
    network_label: string;
    name: string;
    identifier: string;
}

export interface PostImportPageProps {
    brand: BrandWithTimezone;
    limits: { max_rows: number; max_kb: number };
    columns: string[];
    required: string[];
    accounts: PostImportAccountHelp[];
}

export interface PostImportRow {
    row: number;
    valid: boolean;
    errors: string[];
    date: string | null;
    text: string;
    approval: boolean;
    media: number[];
    accounts: { id: number; network: string; network_label: string; name: string; content_type: string }[];
}

export interface PostImportPreview {
    token: string;
    summary: { total: number; valid: number; invalid: number };
    rows: PostImportRow[];
}

export interface PostImportOutcomeRow extends PostImportRow {
    post_id: number | null;
}

export interface PostImportOutcome {
    summary: { created: number; skipped: number };
    rows: PostImportOutcomeRow[];
    repeated: boolean;
}
