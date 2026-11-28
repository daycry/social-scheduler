<?php

use App\Console\Commands\ReencryptSecrets;
use App\Models\Brand;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

test('la simulación cuenta los registros anteriores sin modificar sus valores cifrados', function () {
    $cipher = config('app.cipher');
    $old = new Encrypter(str_repeat('o', 32), $cipher);
    $new = new Encrypter(str_repeat('n', 32), $cipher);
    $new->previousKeys([$old->getKey()]);
    Crypt::swap($new);

    $brand = Brand::factory()->create();
    $social = SocialAccount::factory()->create(['brand_id' => $brand->id]);
    $alreadyCurrent = SocialAccount::factory()->create(['brand_id' => $brand->id]);
    $socialValues = [
        'access_token' => $old->encryptString('old-access-value'),
        'refresh_token' => $old->encryptString('old-refresh-value'),
        'credential_secret' => null,
    ];
    DB::table('social_accounts')->where('id', $social->id)->update($socialValues);
    DB::table('social_accounts')->where('id', $alreadyCurrent->id)->update([
        'access_token' => $new->encryptString('current-access-value'),
        'refresh_token' => null,
    ]);
    $canva = $old->encryptString('canva-access-value');
    $canvaId = DB::table('canva_connections')->insertGetId([
        'user_id' => $brand->user_id,
        'brand_id' => $brand->id,
        'access_token' => $canva,
    ]);

    $this->artisan('app:reencrypt --dry-run')
        ->expectsOutput('Registros que se recifrarían: 2')
        ->doesntExpectOutput('old-access-value')
        ->assertSuccessful();

    $this->assertDatabaseHas('social_accounts', ['id' => $social->id, ...$socialValues]);
    $this->assertDatabaseHas('canva_connections', ['id' => $canvaId, 'access_token' => $canva]);
});

test('recifra todos los secretos persistidos y una segunda ejecución no los cambia', function () {
    $cipher = config('app.cipher');
    $old = new Encrypter(str_repeat('o', 32), $cipher);
    $current = new Encrypter(str_repeat('n', 32), $cipher);
    Crypt::swap((new Encrypter($current->getKey(), $cipher))->previousKeys([$old->getKey()]));

    $brand = Brand::factory()->create();
    $social = SocialAccount::factory()->create(['brand_id' => $brand->id]);
    $oldSocial = [
        'access_token' => $old->encryptString('old-access-value'),
        'refresh_token' => $current->encryptString('current-refresh-value'),
        'credential_secret' => $old->encryptString('old-credential-value'),
    ];
    DB::table('social_accounts')->where('id', $social->id)->update($oldSocial);
    $canvaId = DB::table('canva_connections')->insertGetId([
        'user_id' => $brand->user_id,
        'brand_id' => $brand->id,
        'access_token' => $old->encryptString('canva-access-value'),
        'refresh_token' => $old->encryptString('canva-refresh-value'),
    ]);
    DB::table('users')->where('id', $brand->user_id)->update([
        'two_factor_secret' => $old->encryptString('totp-secret-value'),
    ]);

    $this->artisan('app:reencrypt')->expectsOutput('Registros recifrados: 3')->assertSuccessful();

    $socialAfter = DB::table('social_accounts')->where('id', $social->id)->first();
    $canvaAfter = DB::table('canva_connections')->where('id', $canvaId)->first();
    $totp = DB::table('users')->where('id', $brand->user_id)->value('two_factor_secret');

    expect($current->decryptString($socialAfter->access_token))->toBe('old-access-value')
        ->and($socialAfter->refresh_token)->toBe($oldSocial['refresh_token'])
        ->and($current->decryptString($socialAfter->credential_secret))->toBe('old-credential-value')
        ->and($current->decryptString($canvaAfter->access_token))->toBe('canva-access-value')
        ->and($current->decryptString($canvaAfter->refresh_token))->toBe('canva-refresh-value')
        ->and($current->decryptString($totp))->toBe('totp-secret-value');

    $this->artisan('app:reencrypt')->expectsOutput('Registros recifrados: 0')->assertSuccessful();
    $this->assertDatabaseHas('social_accounts', ['id' => $social->id, 'access_token' => $socialAfter->access_token]);
    $this->assertDatabaseHas('canva_connections', ['id' => $canvaId, 'access_token' => $canvaAfter->access_token]);
});

test('un valor ilegible detiene la rotación y conserva la fila anterior', function () {
    $cipher = config('app.cipher');
    $old = new Encrypter(str_repeat('o', 32), $cipher);
    $current = new Encrypter(str_repeat('n', 32), $cipher);
    Crypt::swap($current->previousKeys([$old->getKey()]));

    $brand = Brand::factory()->create();
    $first = SocialAccount::factory()->create(['brand_id' => $brand->id]);
    $second = SocialAccount::factory()->create(['brand_id' => $brand->id]);
    $original = $old->encryptString('old-access-value');
    DB::table('social_accounts')->where('id', $first->id)->update(['access_token' => $original]);
    DB::table('social_accounts')->where('id', $second->id)->update(['access_token' => 'unreadable-ciphertext']);
    $message = "No se pudo descifrar social_accounts.access_token (id={$second->id}).";

    $this->artisan('app:reencrypt --dry-run')->expectsOutput($message)->assertFailed();
    $this->artisan('app:reencrypt')->expectsOutput($message)->assertFailed();

    $this->assertDatabaseHas('social_accounts', ['id' => $first->id, 'access_token' => $original]);
    $this->assertDatabaseHas('social_accounts', ['id' => $second->id, 'access_token' => 'unreadable-ciphertext']);
});

test('el inventario del recifrado cubre los casts cifrados de los modelos', function () {
    $discovered = [];
    foreach (File::allFiles(app_path('Models')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relativeClass = str_replace(['/', DIRECTORY_SEPARATOR], '\\', substr($file->getRelativePathname(), 0, -4));
        $class = 'App\\Models\\'.$relativeClass;
        if (! is_subclass_of($class, Model::class) || ! (new ReflectionClass($class))->isInstantiable()) {
            continue;
        }

        $model = new $class;
        $encrypted = array_keys(array_filter($model->getCasts(), fn ($cast) => str_starts_with($cast, 'encrypted')));
        if ($encrypted !== []) {
            expect($model->getConnectionName())->toBeNull();
            expect($model::currentEncrypter())->toBe(Crypt::getFacadeRoot());
            sort($encrypted);
            $discovered[$model->getTable()] = $encrypted;
        }
    }

    $declared = (new ReflectionClass(ReencryptSecrets::class))->getConstant('COLUMNS');
    foreach ($declared as &$columns) {
        sort($columns);
    }
    unset($columns);
    ksort($discovered);
    ksort($declared);

    expect($discovered)->toBe($declared);
});

test('sin claves anteriores la simulación avisa y la ejecución conserva el valor', function () {
    $cipher = config('app.cipher');
    $old = new Encrypter(str_repeat('o', 32), $cipher);
    Crypt::swap(new Encrypter(str_repeat('n', 32), $cipher));

    $account = SocialAccount::factory()->create();
    $ciphertext = $old->encryptString('previous-access-value');
    DB::table('social_accounts')->where('id', $account->id)->update(['access_token' => $ciphertext]);
    $message = "No se pudo descifrar social_accounts.access_token (id={$account->id}).";

    $this->artisan('app:reencrypt --dry-run')->expectsOutput($message)->assertFailed();
    $this->artisan('app:reencrypt')->expectsOutput($message)->assertFailed();

    $this->assertDatabaseHas('social_accounts', ['id' => $account->id, 'access_token' => $ciphertext]);
});
