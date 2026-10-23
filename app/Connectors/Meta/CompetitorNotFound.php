<?php

namespace App\Connectors\Meta;

use RuntimeException;

/** Business Discovery no encuentra la cuenta (no existe, es personal o privada). El mensaje es apto para el usuario. */
class CompetitorNotFound extends RuntimeException {}
