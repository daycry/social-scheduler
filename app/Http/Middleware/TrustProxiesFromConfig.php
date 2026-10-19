<?php

namespace App\Http\Middleware;

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;

/**
 * Lee los proxies de confianza de config('app.trusted_proxies') en cada peticion.
 *
 * El callback de withMiddleware() corre antes de cargar .env (y nunca lo lee con config:cache),
 * asi que TrustProxies::at(env(...)) ignoraba TRUSTED_PROXIES del .env.
 */
class TrustProxiesFromConfig extends TrustProxies
{
    protected function proxies()
    {
        return static::$alwaysTrustProxies ?: TrustedProxies::parse(config('app.trusted_proxies'));
    }
}
