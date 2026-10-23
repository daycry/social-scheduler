<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\ComposerPreset;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpClient;

uses(McpClient::class);

// C-04 (T-31): paridad del MCP con la web para `options` por destino.

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->yt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::YouTube, 'name' => 'YT']);
    $this->tt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok, 'name' => 'TT']);
    $this->video = MediaAsset::factory()->video(duration: 45)->for($this->user)->create();
    $this->key = $this->mcpKey($this->user);
});

function moArgs(array $targets): array
{
    return ['brand_id' => test()->brand->id, 'text' => 'Hola', 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true, 'media_ids' => [test()->video->id], 'targets' => $targets];
}

function moYt(array $options): array
{
    return ['account_id' => test()->yt->id, 'content_type' => 'youtube_video', 'options' => $options];
}

function moTt(array $options): array
{
    return ['account_id' => test()->tt->id, 'content_type' => 'tiktok_video', 'options' => $options];
}

test('McpOptions: create_post acepta las opciones de YouTube y get_post las devuelve', function () {
    $options = ['category_id' => '27', 'tags' => ['uno', 'dos'], 'made_for_kids' => false, 'notify_subscribers' => false, 'contains_synthetic_media' => true];

    [$err, $created] = $this->callTool($this->key, 'create_post', moArgs([moYt($options)]));
    [$err2, $post] = $this->callTool($this->key, 'get_post', ['post_id' => $created['id']]);

    expect($err)->toBeFalse()->and($err2)->toBeFalse()
        ->and($created['targets'][0]['options'])->toBe($options)
        ->and($post['targets'][0]['options'])->toBe($options);
});

test('McpOptions: una opcion con forma invalida da error de validacion, igual que en la web', function () {
    [$err, $msg] = $this->callTool($this->key, 'create_post', moArgs([moYt(['category_id' => 'abc'])]));
    expect($err)->toBeTrue()->and(json_encode($msg))->toContain('category_id');

    [$err, $msg] = $this->callTool($this->key, 'create_post', moArgs([moYt(['notify_subscribers' => 'quizas'])]));
    expect($err)->toBeTrue();
});

test('McpOptions: una opcion que la cuenta no admite da error del destino (TikTok en modo borrador, YouTube en TikTok)', function () {
    [$err, $msg] = $this->callTool($this->key, 'create_post', moArgs([moTt(['disable_duet' => true])]));
    expect($err)->toBeTrue()->and(json_encode($msg, JSON_UNESCAPED_UNICODE))->toContain('publicacion directa');

    [$err, $msg] = $this->callTool($this->key, 'create_post', moArgs([moTt(['notify_subscribers' => true])]));
    expect($err)->toBeTrue()->and(json_encode($msg))->toContain('solo existe en YouTube');
});

test('McpOptions: con la cuenta de TikTok en publicacion directa se aceptan sus opciones y el contenido de marca no puede ser privado', function () {
    $this->tt->forceFill(['meta' => ['direct_post' => true]])->save();

    [$err, $post] = $this->callTool($this->key, 'create_post', moArgs([moTt(['privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_duet' => true, 'brand_organic_toggle' => true])]));
    expect($err)->toBeFalse()->and($post['targets'][0]['options'])->toBe(['privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_duet' => true, 'brand_organic_toggle' => true]);

    [$err, $msg] = $this->callTool($this->key, 'create_post', moArgs([moTt(['brand_content_toggle' => true])]));
    expect($err)->toBeTrue()->and(json_encode($msg))->toContain('no puede ser privado');
});

test('McpOptions: update_post sustituye las opciones y el MCP no toca los presets', function () {
    [, $created] = $this->callTool($this->key, 'create_post', moArgs([moYt(['notify_subscribers' => false])]));

    [$err, $updated] = $this->callTool($this->key, 'update_post', moArgs([moYt(['made_for_kids' => true])]) + ['post_id' => $created['id']]);

    expect($err)->toBeFalse()->and($updated['targets'][0]['options'])->toBe(['made_for_kids' => true])
        ->and(ComposerPreset::withoutUserScope()->count())->toBe(0);
});
