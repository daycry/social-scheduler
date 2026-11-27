<?php

namespace App\Services\Rss;

use RuntimeException;

/** Fallo al leer un feed RSS/Atom. El mensaje es legible para el usuario y no incluye datos del feed. */
class RssException extends RuntimeException {}
