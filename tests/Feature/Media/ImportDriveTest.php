<?php

use App\Jobs\ImportRemoteMedia;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\UrlGuard;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// C-04b (T-42, CA-18): endpoint de importacion desde Drive. Nunca hay red: `Http::fake` y DNS inyectado.

const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'services.google.client_id' => 'client-123.apps.googleusercontent.com', 'services.google.picker_api_key' => 'test-key', 'services.google.picker_app_id' => '1234567890']);
    Http::preventStrayRequests();
    Queue::fake();
    $this->user = User::factory()->create();
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['142.250.200.74']));
});

function fakeDrive(array $tokeninfo = [], array $meta = [], int $metaStatus = 200, int $tokenStatus = 200): void
{
    Http::swap(new Factory); // un nuevo fake sustituye al anterior (los stubs de Http::fake se acumulan y gana el primero)
    Http::preventStrayRequests();
    Http::fake([
        'oauth2.googleapis.com/tokeninfo' => Http::response($tokenStatus === 200
            ? $tokeninfo + ['aud' => 'client-123.apps.googleusercontent.com', 'scope' => DRIVE_SCOPE, 'expires_in' => '3000']
            : ['error' => 'invalid_token'], $tokenStatus),
        'www.googleapis.com/drive/v3/files/*' => Http::response($meta + ['name' => 'foto.jpg', 'mimeType' => 'image/jpeg', 'size' => '1000'], $metaStatus),
    ]);
}

function postDrive(array $body = [])
{
    return test()->actingAs(test()->user)->postJson(route('media.import-drive'), $body + ['file_id' => '1AbC_def-GHI', 'access_token' => 'ya29.secret-token']);
}

test('un token valido crea un asset pendiente, encola el job con el token y no lo guarda en el asset (CA-18)', function () {
    fakeDrive();

    $res = postDrive()->assertStatus(202)->assertJsonPath('asset.status', 'pendiente');

    $asset = MediaAsset::withoutUserScope()->findOrFail($res->json('asset.id'));
    expect($asset->source)->toBe('drive')->and($asset->source_ref)->toBe('1AbC_def-GHI')->and($asset->original_name)->toBe('foto.jpg');
    expect(json_encode($asset->getAttributes()))->not->toContain('ya29')
        ->and(json_encode($res->json()))->not->toContain('ya29');
    Queue::assertPushed(ImportRemoteMedia::class, fn ($j) => $j->assetId === $asset->id && $j->reference === '1AbC_def-GHI' && $j->token === 'ya29.secret-token' && $j->queue === 'media');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'files/1AbC_def-GHI') && $r->hasHeader('Authorization', 'Bearer ya29.secret-token'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'tokeninfo') && ! str_contains($r->url(), 'ya29')); // el token va en el cuerpo, no en la URL
});

test('un fileId invalido se rechaza sin llamar a Google', function (string $id) {
    Http::fake();

    postDrive(['file_id' => $id])->assertStatus(422)->assertJsonValidationErrors('file_id');
    Http::assertNothingSent();
})->with(['../etc/passwd', 'a b', 'id?alt=media&x=1', 'a/b', str_repeat('a', 129)]);

test('un token de otra aplicacion (aud distinto) se rechaza', function () {
    fakeDrive(['aud' => 'otra-app.apps.googleusercontent.com']);

    postDrive()->assertStatus(422);
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('un token sin el scope drive.file se rechaza', function () {
    fakeDrive(['scope' => 'https://www.googleapis.com/auth/drive.readonly']);

    postDrive()->assertStatus(422);
    Queue::assertNothingPushed();
});

test('un token caducado (tokeninfo 400) o un 401 de los metadatos piden volver a elegir el fichero', function () {
    fakeDrive(tokenStatus: 400);
    postDrive()->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Vuelve a elegir el fichero en Drive'));

    fakeDrive(metaStatus: 401);
    postDrive()->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Vuelve a elegir el fichero en Drive'));
    Queue::assertNothingPushed();
});

test('el tipo y el tamano se validan antes de crear el asset', function () {
    fakeDrive(meta: ['mimeType' => 'application/pdf', 'name' => 'a.pdf']);
    postDrive()->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Tipo de fichero no admitido'));

    fakeDrive(meta: ['size' => (string) (31 * 1024 * 1024)]);
    postDrive()->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'tamaño máximo'));

    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('sin GOOGLE_PICKER_API_KEY el endpoint no existe (404)', function () {
    config(['services.google.picker_api_key' => null]);
    Http::fake();

    postDrive()->assertNotFound();
    Http::assertNothingSent();
});

test('B-1: sin GOOGLE_PICKER_APP_ID tampoco hay endpoint (con drive.file el Picker necesita setAppId: sin el, files.get da 404)', function () {
    config(['services.google.picker_app_id' => null]);
    Http::fake();

    postDrive()->assertNotFound();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('exige sesion y respeta el limite de 3 importaciones vivas', function () {
    $this->postJson(route('media.import-drive'), ['file_id' => 'abc', 'access_token' => 't'])->assertUnauthorized();

    fakeDrive();
    postDrive()->assertStatus(202);
    postDrive()->assertStatus(202);
    postDrive()->assertStatus(202);
    postDrive()->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'importaciones en curso'));
});
