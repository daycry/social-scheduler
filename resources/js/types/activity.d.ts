export interface ActivityEntry {
    id: number;
    action: string;
    outcome: string;
    channel: string;
    user: string;
    brand: string | null;
    created_at: string | null;
}

export interface ActivityFilters {
    brand_id: number | null;
    user_id: number | null;
    action: string | null;
    from: string | null;
    to: string | null;
}

export interface ActivityActor {
    id: number;
    name: string;
}
