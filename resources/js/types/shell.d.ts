import type { PageFlashData, PageProps } from '@inertiajs/core';

export interface BrandRef {
    id: number;
    name: string;
}

export type BrandWithTimezone = BrandRef & { timezone: string | null };

interface ShellBrand extends BrandRef {
    avatar_url: string | null;
}

interface ShellAccount {
    id: number;
    name: string;
    username: string | null;
    status: string;
}

export interface NetworkOption {
    value: string;
    label: string;
    short_label: string;
    color: string;
    provider: string;
    auth_kind: string;
}

// HandleInertiaRequests comparte el shell con el layout autenticado y el catálogo de redes.
export interface Shell {
    brands: ShellBrand[];
    currentBrand: (ShellBrand & { timezone: string }) | null;
    accounts: Partial<Record<string, ShellAccount[]>>;
    networks: NetworkOption[];
}

export interface FlashMessages {
    success?: string | null;
    error?: string | null;
}

export interface SessionFlash extends FlashMessages {
    publishing_post_id?: number | string | null;
}

export interface ShellPageProps extends PageProps {
    canViewSystemStatus?: boolean;
    shell?: Shell | null;
    auth: { user: { name: string; email: string } };
    // Avisos de la sesión de Laravel compartidos por HandleInertiaRequests.
    flash?: SessionFlash;
    unreadInbox?: number;
    unreadNotifications?: number;
    pendingConnections?: { brand_id: number; brand_name: string }[];
    // Vista de Calendario, que usa la navegación para distinguir semana y lista.
    view?: string;
}

declare module '@inertiajs/core' {
    interface InertiaConfig {
        // Avisos del cliente, como router.flash en useStudioCreation.
        flashDataType: FlashMessages & PageFlashData;
    }
}
