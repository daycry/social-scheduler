<?php

use App\Models\Brand;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// C-04a (T-37, E2E-08): «Desde URL» en la seccion de media del compositor. La interaccion se cubre en Playwright (T-39).

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Alfa']);
    $this->vue = file_get_contents(resource_path('js/Components/Composer/MediaSection.vue'));
});

test('MediaImportUi: el compositor se renderiza y las rutas de importacion y sondeo existen', function () {
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Posts/Compose'));

    expect(route('media.import-url', absolute: false))->toBe('/media/import-url')
        ->and(route('media.show', 12, absolute: false))->toBe('/media/12');
});

test('MediaImportUi: el componente usa esas rutas y deja la URL fuera del DOM publico', function () {
    expect($this->vue)->toContain("route('media.import-url')")->toContain("route('media.show'")
        ->and($this->vue)->toContain('data-testid="media-import"')->toContain('data-testid="media-import-error"');
});

test('MediaImportUi: es accesible — etiqueta, ayuda, error anunciado y progreso con nombre', function () {
    expect($this->vue)
        ->toContain('<label for="media-import-url"')->toContain('id="media-import-url"')
        ->toContain('aria-describedby="media-import-help media-import-error"')
        ->toContain('role="alert"')->toContain('aria-live="polite"')
        ->toContain('<progress')->toContain('aria-label="`Progreso de');
});

test('MediaImportUi: el sondeo tiene candado, backoff, tolerancia a errores sueltos, cancelacion y aviso de atasco (revision F5)', function () {
    // El comportamiento real se ejecuta en Playwright (media-import.spec.ts, con page.route): aqui solo se fija que las piezas existen.
    expect($this->vue)->toContain('if (polling) return')->toContain('POLL_MAX_MS')->toContain('MAX_POLL_FAILURES')
        ->toContain("route('media.destroy'")->toContain('La cola de importación no está procesando');
});

test('MediaImportUi: sondea hasta «listo» o «invalido» y deja de sondear', function () {
    expect($this->vue)->toContain("asset.status === 'listo'")->toContain('onUploaded(asset, null)')
        ->toContain('stopPolling')->toContain('onBeforeUnmount')
        ->and($this->vue)->toContain('Descartar');
});
