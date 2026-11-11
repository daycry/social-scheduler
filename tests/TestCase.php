<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ninguna prueba sale a la red: toda peticion HTTP debe estar cubierta por `Http::fake`.
        // Las que necesitan un servidor local (p. ej. UrlSourceRealHandlerTest) lo declaran con `Http::allowStrayRequests()`.
        Http::preventStrayRequests();

        // Los binarios nativos (ffmpeg, Chromium...) no se sondean salvo que la prueba lo pida: no dependen del equipo que ejecuta la suite.
        config(['health.native' => false]);
    }
}
