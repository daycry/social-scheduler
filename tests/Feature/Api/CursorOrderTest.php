<?php

use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\StudioDocument;
use App\Services\Studio\StudioService;
use Tests\Support\McpClient;

uses(McpClient::class);

// Revision T-28/T-29 (7 y 8): los diseños se paginan por `id` (estable aunque se edite uno entre paginas) y el codigo fuente es UTF-8.

test('un diseño editado entre dos paginas no desaparece ni se repite: se ordena por id, no por updated_at', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $key = $this->mcpKey($user);
    $ids = [];
    foreach (range(1, 5) as $i) {
        $ids[] = app(StudioService::class)->create($user, $brand, ['name' => 'D'.$i, 'document' => StudioDocument::blank()])->id;
    }

    app('auth')->forgetGuards();
    $first = $this->withToken($key)->getJson('/api/v1/brands/'.$brand->id.'/designs?limit=2')->assertOk()->json();
    expect(array_column($first['designs'], 'id'))->toBe([$ids[4], $ids[3]])->and($first['has_more'])->toBeTrue();

    // El mas antiguo se edita entre paginas: con updated_at saltaria al principio y la segunda pagina perderia uno.
    Design::withoutUserScope()->whereKey($ids[0])->update(['name' => 'Editado', 'updated_at' => now()->addMinute()]);

    app('auth')->forgetGuards();
    $second = $this->withToken($key)->getJson('/api/v1/brands/'.$brand->id.'/designs?limit=2&cursor='.urlencode($first['next_cursor']))->assertOk()->json();
    app('auth')->forgetGuards();
    $third = $this->withToken($key)->getJson('/api/v1/brands/'.$brand->id.'/designs?limit=2&cursor='.urlencode($second['next_cursor']))->assertOk()->json();
    $seen = [...array_column($first['designs'], 'id'), ...array_column($second['designs'], 'id'), ...array_column($third['designs'], 'id')];

    expect($seen)->toBe(array_reverse($ids))->and($third['has_more'])->toBeFalse();
});

test('todo el codigo fuente (app, routes y config) y todos los tests estan en UTF-8 valido', function () {
    $bad = [];
    foreach (['app', 'routes', 'config', 'tests'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && ! mb_check_encoding((string) file_get_contents($file->getPathname()), 'UTF-8')) {
                $bad[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
    }

    expect($bad)->toBe([]);
});
