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
