interface BestTimeMeta {
    brand_id: number;
    network: string;
    timezone: string;
    posts: number;
    min_posts: number;
}

export type AvailableBestTimeSuggestion = BestTimeMeta & {
    available: true;
    reason: null;
    scheduled_at: string;
    scheduled_at_utc: string;
};

export type BestTimeSuggestion =
    | AvailableBestTimeSuggestion
    | (BestTimeMeta & {
          available: false;
          reason: string;
          scheduled_at: null;
          scheduled_at_utc: null;
      });

export interface BestTimeContext {
    brandId: number | null;
    network: string;
    timezone: string;
    value: string;
    disabled: boolean;
}
