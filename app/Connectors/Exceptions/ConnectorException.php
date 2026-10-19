<?php

namespace App\Connectors\Exceptions;

use RuntimeException;

/** Error de un conector. El mensaje es apto para mostrarse al usuario (sin secretos). */
class ConnectorException extends RuntimeException {}
