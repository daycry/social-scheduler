import type { BrandWithTimezone } from './shell';
import type { CursorPage } from './pagination';

export type GoalKind = 'milestone' | 'drop' | 'token_expiry';
export type GoalMetric = 'followers' | 'engagement';

export interface GoalInput {
    name: string;
    kind: GoalKind;
    metric: GoalMetric | null;
    threshold: number | string;
    social_account_id: number | null;
    enabled: boolean;
    mail_enabled: boolean;
}

export interface GoalView extends Omit<GoalInput, 'threshold'> {
    id: number;
    threshold: number;
    account_name: string | null;
    account_requires_reconnection: boolean;
    last_value: number | null;
    last_period: string | null;
    last_evaluated_at: string | null;
    last_expires_at: string | null;
}

export interface GoalsPageProps {
    brands: BrandWithTimezone[];
    brand: BrandWithTimezone | null;
    goals: GoalView[];
    accounts: { id: number; name: string; network_label: string }[];
    kinds: Record<GoalKind, string>;
    metrics: Record<GoalMetric, string>;
    limits: { min_integer: number; min_decimal: number; max: Record<GoalKind, number> };
    pagination: CursorPage;
}
