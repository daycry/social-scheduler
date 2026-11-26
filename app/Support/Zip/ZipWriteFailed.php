<?php

namespace App\Support\Zip;

use RuntimeException;

/** No se pudo escribir en el destino del ZIP (disco lleno, flujo cerrado). */
final class ZipWriteFailed extends RuntimeException {}
