import type { BrandRef } from './shell';

// Datos de DashboardController, AnalyticsService y AggregateAnalytics.
type MetricValue = number | null;
type MetricValues = Partial<Record<string, MetricValue>>;

export interface AnalyticsFilters {
    brand_id: number | null;
    from: string;
    to: string;
    compare: 'none' | 'previous' | 'year';
}

type Period = Pick<AnalyticsFilters, 'from' | 'to'> & { days: number };
export interface ConnectedNetwork {
    value: string;
    label: string;
    accounts: number;
}

export interface MetricCoverage {
    status: 'complete' | 'partial' | 'no_data' | 'not_due' | 'unsupported' | 'unavailable_sandbox' | 'unavailable_access';
    expected_account_days: number;
    observed_account_days: number;
    missing_account_days: number;
}

export interface MetricFreshness {
    latest_data_date: string | null;
    latest_expected_date: string | null;
    is_stale: boolean | null;
}

export interface ComparisonQuality {
    status: 'not_requested' | 'unsupported' | 'not_due' | 'unequal_coverage' | 'matched_partial' | 'complete';
    previous_coverage: MetricCoverage | null;
    previous_freshness: MetricFreshness | null;
}

interface MetricQuality {
    coverage: MetricCoverage;
    freshness: MetricFreshness;
    comparison_quality: ComparisonQuality;
}

export interface MetricBreakdown extends MetricQuality {
    network: string;
    label: string;
    value: MetricValue;
    previous: MetricValue;
    delta_pct: MetricValue;
    available: boolean;
}

interface MetricTotals extends MetricQuality {
    key: string;
    label: string;
    kind: 'stock' | 'flow';
    total: MetricValue;
    previous: MetricValue;
    delta_pct: MetricValue;
    available: boolean;
}

export interface SummaryMetric extends MetricTotals {
    by_network: MetricBreakdown[];
    series: { date: string; total: MetricValue; values: MetricValues | [] }[];
}

export interface DashboardSummary {
    period: Period;
    comparison: Period | null;
    networks: ConnectedNetwork[];
    cards: SummaryMetric[];
}

export interface AggregateDashboardSummary extends DashboardSummary {
    brands: (BrandRef & { networks: number; values: MetricValues; previous: MetricValues })[];
}

interface ChartPoint {
    date: string;
    value: MetricValue;
}

export interface ChartSeries {
    name: string;
    color: string;
    points: ChartPoint[];
}

export interface NetworkMetric extends MetricTotals {
    series: ChartPoint[];
}

interface Growth {
    start: MetricValue;
    end: MetricValue;
    change: MetricValue;
    pct: MetricValue;
    status: 'insufficient' | 'partial' | 'complete';
    unmeasured_accounts: number;
}

interface DailyBasis {
    observed_days: number;
    requested_days: number;
}

interface MetricRates {
    views: MetricValue;
    interactions: MetricValue;
}

interface NetworkPost {
    id: number;
    text: string | null;
    content_type: string;
    published_at: string | null;
    url: string | null;
    metrics: MetricValues;
    has_data: boolean;
    engagement_rate: MetricValue;
}

export interface NetworkDetail {
    network: Pick<ConnectedNetwork, 'value' | 'label'>;
    accounts: { id: number; name: string; username: string | null; status: string }[];
    period: Period;
    comparison: Period | null;
    growth: Growth & { previous: Growth | null };
    metrics: NetworkMetric[];
    kpis: {
        per_day: MetricRates;
        per_day_basis: { views: DailyBasis; interactions: DailyBasis };
        engagement_rate: MetricValue;
        per_post: MetricRates & { engagement_rate: MetricValue; posts: number };
    };
    posts: NetworkPost[];
    posts_total: number;
    posts_limit: number;
    posts_truncated: boolean;
}
