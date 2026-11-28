<?php

namespace App\Connectors\Exceptions;

/**
 * Falta un permiso OPCIONAL de la cuenta (p. ej. comentar): hay que reconectar para activarlo, pero la cuenta sigue sirviendo para
 * publicar y NO se marca como `requiere_reconexion` (no bloquea lo programado).
 */
class ScopeMissingException extends ReconnectRequiredException {}
