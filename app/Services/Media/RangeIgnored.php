<?php

namespace App\Services\Media;

use RuntimeException;

/** Interna: el servidor respondio 200 a una peticion con `Range`, asi que el fichero cambio o no admite rangos. */
final class RangeIgnored extends RuntimeException {}
