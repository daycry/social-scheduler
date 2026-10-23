<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// C-03 (T-19): el texto efectivo de cada destino se valida en plan() y decide, por destino, si fill() reinicia el intento.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Queue::fake();
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->fbA = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->fbB = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->service = app(PostService::class);
});

function ttTargets(?string $textA = null, ?string $textB = null): array
{
    return [
        ['account_id' => test()->fbA->id, 'content_type' => 'facebook_text', 'text' => $textA],
        ['account_id' => test()->fbB->id, 'content_type' => 'facebook_text', 'text' => $textB],
    ];
}

function ttData(string $text, array $targets, array $extra = []): array
{
    return $extra + ['text' => $text, 'scheduled_at' => now()->addDay(), 'schedule' => true, 'targets' => $targets];
}

/** Marca los dos destinos como con un intento ambiguo previo (para que un reinicio sea visible: attempts a 0). */
function ttMarkAttempted(Post $post): void
{
    $post->targets()->get()->each(fn ($t) => $t->forceFill(['attempts' => 2, 'error_code' => 'transitorio'])->save());
}

function ttAttempts(Post $post, $account): int
{
    return (int) $post->targets()->where('social_account_id', $account->id)->first()->attempts;
}

test('TargetTextPlanFill: plan() valida el texto EFECTIVO de cada destino (maximo por red y hashtags)', function () {
    $asset = MediaAsset::factory()->for($this->user)->create();
    Storage::disk('local')->put($asset->path, 'x');
    $long = str_repeat('a', 2201);
    $tags = implode(' ', array_map(fn ($i) => "#t$i", range(1, 31)));

    // Plantilla larga: el destino vinculado de Instagram falla; el de Facebook (63206) no.
    $errors = $this->service->validate($this->user, $this->brand, ttData($long, [
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image'],
        ['account_id' => $this->fbA->id, 'content_type' => 'facebook_photo'],
    ], ['media' => [$asset->id]]));
    expect($errors)->toHaveKey('targets.0')->and($errors)->not->toHaveKey('targets.1');

    // Instagram desvinculado con texto corto: la plantilla larga ya no le afecta.
    expect($this->service->validate($this->user, $this->brand, ttData($long, [
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => 'corto'],
    ], ['media' => [$asset->id]])))->toBe([]);

    // Texto propio largo o con demasiados hashtags: falla solo ese destino.
    $errors = $this->service->validate($this->user, $this->brand, ttData('ok', [
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => $long],
        ['account_id' => $this->fbA->id, 'content_type' => 'facebook_photo', 'text' => $long],
    ], ['media' => [$asset->id]]));
    expect($errors)->toHaveKey('targets.0')->and($errors['targets.0'][0])->toContain('2201')->and($errors)->not->toHaveKey('targets.1');

    $errors = $this->service->validate($this->user, $this->brand, ttData('ok', [
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => $tags],
    ], ['media' => [$asset->id]]));
    expect($errors['targets.0'][0])->toContain('31 hashtags');
});

test('TargetTextPlanFill: CA-10 editar el texto propio de un destino reinicia solo ese', function () {
    $post = $this->service->create($this->user, $this->brand, ttData('Plantilla', ttTargets('Propio A', null)));
    ttMarkAttempted($post);

    $this->service->update($this->user, $post, ttData('Plantilla', ttTargets('Propio A editado', null)));

    expect(ttAttempts($post, $this->fbA))->toBe(0)->and(ttAttempts($post, $this->fbB))->toBe(2);
});

test('TargetTextPlanFill: plantilla editada reinicia solo los destinos vinculados', function () {
    $post = $this->service->create($this->user, $this->brand, ttData('Plantilla', ttTargets('Propio A', null)));
    ttMarkAttempted($post);

    $this->service->update($this->user, $post, ttData('Plantilla NUEVA', ttTargets('Propio A', null)));

    expect(ttAttempts($post, $this->fbA))->toBe(2)->and(ttAttempts($post, $this->fbB))->toBe(0);
});

test('TargetTextPlanFill: vincular o desvincular un destino cambia su texto efectivo y lo reinicia; sin cambios, nada', function () {
    $post = $this->service->create($this->user, $this->brand, ttData('Plantilla', ttTargets('Propio A', null)));
    ttMarkAttempted($post);

    // Mismos datos: ningun reinicio.
    $this->service->update($this->user, $post, ttData('Plantilla', ttTargets('Propio A', null)));
    expect(ttAttempts($post, $this->fbA))->toBe(2)->and(ttAttempts($post, $this->fbB))->toBe(2);

    // A pasa a vinculado (null) y B se desvincula con OTRO texto: ambos cambian de texto efectivo.
    $this->service->update($this->user, $post, ttData('Plantilla', ttTargets(null, 'Propio B')));
    expect(ttAttempts($post, $this->fbA))->toBe(0)->and(ttAttempts($post, $this->fbB))->toBe(0);

    // Desvincular con un texto IGUAL al de la plantilla no cambia lo que se publica: no reinicia.
    ttMarkAttempted($post);
    $this->service->update($this->user, $post, ttData('Plantilla', ttTargets('Plantilla', 'Propio B')));
    expect(ttAttempts($post, $this->fbA))->toBe(2)->and(ttAttempts($post, $this->fbB))->toBe(2);
});

test('TargetTextPlanFill: programar un borrador y publicar ahora conservan el texto propio de cada destino', function () {
    $draft = $this->service->create($this->user, $this->brand, ttData('Plantilla', ttTargets('Propio A', null), ['schedule' => false, 'scheduled_at' => null]));
    expect($draft->targets->pluck('text', 'social_account_id')->all())->toBe([$this->fbA->id => 'Propio A', $this->fbB->id => null]);

    $scheduled = $this->service->schedule($this->user, $draft, now()->addDay());
    expect($scheduled->targets->pluck('text', 'social_account_id')->all())->toBe([$this->fbA->id => 'Propio A', $this->fbB->id => null])
        ->and($scheduled->status)->toBe(PostStatus::Scheduled);

    $now = $this->service->publishNow($this->user, $scheduled);
    expect($now->targets->pluck('text', 'social_account_id')->all())->toBe([$this->fbA->id => 'Propio A', $this->fbB->id => null]);
});

test('TargetTextPlanFill: un texto propio vacio se conserva como propio (no vuelve a vincularse)', function () {
    $asset = MediaAsset::factory()->for($this->user)->create();
    Storage::disk('local')->put($asset->path, 'x');
    $post = $this->service->create($this->user, $this->brand, ttData('Plantilla', [
        ['account_id' => $this->fbA->id, 'content_type' => 'facebook_photo', 'text' => ''],
    ], ['media' => [$asset->id]]));

    expect($post->targets->first()->text)->toBe('')->and($post->targets->first()->effectiveText())->toBe('');
});

test('TargetTextPlanFill: un texto propio vacio incumple el requisito de texto de una red que lo exige', function () {
    expect(fn () => $this->service->create($this->user, $this->brand, ttData('Plantilla', [
        ['account_id' => $this->fbA->id, 'content_type' => 'facebook_text', 'text' => ''],
    ])))->toThrow(PostValidationException::class);
});
