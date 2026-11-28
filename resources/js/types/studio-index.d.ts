import type { PublicMediaAsset } from './media-asset';
import type { CursorPage } from './pagination';
import type { BrandWithTimezone } from './shell';

interface DesignListRow {
    id: number;
    user_id: number;
    brand_id: number;
    folder_id: number | null;
    name: string;
    revision: number;
    is_template: boolean;
    width: number;
    height: number;
    page_count: number;
    created_at: string | null;
    updated_at: string | null;
    deleted_at: string | null;
    thumbnail_url: string | null;
    thumbnail_status: 'ready' | 'pending' | 'failed' | 'off';
}

export interface StudioKit {
    palette: string[];
    fonts: string[];
    font_ids?: (number | string | null)[] | null;
    logos: (number | string)[];
    guidelines: string | null;
    voice: string | null;
}

export interface StudioFont {
    id: number;
    brand_id: number;
    name: string;
    family: string;
    style: string;
    weight: number;
    format: string;
    mime: string;
    size: number;
    sha256: string;
    status: string;
    archived_at: string | null;
    css_family: string;
    file_url: string;
}

interface StudioIndexFilters {
    // Parámetros de query del GET. Laravel los valida sin convertir los valores.
    query?: string | null;
    folder_id?: string | null;
    limit?: string | null;
    cursor?: string | null;
    templates?: '0' | '1' | null;
    archived?: '0' | '1' | null;
}

export interface SharedDesign {
    id: number;
    name: string;
    page_count: number;
    role: 'reader' | 'commenter' | 'editor' | 'approver';
    role_label: string;
    owner_name: string;
    timezone: string | null;
    updated_at: string;
}

// StudioController::index y StudioEditor::brandProps entregan el listado y los recursos de marca.
export interface StudioIndexPageProps {
    brand: BrandWithTimezone | null;
    // Sin marca, designs.designs está vacío y los metadatos de paginación conservan su forma.
    designs: CursorPage & { total: number; designs: DesignListRow[] };
    kit: StudioKit | null;
    assets: (PublicMediaAsset & { preview_url: string })[];
    folders: { id: number; name: string; designs_count: number }[];
    filters: StudioIndexFilters | [];
    // Sin marca se omite y Vue aplica el valor por defecto.
    fonts?: StudioFont[];
    shared: SharedDesign[];
}
