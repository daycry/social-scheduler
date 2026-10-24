<?php

namespace App\Services\Media;

use RuntimeException;

/** La importacion remota no puede continuar (tipo, tamano, respuesta del servidor...). El mensaje se guarda en `media_assets.error`. */
class ImportFailedException extends RuntimeException {}
