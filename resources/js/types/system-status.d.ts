export interface SystemStatusReport {
    observed_at: string;
    network_labels: Record<string, string>;
    native_cache_seconds: number;
    queues: { name: string; last_processed_at: string | null; available: boolean }[];
    tokens_available: boolean;
    health: {
        ok: boolean;
        status: 'ok' | 'degraded' | 'down';
        checks: Record<string, { ok: boolean; detail: string; warn?: boolean }>;
    };
    tokens: Record<
        string,
        {
            account_count: number;
            expires_at: string | null;
            refresh_expires_at: string | null;
            data_access_expires_at: string | null;
            unknown_expiry_count: number;
        }
    >;
    quotas: Record<
        string,
        {
            source: 'unavailable' | 'local_counter';
            used: number | null;
            limit: number | null;
            resets_at: string | null;
            observed_at: string | null;
            unavailable_reason: 'not_recorded' | 'read_failed' | null;
        }
    >;
    quota_policy: 'reschedule' | 'fail';
}
