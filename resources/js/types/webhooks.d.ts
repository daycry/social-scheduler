import type { BrandRef } from './shell';
import type { CursorPage } from './pagination';

export type WebhookEventName = 'post.published' | 'post.failed' | 'comment.created';
export type DeliveryState = 'pending' | 'delivered' | 'blocked' | 'failed' | 'exhausted' | 'cancelled';

export interface WebhookInput {
    name: string;
    url: string;
    secret: string;
    events: WebhookEventName[];
    enabled: boolean;
}

export interface WebhookView extends Omit<WebhookInput, 'secret'> {
    id: number;
    last_delivery: {
        status: DeliveryState;
        attempts: number;
        http_status: number | null;
        reason: string | null;
        next_attempt_at: string | null;
        updated_at: string;
    } | null;
}

export interface WebhooksPageProps {
    brands: BrandRef[];
    brand: BrandRef | null;
    webhooks: WebhookView[];
    events: Record<WebhookEventName, string>;
    delivery_states: Record<DeliveryState, string>;
    max_per_user: number;
    pagination: CursorPage;
}
