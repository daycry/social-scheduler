<?php

namespace App\Connectors\Exceptions;

/** La publicacion no admite comentarios (cerrados, video privado o «hecho para ninos»): no se reintenta ni se avisa como error. */
class CommentNotAllowedException extends PermanentPublishException {}
