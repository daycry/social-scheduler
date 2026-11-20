export interface CursorPage {
    limit?: number | string | null;
    cursor?: string | null;
    has_more: boolean;
    next_cursor: string | null;
    // Solo se incluye si el listado solicita el recuento.
    total?: number | null;
}
