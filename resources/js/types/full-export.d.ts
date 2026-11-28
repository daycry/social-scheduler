export type FullExportStatus = 'pending' | 'processing' | 'ready' | 'failed' | 'expired';

export interface FullExportLatest {
    status: FullExportStatus;
    error: string | null;
    include_media: boolean;
    created_at: string;
}

export interface FullExportDownload {
    url: string;
    size: number | null;
    file_count: number | null;
    include_media: boolean;
    expires_at: string;
}

export interface FullExportLimits {
    max_gb: number;
    retention_hours: number;
    link_minutes: number;
}
