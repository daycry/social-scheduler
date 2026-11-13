<?php

use App\Connectors\Network;
use App\Models\AuditLog as AuditLogEntry;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\McpClient;

uses(McpClient::class);

// Revision T-28/T-29, intento 1: SmartLink sin `published` (3) y media por ApiController (4).

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->key = $this->mcpKey($this->user);
});

function rfApi(string $key, string $method, string $uri, array $body = [])
{
    app('auth')->forgetGuards();

    return test()->withToken($key)->json($method, '/api/v1/'.ltrim($uri, '/'), $body);
}

test('smartlinks: un PUT sin `published` nunca publica (CWE-1188): uno nuevo nace sin publicar y uno publicado conserva su estado', function () {
    $body = ['slug' => 'sin-publicar', 'title' => 'Marca', 'blocks' => []];

    expect(rfApi($this->key, 'PUT', 'brands/'.$this->brand->id.'/smart-link', $body)->assertCreated()->json('published'))->toBeFalse();

    rfApi($this->key, 'PUT', 'brands/'.$this->brand->id.'/smart-link', $body + ['published' => true])->assertOk()->assertJsonPath('published', true);
    // Reenviar sin `published` no cambia lo publicado ni lo despublica.
    rfApi($this->key, 'PUT', 'brands/'.$this->brand->id.'/smart-link', ['title' => 'Otro titulo'] + $body)->assertOk()->assertJsonPath('published', true)->assertJsonPath('title', 'Otro titulo');
    rfApi($this->key, 'PUT', 'brands/'.$this->brand->id.'/smart-link', $body + ['published' => false])->assertOk()->assertJsonPath('published', false);
});

test('media: errores, auditoria y respuestas como el resto de la API y sin URLs de sesion web', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    // Un error de entrada lleva `message` y `errors` (tambien el que sale del servicio, no solo el de validacion).
    rfApi($this->key, 'POST', 'media', ['name' => 'x.exe', 'mime' => 'application/x-msdownload', 'size' => 10])->assertUnprocessable()->assertJsonStructure(['message', 'errors']);
    rfApi($this->key, 'POST', 'media/import-url', ['url' => 'http://127.0.0.1/a.png'])->assertUnprocessable()->assertJsonStructure(['message', 'errors']);

    $created = rfApi($this->key, 'POST', 'media', ['name' => 'p.png', 'mime' => 'image/png', 'size' => strlen($png)])->assertCreated()->json();
    expect($created['asset'])->not->toHaveKeys(['preview_url', 'download_url'])->and($created['id'])->toBe($created['asset']['id']);
    $id = $created['id'];

    app('auth')->forgetGuards();
    $uploaded = $this->withServerVariables(['HTTP_AUTHORIZATION' => 'Bearer '.$this->key])->call('PUT', '/api/v1/media/'.$id.'/upload', [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_ACCEPT' => 'application/json'], $png)->assertOk()->json();
    expect($uploaded['asset'])->not->toHaveKeys(['preview_url', 'download_url']);
    rfApi($this->key, 'GET', 'media/'.$id)->assertOk()->assertJsonMissingPath('asset.preview_url')->assertJsonMissingPath('asset.download_url');

    // Borrar responde {id, deleted} como los posts, y la auditoria queda en el canal api.
    rfApi($this->key, 'DELETE', 'media/'.$id)->assertOk()->assertExactJson(['id' => $id, 'deleted' => true]);
    expect(AuditLogEntry::where('user_id', $this->user->id)->where('channel', 'api')->whereIn('action', ['initiate_media_upload', 'upload_media', 'get_media', 'delete_media'])->pluck('action')->unique()->sort()->values()->all())
        ->toBe(['delete_media', 'get_media', 'initiate_media_upload', 'upload_media']);

    // Una media en uso no se borra: conflicto de estado (409), no error de entrada.
    $used = MediaAsset::factory()->for($this->user)->create();
    $account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    rfApi($this->key, 'POST', 'brands/'.$this->brand->id.'/posts', ['text' => 'x', 'schedule' => false, 'media' => [$used->id], 'targets' => [['account_id' => $account->id, 'content_type' => 'facebook_photo']]])->assertCreated();
    rfApi($this->key, 'DELETE', 'media/'.$used->id)->assertStatus(409);
});
