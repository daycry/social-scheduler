<?php

namespace App\Services\Export;

use RuntimeException;

/** No hay espacio libre para preparar el ZIP. El mensaje es legible para el usuario. */
final class InsufficientSpace extends RuntimeException {}
