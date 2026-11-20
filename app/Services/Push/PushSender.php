<?php

namespace App\Services\Push;

use App\Models\PushSubscription;

/** El adaptador solo envía un aviso genérico; nunca recibe contenido privado del aviso in-app. */
interface PushSender
{
    public function send(PushSubscription $subscription): PushResult;
}
