<?php

namespace App\Support\Zip;

use RuntimeException;

/** No se pudo leer un fichero de origen que había que meter en el ZIP (no existe, ilegible, cambió mientras se leía). */
final class ZipSourceFailed extends RuntimeException {}
