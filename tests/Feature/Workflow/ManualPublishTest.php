<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ManualPublishService;
use App\Services\PostService;
use App\Services\PublishOutcomes;
use App\Services\PublishRequestFactory;
use App\Services\SocialAccountService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// T-19 publicacion manual: el destino no va a la red; a la hora se avisa una vez y el usuario lo marca como publicado.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-09 12:00:00');
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
});

function manualPost(array $over = []): Post
{
    return app(PostService::class)->create(test()->user, test()->brand, array_replace_recursive([
        'text' => 'Texto para copiar', 'schedule' => true, 'scheduled_at' => '2026-11-09T13:00', 'media' => [],
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text', 'manual' => true]],
    ], $over));
}

test('un destino manual nunca se encola ni se publica; el aviso sale una sola vez a la hora', function () {
    $post = manualPost();
    $target = $post->targets()->first();
    expect($target->manual)->toBeTrue();

    // Antes de la hora no hay nada.
    expect(app(ManualPublishService::class)->notifyDue())->toBe(0);

    Carbon::setTestNow('2026-11-09 13:01:00');
    expect(DispatchDuePosts::dueTargets()->all())->toBe([]);

    $dispatch = app(DispatchDuePosts::class);
    $dispatch();
    $dispatch(); // idempotente: segunda pasada, ningun aviso nuevo

    Queue::assertNothingPushed();
    expect($this->user->notifications()->where('data->type', 'manual_due')->count())->toBe(1)
        ->and($this->user->notifications()->first()->data['url'])->toBe(route('posts.manual.show', $post->id, false))
        ->and($target->fresh()->status)->toBe(PostStatus::Scheduled)->and($target->fresh()->manual_notified_at)->not->toBeNull();

    // El claim del job tampoco lo deja pasar aunque alguien lo encole a mano.
    (new PublishPostTarget($target->id))->handle(app(ConnectorRegistry::class), app(PublishOutcomes::class), app(PublishRequestFactory::class), app(SocialAccountService::class));
    expect($target->fresh()->status)->toBe(PostStatus::Scheduled)->and($target->fresh()->attempts)->toBe(0);
});

test('el aviso trae el contenido listo para copiar y la media descargable', function () {
    $media = MediaAsset::factory()->for($this->user)->create(['original_name' => 'foto.jpg', 'width' => 400, 'height' => 400]);
    $post = manualPost(['media' => [$media->id], 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_photo', 'manual' => true, 'text' => 'Texto propio']]]);

    $json = $this->actingAs($this->user)->getJson(route('workflow.manual.content', $post->id))->assertOk()->json();

    expect($json['targets'])->toHaveCount(1)
        ->and($json['targets'][0]['text'])->toBe('Texto propio')
        ->and($json['targets'][0]['network_label'])->toBe('Facebook')
        ->and($json['targets'][0]['media'][0]['download_url'])->toBe(route('media.file', $media->id, false))
        ->and($json['targets'][0]['media'][0]['name'])->toBe('foto.jpg');
    $this->actingAs($this->user)->get(route('posts.manual.show', $post->id))->assertOk();
    $this->actingAs($this->other)->getJson(route('workflow.manual.content', $post->id))->assertNotFound();
    $this->actingAs($this->other)->get(route('posts.manual.show', $post->id))->assertNotFound();
});

test('marcar como publicado: https opcional, idempotente, refleja el estado y la fecha', function () {
    $post = manualPost();
    $target = $post->targets()->first();
    $url = route('workflow.manual.published', [$post->id, $target->id]);

    foreach (['http://x.com/p', 'ftp://x.com', 'javascript:alert(1)', 'https://', 'https://x y.com', str_repeat('a', 2001)] as $bad) {
        $this->actingAs($this->user)->postJson($url, ['url' => $bad])->assertStatus(422);
    }
    expect($target->fresh()->status)->toBe(PostStatus::Scheduled);

    $this->actingAs($this->user)->postJson($url, ['url' => 'https://facebook.com/pagina/posts/1'])->assertOk()
        ->assertJsonPath('target.status', 'publicada')->assertJsonPath('target.external_url', 'https://facebook.com/pagina/posts/1')
        ->assertJsonPath('post.status', 'publicada');
    // Repetirlo no cambia nada ni falla.
    $this->actingAs($this->user)->postJson($url, ['url' => 'https://otra.com/x'])->assertOk()->assertJsonPath('target.external_url', 'https://facebook.com/pagina/posts/1');
    expect($target->fresh()->attempts)->toBe(1)->and($post->fresh()->status)->toBe(PostStatus::Published);
});

test('un destino normal no se puede marcar a mano y otro usuario recibe 404', function () {
    $post = manualPost(['targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text', 'manual' => false]]]);
    $target = $post->targets()->first();

    $this->actingAs($this->user)->postJson(route('workflow.manual.published', [$post->id, $target->id]))->assertStatus(422);
    $this->actingAs($this->other)->postJson(route('workflow.manual.published', [$post->id, $target->id]))->assertNotFound();
    expect($target->fresh()->status)->toBe(PostStatus::Scheduled);

    $manual = manualPost();
    $this->actingAs($this->user)->postJson(route('workflow.manual.published', [$manual->id, $target->id]))->assertNotFound(); // destino de otro post
    $this->actingAs($this->user)->postJson(route('workflow.manual.published', [$manual->id, $manual->targets()->first()->id]))->assertOk()->assertJsonPath('target.external_url', null);
});

test('un post manual pendiente de aprobacion no avisa ni se marca hasta estar aprobado', function () {
    $post = manualPost(['schedule' => false, 'requires_approval' => true]);
    Post::withoutUserScope()->whereKey($post->id)->update(['status' => 'programada', 'scheduled_at' => now()->subHour()]);
    PostTarget::withoutUserScope()->where('post_id', $post->id)->update(['status' => 'programada']);

    expect(app(ManualPublishService::class)->notifyDue())->toBe(0);
    $this->actingAs($this->user)->postJson(route('workflow.manual.published', [$post->id, $post->targets()->first()->id]))->assertStatus(422);

    Post::withoutUserScope()->whereKey($post->id)->update(['approval_status' => 'aprobado']);
    expect(app(ManualPublishService::class)->notifyDue())->toBe(1);
});

test('reprogramar o editar un manual rearma el aviso', function () {
    $post = manualPost();
    Carbon::setTestNow('2026-11-09 13:05:00');
    app(ManualPublishService::class)->notifyDue();
    expect($post->targets()->first()->manual_notified_at)->not->toBeNull();

    app(PostService::class)->reschedule($this->user, $post->fresh(), '2026-11-10T09:00');
    expect($post->targets()->first()->manual_notified_at)->toBeNull();

    Carbon::setTestNow('2026-11-10 09:30:00');
    expect(app(ManualPublishService::class)->notifyDue())->toBe(1);
});

test('las paginas de aprobaciones, series y aviso manual se renderizan con sus datos', function () {
    $collab = BrandCollaborator::query()->create(['brand_id' => $this->brand->id, 'owner_id' => $this->user->id, 'user_id' => $this->other->id, 'role' => 'aprobador']);
    $post = manualPost();

    $this->actingAs($this->user)->get(route('approvals.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Approvals/Index')->has('brands', 1)->where('brands.0.collaborators.0.id', $collab->id));
    $this->actingAs($this->user)->get(route('series.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Series/Index')->has('brands.0.accounts', 2));
    $this->actingAs($this->user)->get(route('posts.manual.show', $post->id))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Posts/Manual')->has('targets', 1));
    // Un aprobador invitado ve la bandeja sin gestionar colaboradores.
    $this->actingAs($this->other)->get(route('approvals.index'))->assertOk()->assertInertia(fn ($page) => $page->has('brands', 0));
});
