<?php

use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Exceptions\InvalidPostTransition;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\SocialAccountService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->image = MediaAsset::factory()->for($this->user)->create();
    $this->service = app(PostService::class);
});

/** Datos validos de una publicacion IG imagen programada. */
function postData(array $over = []): array
{
    return array_merge([
        'text' => 'Hola mundo',
        'scheduled_at' => '2026-11-02 10:00',
        'schedule' => true,
        'media' => [test()->image->id],
        'targets' => [['account_id' => test()->ig->id, 'content_type' => 'instagram_image']],
    ], $over);
}

test('Posts: crea una publicacion programada con su destino y media', function () {
    $post = $this->service->create($this->user, $this->brand, postData());

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->targets)->toHaveCount(1)
        ->and($post->targets[0]->status)->toBe(PostStatus::Scheduled)
        ->and($post->targets[0]->network)->toBe(Network::Instagram)
        ->and($post->targets[0]->content_type)->toBe(ContentType::InstagramImage)
        ->and($post->media->pluck('id')->all())->toBe([$this->image->id])
        ->and($post->user_id)->toBe($this->user->id);
});

test('Posts: la zona horaria de la marca se respeta (Europe/Madrid CET = UTC+1) y se guarda en UTC', function () {
    $post = $this->service->create($this->user, $this->brand, postData(['scheduled_at' => '2026-11-02 10:00']));

    expect($post->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-02 09:00')
        ->and($post->scheduledAtLocal()->format('Y-m-d H:i'))->toBe('2026-11-02 10:00');

    // En verano (CEST, UTC+2) la misma hora local son dos horas antes en UTC.
    $summer = $this->service->create($this->user, $this->brand, postData(['scheduled_at' => '2027-07-01 10:00', 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_photo']]]));
    expect($summer->scheduled_at->utc()->format('H:i'))->toBe('08:00');

    // Con zona explicita en el string manda la zona del string.
    $utc = $this->service->create($this->user, $this->brand, postData(['scheduled_at' => '2026-11-03T10:00:00Z']));
    expect($utc->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-03 10:00');

    $other = Brand::factory()->for($this->user)->create(['timezone' => 'America/New_York']);
    $acc = SocialAccount::factory()->for($this->user)->create(['brand_id' => $other->id, 'network' => Network::Instagram]);
    $ny = $this->service->create($this->user, $other, postData(['targets' => [['account_id' => $acc->id, 'content_type' => 'instagram_image']]]));
    expect($ny->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-02 15:00');
});

test('Posts: un borrador no exige fecha ni destinos; programar si exige fecha futura y destino', function () {
    $draft = $this->service->create($this->user, $this->brand, ['text' => 'idea', 'schedule' => false]);
    expect($draft->status)->toBe(PostStatus::Draft)->and($draft->targets)->toHaveCount(0);

    expect(fn () => $this->service->create($this->user, $this->brand, postData(['scheduled_at' => '2026-11-01 08:30', 'targets' => []])))
        ->toThrow(PostValidationException::class);

    try {
        $this->service->create($this->user, $this->brand, postData(['scheduled_at' => '2026-10-30 10:00']));
    } catch (PostValidationException $e) {
        expect($e->errors())->toHaveKey('scheduled_at');
    }
});

test('Posts: la validacion de media rechaza indicando el limite (CA-05)', function () {
    $long = MediaAsset::factory()->video(duration: 1200)->for($this->user)->create();

    try {
        $this->service->create($this->user, $this->brand, postData(['media' => [$long->id], 'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_reel']]]));
        $this->fail('debia rechazar');
    } catch (PostValidationException $e) {
        expect($e->errors()['targets.0'][0])->toContain('admite como maximo 15 min');
    }
    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('Posts: el tipo debe corresponder a la red de la cuenta y no se duplican destinos', function () {
    expect(fn () => $this->service->create($this->user, $this->brand, postData(['targets' => [['account_id' => $this->ig->id, 'content_type' => 'facebook_photo']]])))
        ->toThrow(PostValidationException::class, 'no corresponde a Instagram');

    expect(fn () => $this->service->create($this->user, $this->brand, postData(['targets' => [
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image'],
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image'],
    ]])))->toThrow(PostValidationException::class, 'ya estan');
});

test('Posts: transiciones validas se persisten y las invalidas lanzan', function () {
    $post = $this->service->create($this->user, $this->brand, postData());
    $target = $post->targets[0];

    $t = $this->service->transition($target, PostStatus::Publishing);
    expect($t->status)->toBe(PostStatus::Publishing)->and($t->started_at)->not->toBeNull();
    expect($post->fresh()->status)->toBe(PostStatus::Publishing);

    $this->service->transition($target, PostStatus::Processing, ['external_reference' => 'c-1']);
    $done = $this->service->transition($target, PostStatus::Published, ['external_id' => '123', 'external_url' => 'https://x.test/p/123']);

    expect($done->fresh()->status)->toBe(PostStatus::Published)
        ->and($done->fresh()->external_id)->toBe('123')
        ->and($done->fresh()->published_at)->not->toBeNull()
        ->and($post->fresh()->status)->toBe(PostStatus::Published);

    expect(fn () => $this->service->transition($target, PostStatus::Scheduled))->toThrow(InvalidPostTransition::class);
    expect($target->fresh()->status)->toBe(PostStatus::Published);
});

test('Posts: matriz de transiciones del enum', function (PostStatus $from, PostStatus $to, bool $ok) {
    expect($from->canTransitionTo($to))->toBe($ok);
})->with([
    [PostStatus::Draft, PostStatus::Scheduled, true],
    [PostStatus::Draft, PostStatus::Published, false],
    [PostStatus::Scheduled, PostStatus::Publishing, true],
    [PostStatus::Scheduled, PostStatus::Published, false],
    [PostStatus::Publishing, PostStatus::Processing, true],
    [PostStatus::Publishing, PostStatus::Published, true],
    [PostStatus::Publishing, PostStatus::Failed, true],
    [PostStatus::Processing, PostStatus::Published, true],
    [PostStatus::Processing, PostStatus::Scheduled, false],
    [PostStatus::Failed, PostStatus::Scheduled, true],
    [PostStatus::Published, PostStatus::Failed, false],
    [PostStatus::Published, PostStatus::Draft, false],
]);

test('Posts: el estado agregado refleja los destinos (uno publicado y otro con error = fallida)', function () {
    $post = $this->service->create($this->user, $this->brand, postData(['targets' => [
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image'],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo'],
    ]]));
    [$a, $b] = $post->targets;

    $this->service->transition($a, PostStatus::Publishing);
    $this->service->transition($a, PostStatus::Published);
    expect($post->fresh()->status)->toBe(PostStatus::Scheduled); // el otro sigue pendiente

    $this->service->transition($b, PostStatus::Publishing);
    $this->service->transition($b, PostStatus::Failed, ['error_message' => 'x']);
    expect($post->fresh()->status)->toBe(PostStatus::Failed);
});

test('Posts: reprogramar y desprogramar una pendiente; una publicada no se toca (CA-08)', function () {
    $post = $this->service->create($this->user, $this->brand, postData());

    $moved = $this->service->reschedule($this->user, $post, '2026-11-05 18:30');
    expect($moved->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-05 17:30');

    expect(fn () => $this->service->reschedule($this->user, $post, '2026-10-01 10:00'))->toThrow(PostValidationException::class, 'futura');

    $back = $this->service->unschedule($this->user, $post);
    expect($back->status)->toBe(PostStatus::Draft)->and($post->targets()->first()->status)->toBe(PostStatus::Draft);

    $again = $this->service->schedule($this->user, $post->fresh(), '2026-11-06 09:00');
    expect($again->status)->toBe(PostStatus::Scheduled);

    $t = $again->targets[0];
    $this->service->transition($t, PostStatus::Publishing);
    $this->service->transition($t, PostStatus::Published);
    expect(fn () => $this->service->reschedule($this->user, $post->fresh(), '2026-11-09 09:00'))->toThrow(PostValidationException::class, 'ya se ha publicado');
    expect(fn () => $this->service->delete($this->user, $post->fresh()))->toThrow(PostValidationException::class);
});

test('Posts: editar reemplaza contenido y destinos; borrar una pendiente la envia a la papelera', function () {
    $post = $this->service->create($this->user, $this->brand, postData());

    $edited = $this->service->update($this->user, $post, postData(['text' => 'Nuevo', 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_photo']]]));
    expect($edited->text)->toBe('Nuevo')->and($edited->targets)->toHaveCount(1)->and($edited->targets[0]->network)->toBe(Network::Facebook);

    $this->service->delete($this->user, $edited);
    // Soft delete (papelera): el post sale de las consultas normales pero conserva sus destinos para poder restaurarlo.
    expect(Post::withoutUserScope()->count())->toBe(0)->and(Post::withoutUserScope()->onlyTrashed()->count())->toBe(1)->and(PostTarget::withoutUserScope()->count())->toBe(1);
    // La media no se borra con la publicacion.
    expect(MediaAsset::withoutUserScope()->count())->toBe(1);
});

test('Posts: aislamiento, otro usuario no ve ni toca publicaciones, cuentas ni media ajenas', function () {
    $post = $this->service->create($this->user, $this->brand, postData());
    $other = User::factory()->create();

    expect(Post::withoutUserScope()->count())->toBe(1);
    $this->actingAs($other);
    expect(Post::count())->toBe(0)->and(PostTarget::count())->toBe(0);
    $this->actingAs($this->user);
    expect(Post::count())->toBe(1);

    expect(fn () => $this->service->update($other, $post, postData()))->toThrow(PostValidationException::class);
    expect(fn () => $this->service->delete($other, $post))->toThrow(PostValidationException::class);
    expect(fn () => $this->service->create($other, $this->brand, postData()))->toThrow(PostValidationException::class, 'marca');

    $otherBrand = Brand::factory()->for($other)->create();
    expect(fn () => $this->service->create($other, $otherBrand, postData()))->toThrow(PostValidationException::class); // cuenta y media ajenas
});

// Criterio heredado de T-16: borrar / desconectar / requerir reconexion NO borra publicaciones pendientes.
test('Posts: desconectar la cuenta no borra las publicaciones pendientes', function () {
    $post = $this->service->create($this->user, $this->brand, postData());

    app(SocialAccountService::class)->disconnect($this->ig);

    $target = $post->targets()->first();
    expect(Post::withoutUserScope()->count())->toBe(1)
        ->and($target)->not->toBeNull()
        ->and($target->status)->toBe(PostStatus::Scheduled)
        ->and($target->social_account_id)->toBe($this->ig->id);
});

test('Posts: pasar la cuenta a requiere_reconexion no borra las publicaciones pendientes', function () {
    $post = $this->service->create($this->user, $this->brand, postData());

    app(SocialAccountService::class)->markRequiresReconnection($this->ig, 'token revocado');

    expect($post->targets()->first()->status)->toBe(PostStatus::Scheduled)
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('Posts: borrar la cuenta (fila) conserva la publicacion pendiente sin cuenta (sin cascade destructivo)', function () {
    $post = $this->service->create($this->user, $this->brand, postData());

    SocialAccount::withoutUserScope()->whereKey($this->ig->id)->delete();

    $target = PostTarget::withoutUserScope()->where('post_id', $post->id)->first();
    expect(Post::withoutUserScope()->count())->toBe(1)
        ->and($target)->not->toBeNull()
        ->and($target->social_account_id)->toBeNull()
        ->and($target->status)->toBe(PostStatus::Scheduled)
        ->and($target->network)->toBe(Network::Instagram);
});

test('Posts: la media usada por una publicacion no se puede borrar (restrict) ni via la API', function () {
    $this->service->create($this->user, $this->brand, postData());

    expect(fn () => MediaAsset::withoutUserScope()->whereKey($this->image->id)->delete())->toThrow(QueryException::class);

    $this->actingAs($this->user)->deleteJson(route('media.destroy', $this->image))->assertStatus(409);
    expect(MediaAsset::withoutUserScope()->count())->toBe(1);
});

test('Posts: no se programa sobre una cuenta que requiere reconexion, pero si como borrador', function () {
    $this->ig->update(['status' => SocialAccount::STATUS_RECONNECT]);

    expect(fn () => $this->service->create($this->user, $this->brand, postData()))->toThrow(PostValidationException::class, 'reconectarse');
    expect($this->service->create($this->user, $this->brand, postData(['schedule' => false]))->status)->toBe(PostStatus::Draft);
});

test('Posts: una cuenta desconectada no se puede usar de destino', function () {
    app(SocialAccountService::class)->disconnect($this->ig);

    expect(fn () => $this->service->create($this->user, $this->brand, postData(['schedule' => false])))
        ->toThrow(PostValidationException::class, 'desconectada');
});
