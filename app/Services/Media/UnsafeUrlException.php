<?php

namespace App\Services\Media;

use InvalidArgumentException;

/** La URL no es segura de pedir (SSRF) o no es valida. El mensaje esta en espanol y se puede mostrar al usuario. */
class UnsafeUrlException extends InvalidArgumentException {}
