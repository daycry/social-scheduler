export interface PushState {
    enabled: boolean;
    public_key: string | null;
    unavailable_reason: string | null;
}

export interface PushDevice {
    id: number;
    device_name: string;
    last_seen_at: string;
    last_sent_at: string | null;
    current: boolean;
}

export interface NotificationPreferences {
    notify_weekly_digest: boolean;
    weekly_digest_ai: boolean;
    notify_manual_reminder: boolean;
}

export type NotificationChannels = Record<string, Partial<Record<'database' | 'push' | 'mail', boolean>>>;
