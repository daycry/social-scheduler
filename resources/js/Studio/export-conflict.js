// Resultado de un fallo al exportar o al usar el diseño en una publicacion.
// Un 409 significa que el diseño cambio desde que se abrio (la revision pedida ya no existe): el editor abre el dialogo de conflicto
// existente en lugar de mostrar un error generico.
export function exportFailure(error) {
    return {
        conflict: error?.status === 409,
        message: error?.message || 'No se pudo completar la exportación.',
    };
}
