<?php

namespace App\Support\Zip;

use RuntimeException;

/** El ZIP superaría el tamaño máximo que se le ha dado al escritor. */
final class ZipTooLarge extends RuntimeException {}
