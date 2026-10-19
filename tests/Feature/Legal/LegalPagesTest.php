<?php

test('privacy and terms pages are public', function (string $path, string $heading) {
    config(['legal.contact_email' => 'contacto@example.com']);

    $this->get($path)->assertOk()->assertSee($heading)->assertSee('contacto@example.com');
})->with([
    'privacy' => ['/privacy', 'Política de privacidad'],
    'terms' => ['/terms', 'Condiciones del servicio'],
]);
