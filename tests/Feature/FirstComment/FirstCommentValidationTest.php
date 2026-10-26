<?php

use App\Connectors\Network;
use App\Http\Requests\PostRequest;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

// C-01b (CA-06): maximo del primer comentario por red; no se ofrece en TikTok ni en Stories; editarlo no reinicia el intento.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Queue::fake();
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $acc = fn (Network $n) => SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => $n]);
    $this->ig = $acc(Network::Instagram);
    $this->fb = $acc(Network::Facebook);
    $this->yt = $acc(Network::YouTube);
    $this->tt = $acc(Network::TikTok);
    $this->asset = MediaAsset::factory()->for($this->user)->create();
    Storage::disk('local')->put($this->asset->path, 'x');
    $this->service = app(PostService::class);
});

function fcData(array $targets): array
{
    return ['text' => 'Plantilla', 'scheduled_at' => now()->addDay(), 'schedule' => true, 'media' => [test()->asset->id], 'targets' => $targets];
}

test('el maximo del primer comentario lo marca la red: IG 2.200, FB 8.000', function (string $net, string $type, int $max) {
    $account = $this->{$net};
    $ok = $this->service->validate($this->user, $this->brand, fcData([['account_id' => $account->id, 'content_type' => $type, 'first_comment' => str_repeat('a', $max)]]));
    $ko = $this->service->validate($this->user, $this->brand, fcData([['account_id' => $account->id, 'content_type' => $type, 'first_comment' => str_repeat('a', $max + 1)]]));

    expect($ok)->toBe([])->and($ko)->toHaveKey('targets.0')->and($ko['targets.0'][0])->toContain((string) $max);
})->with([
    'instagram' => ['ig', 'instagram_image', 2200],
    'facebook' => ['fb', 'facebook_photo', 8000],
]);

test('YouTube admite hasta 10.000 caracteres de primer comentario', function () {
    $video = MediaAsset::factory()->video(duration: 30)->for($this->user)->create();
    Storage::disk('local')->put($video->path, 'x');
    $data = fn (int $n) => ['text' => 'Titulo', 'scheduled_at' => now()->addDay(), 'schedule' => true, 'media' => [$video->id],
        'targets' => [['account_id' => $this->yt->id, 'content_type' => 'youtube_video', 'first_comment' => str_repeat('a', $n)]]];

    expect(collect($this->service->validate($this->user, $this->brand, $data(10000)))->flatten()->filter(fn ($m) => str_contains($m, 'comentario'))->all())->toBe([])
        ->and($this->service->validate($this->user, $this->brand, $data(10001)))->toHaveKey('targets.0');
});

test('TikTok y las Stories de Instagram no admiten primer comentario', function (string $net, string $type) {
    $errors = $this->service->validate($this->user, $this->brand, fcData([['account_id' => $this->{$net}->id, 'content_type' => $type, 'first_comment' => 'Hola']]));

    expect($errors)->toHaveKey('targets.0')->and(implode(' ', $errors['targets.0']))->toContain('primer comentario');
})->with([
    'tiktok' => ['tt', 'tiktok_video'],
    'story' => ['ig', 'instagram_story'],
]);

test('un primer comentario vacio o solo espacios es nulo', function () {
    $post = $this->service->create($this->user, $this->brand, fcData([['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'first_comment' => "  \n "]]));

    expect($post->targets->first()->fresh()->first_comment)->toBeNull();
});

test('se guarda el primer comentario por destino', function () {
    $post = $this->service->create($this->user, $this->brand, fcData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'first_comment' => 'Link en bio'],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo'],
    ]));

    expect($post->targets->firstWhere('social_account_id', $this->ig->id)->fresh()->first_comment)->toBe('Link en bio')
        ->and($post->targets->firstWhere('social_account_id', $this->fb->id)->fresh()->first_comment)->toBeNull();
});

test('cambiar solo el primer comentario no reinicia el intento de publicacion', function () {
    $post = $this->service->create($this->user, $this->brand, fcData([['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'first_comment' => 'Uno']]));
    $post->targets()->get()->each(fn ($t) => $t->forceFill(['attempts' => 2, 'error_code' => 'transitorio'])->save());

    $this->service->update($this->user, $post, fcData([['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'first_comment' => 'Dos']]));

    $t = $post->targets()->first();
    expect($t->first_comment)->toBe('Dos')->and($t->attempts)->toBe(2)->and($t->error_code)->toBe('transitorio');
});

test('una edicion de otro campo conserva el primer comentario del destino', function () {
    $post = $this->service->create($this->user, $this->brand, fcData([['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'first_comment' => 'Persiste']]));

    $this->service->updateNotes($this->user, $post, 'nota');

    expect($post->targets()->first()->first_comment)->toBe('Persiste');
});

test('PostRequest declara targets.*.first_comment como texto opcional', function () {
    $rules = (new PostRequest)->rules();

    expect($rules)->toHaveKey('targets.*.first_comment');
    $v = Validator::make(['schedule' => false, 'targets' => [['account_id' => 1, 'content_type' => 'instagram_image', 'first_comment' => ['no']]]], $rules);
    expect($v->fails())->toBeTrue();
});
