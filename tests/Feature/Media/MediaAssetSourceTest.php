<?php

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Schema;

// C-04a (T-34): origen de la media (upload|url|drive), referencia y estado de importacion.

test('MediaAssetSource: las columnas nuevas existen y los assets nuevos son «upload»', function () {
    expect(Schema::hasColumns('media_assets', ['source', 'source_ref', 'import_state']))->toBeTrue();

    $asset = MediaAsset::factory()->create();

    expect($asset->fresh()->source)->toBe('upload')->and($asset->fresh()->source_ref)->toBeNull()->and($asset->fresh()->import_state)->toBeNull();
});

test('MediaAssetSource: import_state se guarda como JSON', function () {
    $asset = MediaAsset::factory()->create(['source' => 'url', 'source_ref' => 'https://e.com/a.jpg', 'import_state' => ['bytes' => 10, 'total' => 100]]);

    expect($asset->fresh()->import_state)->toBe(['bytes' => 10, 'total' => 100])->and($asset->fresh()->source)->toBe('url');
});

test('MediaAssetSource: toPublicArray expone el progreso sin filtrar la URL ni el estado interno', function () {
    $asset = MediaAsset::factory()->create([
        'status' => MediaAsset::STATUS_PENDING,
        'source' => 'url',
        'source_ref' => 'https://e.com/a.jpg',
        'import_state' => ['bytes' => 50, 'total' => 200, 'validator' => '"etag"', 'url' => 'https://e.com/a.jpg?token=SECRETO'],
    ]);

    $public = $asset->toPublicArray();

    expect($public)->toMatchArray(['source' => 'url', 'bytes' => 50, 'total' => 200])
        ->and(json_encode($public))->not->toContain('SECRETO')->not->toContain('e.com')->not->toContain('etag')
        ->and($public)->not->toHaveKeys(['source_ref', 'import_state']);
});

test('MediaAssetSource: una subida directa no lleva progreso', function () {
    $public = MediaAsset::factory()->create()->toPublicArray();

    expect($public['source'])->toBe('upload')->and($public['bytes'])->toBeNull()->and($public['total'])->toBeNull();
});

test('MediaAssetSource: la configuracion trae el presupuesto y la cola «media»', function () {
    expect(config('media.import_budget_seconds'))->toBe(80)
        ->and(config('media.import_queue'))->toBe('media')
        ->and(config('queue.names'))->toContain('media');
});
