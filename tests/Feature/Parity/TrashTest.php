<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PollPublishStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\PublishRecovery;
use App\Services\TrashService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->image = MediaAsset::factory()->for($this->user)->create();
    $this->posts = app(PostService::class);
    $this->trash = app(TrashService::class);
});

function trashPost(string $when = '2026-11-10 10:00'): Post
{
    return app(PostService::class)->create(test()->user, test()->brand, [
        'text' => 'Para borrar', 'schedule' => true, 'scheduled_at' => $when, 'media' => [test()->image->id],
        'targets' => [['account_id' => test()->ig->id, 'content_type' => 'instagram_image']],
    ]);
}

test('Trash: borrar envia a la papelera (soft delete) y deja de verse en el resto de consultas', function () {
    $post = trashPost();

    $this->actingAs($this->user)->delete(route('posts.destroy', $post))->assertRedirect();

    expect(Post::query()->count())->toBe(0)->and(Post::withoutUserScope()->onlyTrashed()->count())->toBe(1);
    $this->actingAs($this->user)->get(route('trash.index'))->assertOk()->assertSee('Para borrar');
});

test('Trash: lo publicado o en vuelo NO se envia a la papelera (lockEditableTargets)', function () {
    foreach ([PostStatus::Publishing, PostStatus::Processing, PostStatus::Published] as $status) {
        $post = trashPost();
        PostTarget::withoutUserScope()->where('post_id', $post->id)->update(['status' => $status->value]);

        expect(fn () => $this->trash->trash($this->user, $post->fresh()))->toThrow(PostValidationException::class);
        expect(Post::withoutUserScope()->whereKey($post->id)->whereNull('deleted_at')->exists())->toBeTrue();
    }
});

test('Trash: una publicacion en la papelera no se despacha ni se publica', function () {
    Queue::fake();
    $post = trashPost('2026-11-01 12:30');
    $this->trash->trash($this->user, $post);

    Carbon::setTestNow('2026-11-01 13:00:00');
    expect(app(DispatchDuePosts::class)())->toBe(0);
    Queue::assertNotPushed(PublishPostTarget::class);
});

test('Trash: restaurar devuelve el post como BORRADOR (los destinos se cancelan al borrar) y no se publica de golpe', function () {
    Queue::fake();
    $future = trashPost('2026-11-10 10:00');
    $past = trashPost('2026-11-01 12:30');
    $this->trash->trash($this->user, $future);
    $this->trash->trash($this->user, $past);
    Carbon::setTestNow('2026-11-02 12:00:00');

    $this->actingAs($this->user)->post(route('trash.restore', $future))->assertRedirect(route('trash.index'));
    $this->actingAs($this->user)->post(route('trash.restore', $past))->assertRedirect(route('trash.index'));

    expect(Post::query()->count())->toBe(2)
        ->and($future->fresh()->status)->toBe(PostStatus::Draft)
        ->and($past->fresh()->status)->toBe(PostStatus::Draft)
        ->and($past->fresh()->targets->first()->status)->toBe(PostStatus::Draft);
    expect(app(DispatchDuePosts::class)())->toBe(0);
});

test('Trash: restaurar algo que no esta en la papelera da error, y eliminar para siempre borra', function () {
    $live = trashPost();
    $this->actingAs($this->user)->post(route('trash.restore', $live))->assertRedirect()->assertSessionHas('error');

    $this->trash->trash($this->user, $live);
    $this->actingAs($this->user)->delete(route('trash.destroy', $live))->assertRedirect(route('trash.index'));

    expect(Post::withoutUserScope()->withTrashed()->count())->toBe(0)->and(PostTarget::withoutUserScope()->count())->toBe(0);
});

test('Trash: posts:purge-trash elimina lo de mas de 30 dias y deja el resto', function () {
    $old = trashPost();
    $recent = trashPost();
    $live = trashPost();
    $this->trash->trash($this->user, $old);
    Carbon::setTestNow('2026-11-20 12:00:00');
    $this->trash->trash($this->user, $recent);
    Carbon::setTestNow('2026-12-05 12:00:00'); // old: 34 dias en la papelera; recent: 15

    Artisan::call('posts:purge-trash');

    expect(Post::withoutUserScope()->withTrashed()->pluck('id')->sort()->values()->all())->toBe(collect([$recent->id, $live->id])->sort()->values()->all())
        ->and(Post::withoutUserScope()->onlyTrashed()->pluck('id')->all())->toBe([$recent->id]);
});

test('Trash: la purga se salta destinos en vuelo', function () {
    $post = trashPost();
    $this->trash->trash($this->user, $post);
    PostTarget::withoutUserScope()->where('post_id', $post->id)->update(['status' => PostStatus::Publishing->value]);
    Carbon::setTestNow('2026-12-15 12:00:00');

    expect($this->trash->purgeOlderThan(30))->toBe(0)->and(Post::withoutUserScope()->onlyTrashed()->count())->toBe(1);
});

test('Trash: aislamiento — la papelera, restaurar y eliminar de otro usuario responden 404', function () {
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $otherPost = Post::factory()->create(['brand_id' => $otherBrand->id, 'text' => 'Texto privado ajeno']);
    $otherPost->delete();

    $this->actingAs($this->user)->get(route('trash.index'))->assertOk()->assertDontSee('Texto privado ajeno');
    $this->actingAs($this->user)->post(route('trash.restore', $otherPost))->assertNotFound();
    $this->actingAs($this->user)->delete(route('trash.destroy', $otherPost))->assertNotFound();
    expect(Post::withoutUserScope()->onlyTrashed()->whereKey($otherPost->id)->exists())->toBeTrue();

    expect(fn () => $this->trash->restore($this->user, $otherPost))->toThrow(PostValidationException::class);
});

test('Trash: B1 un post en la papelera con la cuenta borrada NO tumba el despachador (los demas se despachan)', function () {
    Queue::fake();
    $trashed = trashPost('2026-11-10 10:00');
    $this->trash->trash($this->user, $trashed);
    // Estado heredado: destino todavia programado dentro de un post borrado, y su cuenta eliminada (FK nullOnDelete).
    PostTarget::withoutUserScope()->where('post_id', $trashed->id)->update(['status' => PostStatus::Scheduled->value, 'social_account_id' => null]);

    $live = trashPost('2026-11-01 12:30');
    Carbon::setTestNow('2026-11-01 13:00:00');

    expect(app(DispatchDuePosts::class)())->toBe(1);
    Queue::assertPushed(PublishPostTarget::class, 1);
});

test('Trash: B1 un destino procesando de un post en la papelera, con la cuenta desconectada, no aborta la recuperacion', function () {
    Queue::fake();
    $trashed = trashPost('2026-11-10 10:00');
    $this->trash->trash($this->user, $trashed);
    PostTarget::withoutUserScope()->where('post_id', $trashed->id)->update(['status' => PostStatus::Processing->value]);
    $this->ig->update(['status' => SocialAccount::STATUS_DISCONNECTED]);

    $other = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $live = app(PostService::class)->create($this->user, $this->brand, [
        'text' => 'Viva', 'schedule' => true, 'scheduled_at' => '2026-11-01 12:30', 'media' => [$this->image->id],
        'targets' => [['account_id' => $other->id, 'content_type' => 'instagram_image']],
    ]);
    Carbon::setTestNow('2026-11-01 13:00:00');

    expect(app(DispatchDuePosts::class)())->toBe(1)
        ->and(PostTarget::withoutUserScope()->where('post_id', $trashed->id)->first()->status)->toBe(PostStatus::Processing); // la papelera no se toca
});

test('Trash: B1 enviar a la papelera cancela los destinos pendientes (borrador) y cierra un procesando huerfano', function () {
    $post = trashPost('2026-11-10 10:00');
    $this->trash->trash($this->user, $post);

    expect(PostTarget::withoutUserScope()->where('post_id', $post->id)->first()->status)->toBe(PostStatus::Draft);
});

test('Trash: B1 el repoll ignora la papelera y la transicion de un destino ya existente funciona con el post borrado', function () {
    Queue::fake();
    $post = trashPost('2026-11-10 10:00');
    $this->trash->trash($this->user, $post);
    $target = PostTarget::withoutUserScope()->where('post_id', $post->id)->first();
    $target->forceFill(['status' => PostStatus::Processing->value, 'next_attempt_at' => null])->save();

    expect(app(PublishRecovery::class)->run()['polled'])->toBe(0);
    Queue::assertNotPushed(PollPublishStatus::class);

    // La transicion explicita no revienta (withTrashed): p. ej. un sondeo que ya estaba en cola cuando se envio a la papelera.
    expect(app(PostService::class)->transition($target->fresh(), PostStatus::Failed, ['error_code' => 'x'])->status)->toBe(PostStatus::Failed);
});
