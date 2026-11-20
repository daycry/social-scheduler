<?php

use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

test('ownedBy devuelve solo las filas del usuario indicado, sin depender del autenticado', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $mine = Brand::factory()->for($owner)->create();
    Brand::factory()->for($other)->create();

    Auth::login($other);

    expect(Brand::ownedBy($owner)->pluck('id')->all())->toBe([$mine->id])
        ->and(Brand::ownedBy($owner->id)->pluck('id')->all())->toBe([$mine->id]);
});

test('ownedBy no filtra filas de otros usuarios aunque se encadene con otros filtros', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $theirs = Brand::factory()->for($other)->create();

    expect(Brand::ownedBy($owner)->whereKey($theirs->id)->exists())->toBeFalse();
});

test('no reaparece el patron withoutUserScope()->where(user_id) en app/', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (manuallySelectsUserId((string) file_get_contents($file->getPathname()))) {
            $offenders[] = substr($file->getPathname(), strlen(base_path()) + 1);
        }
    }

    expect($offenders)->toBe([], 'Usa ownedBy($user) en lugar de withoutUserScope()->where(\'user_id\', ...): '.implode(', ', $offenders));
});

/** Las exclusiones de diseños propios no equivalen a filtrar por un propietario. */
function manuallySelectsUserId(string $source): bool
{
    $pattern = <<<'REGEX'
~withoutUserScope\(\)\s*->where\(\s*['"](?:[a-z_][a-z0-9_]*\.)?user_id['"]\s*,(?!\s*['"](?:!=|<>)['"]\s*,)~i
REGEX;

    return preg_match($pattern, $source) === 1;
}

test('la guardia distingue la selección de propietario de su exclusión explícita', function () {
    expect(manuallySelectsUserId("withoutUserScope()->where('user_id', 1)"))->toBeTrue()
        ->and(manuallySelectsUserId("withoutUserScope()->where('posts.user_id', '=', 1)"))->toBeTrue()
        ->and(manuallySelectsUserId("withoutUserScope()->where('posts.user_id', '!=')"))->toBeTrue()
        ->and(manuallySelectsUserId("withoutUserScope()->where('studio_designs.user_id', '!=', 1)"))->toBeFalse()
        ->and(manuallySelectsUserId("withoutUserScope()->where('studio_designs.user_id', '<>', 1)"))->toBeFalse();
});
