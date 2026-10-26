<?php

use App\Models\Brand;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// C-04b (T-43, CA-18): la configuracion del Picker solo llega a la pagina si esta configurado.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

test('sin GOOGLE_PICKER_API_KEY no se expone ninguna configuracion de Drive', function () {
    config(['services.google.picker_api_key' => null, 'services.google.client_id' => 'cid.apps.googleusercontent.com', 'services.google.picker_app_id' => '123']);

    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Posts/Compose')->where('drivePicker', null));
});

test('B-1: sin GOOGLE_PICKER_APP_ID no se expone la configuracion (no hay boton: el Picker con drive.file necesita setAppId)', function () {
    config(['services.google.picker_api_key' => 'pk', 'services.google.client_id' => 'cid.apps.googleusercontent.com', 'services.google.picker_app_id' => null]);

    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('drivePicker', null));

    config(['services.google.picker_app_id' => '']);
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))->assertInertia(fn (Assert $p) => $p->where('drivePicker', null));
});

test('sin client id tampoco (el aud del token no se podria verificar)', function () {
    config(['services.google.picker_api_key' => 'k', 'services.google.picker_app_id' => '1', 'services.google.client_id' => null]);

    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertInertia(fn (Assert $p) => $p->where('drivePicker', null));
});

test('con la clave configurada se exponen clave, app id y client id, y nunca el secreto', function () {
    config(['services.google.picker_api_key' => 'pk', 'services.google.picker_app_id' => '987', 'services.google.client_id' => 'cid.apps.googleusercontent.com', 'services.google.client_secret' => 'TOP-SECRET']);

    $res = $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('drivePicker', ['api_key' => 'pk', 'app_id' => '987', 'client_id' => 'cid.apps.googleusercontent.com']));

    expect($res->getContent())->not->toContain('TOP-SECRET');
});

test('el composable pide solo drive.file, carga los scripts al usarlo y no guarda el token', function () {
    $js = file_get_contents(resource_path('js/Composables/useDrivePicker.js'));

    expect($js)->toContain('https://www.googleapis.com/auth/drive.file')->not->toContain('drive.readonly')
        ->and($js)->not->toContain('localStorage')->not->toContain('sessionStorage')
        ->and($js)->toContain('const pick = async')->toContain('loadScript(GIS_SRC')
        // B-1: el app id es obligatorio; B-2: tiempo maximo y onerror en gapi.load; B-3: precarga para no perder el gesto del usuario.
        ->and($js)->toContain('!!getConfig()?.app_id')->toContain('.setAppId(config.app_id)')->toContain('onerror:')->toContain('ontimeout:')->toContain('const preload');
});
