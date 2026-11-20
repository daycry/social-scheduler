<?php

use App\Providers\AppServiceProvider;
use App\Providers\PushServiceProvider;
use App\Providers\WebhookServiceProvider;

return [
    AppServiceProvider::class,
    WebhookServiceProvider::class,
    PushServiceProvider::class,
];
