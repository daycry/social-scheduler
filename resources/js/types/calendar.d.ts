// Props de Inertia de CalendarController, CalendarService y CalendarViewService.
export type CalendarViewMode = 'week' | 'month' | 'list';

export interface CalendarFilters {
    brand_id: number | string | null;
    network: string | null;
    status: string | null;
    q: string | null;
}

export type CalendarNavigation = Partial<CalendarFilters & { view: CalendarViewMode; date: string }>;

export interface SavedCalendarView {
    id: number;
    name: string;
    filters: { brand_id?: number; network?: string; status?: string; view?: CalendarViewMode };
    is_default: boolean;
}

export interface CalendarBrand {
    id: number;
    name: string;
    timezone: string;
}

export interface MarketingDate {
    date: string;
    name: string;
}

/** Feed .ics (T-90): `url` solo llega justo tras crear o regenerar el enlace; la base de datos guarda únicamente su hash. */
export interface CalendarFeedState {
    active: boolean;
    url: string | null;
    generated_at: string | null;
}

export interface CalendarExportSummary {
    total: number;
    max: number;
    truncated: boolean;
}

interface CalendarTarget {
    network: string;
    network_label: string;
    account_name: string | null;
    content_type: string;
    content_label: string;
    status: string;
    status_label: string;
    error_message: string | null;
    status_note: string | null;
    external_url: string | null;
    manual: boolean;
    manual_pending: boolean;
}

interface CalendarItemData {
    id: number;
    brand_id: number;
    brand_name: string | null;
    text: string;
    has_notes: boolean;
    status: string;
    status_label: string;
    editable: boolean;
    can_publish_now: boolean;
    approval_status: 'pendiente' | 'aprobado' | 'rechazado' | null;
    approval_signature: string | null;
    series_id: number | null;
    can_retry: boolean;
    retry_blocked: string | null;
    has_manual: boolean;
    manual_pending: boolean;
    targets: CalendarTarget[];
}

export type ScheduledCalendarItem = CalendarItemData & {
    scheduled_at: string;
    local_date: string;
    local_time: string;
    local_datetime: string;
};
export type UndatedCalendarItem = CalendarItemData & {
    scheduled_at: null;
    local_date: null;
    local_time: null;
    local_datetime: null;
};
export type CalendarItem = ScheduledCalendarItem | UndatedCalendarItem;

export interface CalendarBatchResult {
    ok: number;
    failed: number;
    replayed?: boolean;
    results: ({ post_id: number } & ({ ok: true } | { ok: false; error: string }))[];
}
