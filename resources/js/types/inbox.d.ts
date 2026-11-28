// Props de Inertia del InboxController.
export interface InboxFilters {
    network: string | null;
    status: 'open' | 'closed' | null;
    assigned: string | null;
    label: string | null;
    unread: boolean;
    hidden: boolean | null;
    has_notes: boolean;
}

export interface InboxNote {
    id: number;
    body: string;
    at: string | null;
}

export interface InboxComment {
    id: number;
    network: string;
    author: string | null;
    text: string | null;
    commented_at: string | null;
    read: boolean;
    hidden: boolean;
    closed: boolean;
    can_hide: boolean;
    assigned_user_id: number | null;
    labels: string[];
    notes: InboxNote[];
    is_reply: boolean;
    account: string | null;
    post_text: string | null;
    post_url: string | null;
    my_replies: { id: number; text: string | null; at: string | null }[];
}

export interface InboxPage {
    data: InboxComment[];
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface InboxAccount {
    id: number;
    brand_id: number;
    network: string;
    name: string;
    can_read: boolean;
    can_moderate: boolean;
    can_hide: boolean;
    missing_scopes: string[];
    help: string | null;
    needs_reconnect: boolean;
}

export interface InboxCoverage {
    network: string;
    label: string;
    read: boolean;
    reply: boolean;
    hide: boolean;
    note: string;
}

export interface SavedReply {
    id: number;
    title: string;
    body: string;
}
