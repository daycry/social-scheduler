<?php

namespace App\Connectors\Exceptions;

/** La red rechaza definitivamente las credenciales: el usuario debe volver a conectar la cuenta. */
class ReconnectRequiredException extends ConnectorException {}
