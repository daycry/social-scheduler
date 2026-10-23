<?php

use Inertia\Testing\AssertableInertia as Assert;

test('CA-10: sin credenciales no hay botones y la redireccion da 404', function () {
    config(['services.google_login.client_id' => null, 'services.facebook_login.client_id' => null]);

    $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('socialProviders', []));
    $this->get('/auth/google/redirect')->assertNotFound();
    $this->get('/auth/facebook/redirect')->assertNotFound();
});

test('CA-10: solo aparece el proveedor configurado', function () {
    config([
        'services.google_login.client_id' => 'id',
        'services.google_login.client_secret' => 'secret',
        'services.facebook_login.client_id' => null,
        'services.facebook_login.client_secret' => null,
    ]);

    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('socialProviders', ['google']));
});

test('con los dos proveedores configurados llegan en orden estable y sin secretos', function () {
    config([
        'services.google_login.client_id' => 'id-g',
        'services.google_login.client_secret' => 'sec-g',
        'services.facebook_login.client_id' => 'id-f',
        'services.facebook_login.client_secret' => 'sec-f',
    ]);

    $response = $this->get('/login');
    $response->assertInertia(fn (Assert $page) => $page->where('socialProviders', ['google', 'facebook']));
    expect($response->getContent())->not->toContain('sec-g')->not->toContain('sec-f');
});
