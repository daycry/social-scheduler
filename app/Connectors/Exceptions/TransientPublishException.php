<?php

namespace App\Connectors\Exceptions;

/** Fallo pasajero (5xx, red, timeout). Puede que la red YA haya publicado: el reintento consulta antes de publicar. */
class TransientPublishException extends PublishException {}
