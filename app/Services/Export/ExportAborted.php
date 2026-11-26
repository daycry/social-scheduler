<?php

namespace App\Services\Export;

use RuntimeException;

/** La fila de la exportación ya no existe o ya no está en curso: el job para sin publicar nada. */
final class ExportAborted extends RuntimeException {}
