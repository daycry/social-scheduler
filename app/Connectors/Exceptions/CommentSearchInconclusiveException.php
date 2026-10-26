<?php

namespace App\Connectors\Exceptions;

/**
 * La busqueda acotada del comentario propio se agoto (paginas o presupuesto de tiempo) sin llegar al final de la lista: no se sabe
 * si el comentario ya existe. Es transitoria para el job, que NO crea otro a ciegas cuando un intento previo pudo enviarlo.
 */
class CommentSearchInconclusiveException extends TransientPublishException {}
