// Representación que MediaAsset::toPublicArray entrega a las vistas y las APIs web.
export interface PublicMediaAsset {
    id: number;
    name: string;
    kind: string;
    mime: string;
    size: number;
    width: number | null;
    height: number | null;
    duration: number | null;
    fps: number | null;
    status: string;
    error: string | null;
    source: string;
    origin: {
        provider: 'canva';
        label: 'Canva';
        design_id: string | null;
        url: string | null;
        title: string | null;
    } | null;
    bytes: number | null;
    total: number | null;
    runs: number | null;
    preview_url: string | null;
    download_url?: string | null;
}
