<?php

namespace App\Connectors\Exceptions;

/** La red rechaza el contenido de forma definitiva (formato, permisos, politica): no se reintenta. */
class PermanentPublishException extends PublishException {}
