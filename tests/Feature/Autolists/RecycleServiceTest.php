<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Exceptions\OwnershipException;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Autolists\RecycleService;
use App\Services\Posts\PostDuplicator;
use App\Services\TrashService;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// T-92 (F-20): reciclado inteligente. Se proponen los posts publicados con mejor engagement (interacciones / visualizaciones, el criterio del dashboard)
// y metricas suficientes, nunca antes del intervalo minimo desde la ultima publicacion o reciclado del MISMO contenido. Sin red.

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-27 12:00:00');
    config(['social.recycle.min_interval_days' => 30]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'IG']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => 'FB']);
    $this->svc = app(RecycleService::class);
});

/**
 * Post publicado hace `$days` dias en `$account` con su ultimo snapshot (`$views`/`$interactions`; `null` en `$views` = sin snapshot).
 *
 * @param  array<string, mixed>  $post
 */
function publishedPost(int $days, ?int $views, ?int $interactions, ?SocialAccount $account = null, array $post = []): Post
{
    $account ??= test()->ig;
    $p = Post::factory()->create($post + ['brand_id' => $account->brand_id, 'user_id' => $account->user_id, 'text' => 'Texto '.uniqid(), 'scheduled_at' => null, 'status' => PostStatus::Published]);
    $t = PostTarget::factory()->create([
        'post_id' => $p->id, 'user_id' => $account->user_id, 'social_account_id' => $account->id, 'network' => $account->network,
        'content_type' => $account->network === Network::Instagram ? ContentType::InstagramImage : ContentType::FacebookText,
        'status' => PostStatus::Published, 'published_at' => now()->subDays($days),
    ]);
    if ($views !== null) {
        PostMetricSnapshot::withoutUserScope()->create([
            'user_id' => $account->user_id, 'post_target_id' => $t->id, 'social_account_id' => $account->id, 'network' => $account->network->value,
            'date' => now()->subDay()->toDateString(), 'metrics' => array_replace(Metric::blankPost(), [Metric::VIEWS => $views, Metric::INTERACTIONS => $interactions]),
        ]);
    }

    return $p;
}

/** @return list<int> */
function proposedIds(?Brand $brand = null, ?User $user = null): array
{
    return array_column(test()->svc->proposals($user ?? test()->user, $brand ?? test()->brand)['proposals'], 'post_id');
}

/** @return array<string, int> */
function excludedCounts(): array
{
    return collect(test()->svc->proposals(test()->user, test()->brand)['excluded'])->pluck('count', 'reason')->all();
}

test('Reciclado: solo se proponen posts con metricas suficientes y maduras', function () {
    $good = publishedPost(40, 1000, 100);
    publishedPost(40, 20, 10);          // pocas visualizaciones
    publishedPost(40, null, null);      // sin snapshot
    publishedPost(40, 1000, null);      // sin interacciones: el engagement no se puede calcular
    publishedPost(3, 1000, 200);        // demasiado reciente: sus metricas aun no maduraron

    $result = $this->svc->proposals($this->user, $this->brand);

    expect(array_column($result['proposals'], 'post_id'))->toBe([$good->id])
        ->and($result['proposals'][0]['engagement_rate'])->toBe(10.0)
        ->and($result['proposals'][0]['views'])->toBe(1000)
        ->and($result['proposals'][0]['interactions'])->toBe(100)
        ->and(excludedCounts())->toEqual(['low_views' => 1, 'no_metrics' => 2, 'too_recent' => 1]);
});

test('Reciclado: ordena por engagement, el mismo criterio del dashboard, entre redes', function () {
    $ig = publishedPost(40, 1000, 50, $this->ig);   // 5 %
    $fb = publishedPost(40, 500, 100, $this->fb);   // 20 %
    $mid = publishedPost(40, 2000, 200, $this->ig); // 10 %

    expect(proposedIds())->toBe([$fb->id, $mid->id, $ig->id]);
});

test('Reciclado: un post no se vuelve a proponer antes del intervalo minimo desde su ultima publicacion', function () {
    config(['social.recycle.min_interval_days' => 60]);
    $recent = publishedPost(30, 1000, 100); // publicado hace 30 dias: dentro del intervalo
    $old = publishedPost(61, 1000, 100);

    expect(proposedIds())->toBe([$old->id])
        ->and(excludedCounts())->toEqual(['interval' => 1]);

    Carbon::setTestNow('2026-12-27 12:00:00'); // 30 dias despues el segundo ya cumple el intervalo
    expect(proposedIds())->toContain($recent->id);
});

test('Reciclado: el intervalo cuenta sobre el contenido: un reciclado (borrador, programado o publicado) bloquea al original y a la familia', function () {
    config(['social.recycle.min_interval_days' => 60]);
    $original = publishedPost(200, 1000, 100);
    expect(proposedIds())->toBe([$original->id]);

    // Reciclado en borrador creado hace 10 dias.
    $draft = Post::factory()->create(['brand_id' => $this->brand->id, 'scheduled_at' => null, 'status' => PostStatus::Draft, 'recycle_family_id' => $original->id]);
    $draft->forceFill(['created_at' => now()->subDays(10)])->save();
    expect(proposedIds())->toBe([]);

    // Pasado el intervalo desde su creacion, vuelve.
    $draft->forceFill(['created_at' => now()->subDays(61)])->save();
    expect(proposedIds())->toBe([$original->id]);

    // Reciclado ya programado aun no publicado: cuenta por su fecha programada (futura).
    $draft->forceFill(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDays(3)])->save();
    expect(proposedIds())->toBe([]);

    // Papelera: borrar el reciclado libera al original.
    $draft->delete();
    expect(proposedIds())->toBe([$original->id]);
});

test('Reciclado: un reciclado publicado con mejores metricas es la propuesta de su familia y el original no se repite', function () {
    config(['social.recycle.min_interval_days' => 30]);
    $original = publishedPost(200, 1000, 50);
    $recycled = publishedPost(40, 1000, 300, null, ['recycle_family_id' => $original->id]);

    $result = $this->svc->proposals($this->user, $this->brand);

    expect(array_column($result['proposals'], 'post_id'))->toBe([$recycled->id])
        ->and($result['proposals'][0]['recycled_times'])->toBe(1);

    // El reciclado publicado hace 10 dias bloquea a toda la familia.
    $recycled->targets()->update(['published_at' => now()->subDays(10)]);
    expect(proposedIds())->toBe([]);
});

test('Reciclado: reciclar crea un borrador con el mismo contenido y medios vinculado al original, sin publicar', function () {
    Queue::fake();
    $media = MediaAsset::factory()->for($this->user)->create();
    $original = publishedPost(40, 1000, 100);
    $original->media()->attach($media->id, ['position' => 0]);
    $original->forceFill(['text' => 'Contenido que funciona'])->save();

    $result = $this->svc->recycle($this->user, $original);
    $copy = $result->post;

    expect($copy->id)->not->toBe($original->id)
        ->and($copy->text)->toBe('Contenido que funciona')
        ->and($copy->status)->toBe(PostStatus::Draft)
        ->and($copy->scheduled_at)->toBeNull()
        ->and($copy->recycle_family_id)->toBe($original->id)
        ->and($copy->media->pluck('id')->all())->toBe([$media->id])
        ->and($copy->targets->pluck('social_account_id')->all())->toBe([$this->ig->id])
        ->and($copy->targets->first()->status)->toBe(PostStatus::Draft)
        ->and($copy->targets->first()->external_id)->toBeNull()
        ->and($original->fresh()->status)->toBe(PostStatus::Published);
    Queue::assertNothingPushed();

    // Ya reciclado: ni se propone ni se puede reciclar de nuevo antes del intervalo.
    expect(proposedIds())->toBe([]);
    expect(fn () => $this->svc->recycle($this->user, $original))->toThrow(PostValidationException::class);
    expect(Post::withoutUserScope()->count())->toBe(2);
});

test('Reciclado: reciclar un reciclado se vincula al original (el contenido es el mismo)', function () {
    $original = publishedPost(300, 1000, 50, $this->fb);
    $recycled = publishedPost(100, 1000, 300, $this->fb, ['recycle_family_id' => $original->id]);

    $copy = $this->svc->recycle($this->user, $recycled)->post;

    expect($copy->recycle_family_id)->toBe($original->id);
});

test('Reciclado: no se puede reciclar lo que no es una propuesta (metricas insuficientes o ajeno)', function () {
    $weak = publishedPost(40, 10, 1);
    expect(fn () => $this->svc->recycle($this->user, $weak))->toThrow(PostValidationException::class);

    $other = User::factory()->create();
    $good = publishedPost(40, 1000, 100);
    expect(fn () => $this->svc->recycle($other, $good))->toThrow(OwnershipException::class);
});

test('Reciclado: descartar oculta la propuesta hasta pasado el intervalo', function () {
    config(['social.recycle.min_interval_days' => 60]);
    $post = publishedPost(100, 1000, 100);

    $this->svc->dismiss($this->user, $post);
    expect(proposedIds())->toBe([])
        ->and(excludedCounts())->toEqual(['dismissed' => 1]);

    Carbon::setTestNow('2027-01-27 12:00:00');
    expect(proposedIds())->toBe([$post->id]);

    expect(fn () => $this->svc->dismiss(User::factory()->create(), $post))->toThrow(OwnershipException::class);
});

test('Reciclado: aislamiento por usuario y por marca', function () {
    $mine = publishedPost(40, 1000, 100);

    $otherUser = User::factory()->create();
    $otherBrand = Brand::factory()->for($otherUser)->create();
    $otherAccount = SocialAccount::factory()->for($otherUser)->create(['brand_id' => $otherBrand->id, 'network' => Network::Instagram]);
    $theirs = publishedPost(40, 5000, 900, $otherAccount);

    $sameUserOtherBrand = Brand::factory()->for($this->user)->create();
    $otherBrandAccount = SocialAccount::factory()->for($this->user)->create(['brand_id' => $sameUserOtherBrand->id, 'network' => Network::Instagram]);
    $inOtherBrand = publishedPost(40, 5000, 900, $otherBrandAccount);

    expect(proposedIds())->toBe([$mine->id])
        ->and(proposedIds($otherBrand, $otherUser))->toBe([$theirs->id])
        ->and(proposedIds($sameUserOtherBrand))->toBe([$inOtherBrand->id])
        ->and(fn () => $this->svc->proposals($otherUser, $this->brand))->toThrow(OwnershipException::class);
});

test('Reciclado: las cuentas desconectadas no aportan propuestas', function () {
    $post = publishedPost(40, 1000, 100, $this->fb);
    expect(proposedIds())->toBe([$post->id]);

    $this->fb->forceFill(['status' => SocialAccount::STATUS_DISCONNECTED])->save();

    expect(proposedIds())->toBe([])
        ->and(excludedCounts())->toEqual(['disconnected' => 1]);
    expect(fn () => $this->svc->recycle($this->user, $post))->toThrow(PostValidationException::class);
});

test('Reciclado web: la pagina muestra las propuestas, Reciclar abre el borrador y Descartar la oculta; lo ajeno es 404', function () {
    $post = publishedPost(40, 1000, 100, $this->fb);
    $other = User::factory()->create();

    $this->actingAs($this->user)->get(route('autolists.recycle.index', ['brand_id' => $this->brand->id]))
        ->assertInertia(fn ($page) => $page->component('Autolists/Recycle')
            ->where('proposals.0.post_id', $post->id)->where('proposals.0.targets.0.account', 'FB')->where('total', 1)->where('rules.min_views', 100));

    $this->actingAs($other)->post(route('autolists.recycle.store', $post))->assertNotFound();
    $this->actingAs($other)->post(route('autolists.recycle.dismiss', $post))->assertNotFound();

    $this->actingAs($this->user)->post(route('autolists.recycle.dismiss', $post))->assertRedirect()->assertSessionHas('success');
    $this->actingAs($this->user)->get(route('autolists.recycle.index', ['brand_id' => $this->brand->id]))
        ->assertInertia(fn ($page) => $page->where('proposals', [])->where('excluded.0.reason', 'dismissed'));

    $this->travel(61)->days();
    $this->actingAs($this->user)->post(route('autolists.recycle.store', $post))->assertRedirect()->assertSessionHas('success');
    $draft = Post::withoutUserScope()->where('recycle_family_id', $post->id)->sole();
    expect($draft->status)->toBe(PostStatus::Draft);

    // Segundo intento: ya reciclado, mensaje legible y nada nuevo.
    $this->actingAs($this->user)->post(route('autolists.recycle.store', $post))->assertRedirect()->assertSessionHas('error');
    expect(Post::withoutUserScope()->where('recycle_family_id', $post->id)->count())->toBe(1);
});

test('Reciclado: la familia sobrevive a la purga real del original (clave estable, sin FK)', function () {
    $original = publishedPost(300, 1000, 50, $this->fb);
    $r1 = publishedPost(10, 1000, 100, $this->fb, ['recycle_family_id' => $original->id]);
    $r2 = publishedPost(100, 1000, 400, $this->fb, ['recycle_family_id' => $original->id]);
    expect(proposedIds())->toBe([]);

    $original->delete();
    app(TrashService::class)->forceDelete($this->user, $original->fresh() ?? Post::withTrashed()->findOrFail($original->id));
    expect(Post::withTrashed()->whereKey($original->id)->exists())->toBeFalse()
        ->and($r2->fresh()->recycle_family_id)->toBe($original->id);

    // R1 se publico hace 10 dias: R2 (mismo contenido) no puede proponerse aunque el original ya no exista.
    expect(proposedIds())->toBe([]);
    expect(fn () => $this->svc->recycle($this->user, $r2))->toThrow(PostValidationException::class);

    // Pasado el intervalo, la familia vuelve y se recicla vinculada a la misma clave.
    Carbon::setTestNow('2027-01-27 12:00:00');
    expect(proposedIds())->toBe([$r2->id]);
    expect($this->svc->recycle($this->user, $r2)->post->recycle_family_id)->toBe($original->id);
});

test('Reciclado: con el original en la papelera el doble reciclado sigue bloqueado y su publicacion previa cuenta', function () {
    $original = publishedPost(10, 1000, 100, $this->fb);
    $r = publishedPost(100, 1000, 400, $this->fb, ['recycle_family_id' => $original->id]);
    $original->delete();

    // El original en la papelera sigue siendo una publicacion de hace 10 dias del mismo contenido.
    expect(proposedIds())->toBe([]);

    Carbon::setTestNow('2026-12-20 12:00:00');
    $this->svc->recycle($this->user, $r);
    expect(fn () => $this->svc->recycle($this->user, $r))->toThrow(PostValidationException::class)
        ->and(Post::withoutUserScope()->whereNotNull('recycle_family_id')->count())->toBe(2);
});

test('Reciclado: un post multidestino puntua y muestra el engagement agregado, no la media de tasas', function () {
    $post = publishedPost(40, 1000, 100, $this->fb);
    $t = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->ig->id, 'network' => Network::Instagram,
        'content_type' => ContentType::InstagramImage, 'status' => PostStatus::Published, 'published_at' => now()->subDays(40),
    ]);
    PostMetricSnapshot::withoutUserScope()->create(['user_id' => $this->user->id, 'post_target_id' => $t->id, 'social_account_id' => $this->ig->id, 'network' => 'instagram',
        'date' => now()->subDay()->toDateString(), 'metrics' => array_replace(Metric::blankPost(), [Metric::VIEWS => 100, Metric::INTERACTIONS => 50])]);

    $p = $this->svc->proposals($this->user, $this->brand)['proposals'][0];

    expect($p['views'])->toBe(1100)->and($p['interactions'])->toBe(150)->and($p['engagement_rate'])->toBe(13.64)
        ->and(array_column($p['targets'], 'id'))->toHaveCount(2);
});

test('Reciclado: descartar solo vale para una propuesta vigente', function () {
    $weak = publishedPost(40, 10, 1);
    expect(fn () => $this->svc->dismiss($this->user, $weak))->toThrow(PostValidationException::class)
        ->and($weak->fresh()->recycle_dismissed_at)->toBeNull();
});

test('Reciclado: se analizan como mucho max_targets destinos, los mas recientes primero', function () {
    config(['social.recycle.max_targets' => 1]);
    publishedPost(100, 1000, 900);
    $newer = publishedPost(40, 1000, 100);

    expect(proposedIds())->toBe([$newer->id]);
});

test('Reciclado web: reciclar y descartar tienen limitador de frecuencia', function () {
    foreach (['autolists.recycle.store', 'autolists.recycle.dismiss'] as $name) {
        expect(collect(app('router')->getRoutes()->getByName($name)->gatherMiddleware())->contains('throttle:autolists.recycle'))->toBeTrue();
    }
});

test('Reciclado: reciclar por POST directo da el mismo veredicto que la lista: solo el mejor miembro de la familia', function () {
    $original = publishedPost(300, 1000, 50, $this->fb);   // 5 %
    $recycled = publishedPost(100, 1000, 100, $this->fb, ['recycle_family_id' => $original->id]); // 10 %

    expect(proposedIds())->toBe([$recycled->id])
        ->and(fn () => $this->svc->recycle($this->user, $original))->toThrow(PostValidationException::class)
        ->and(fn () => $this->svc->dismiss($this->user, $original))->toThrow(PostValidationException::class);
    expect($this->svc->recycle($this->user, $recycled)->post->recycle_family_id)->toBe($original->id);
});

test('Reciclado: los destinos demasiado recientes no gastan el tope max_targets y lo que queda fuera por el tope se cuenta', function () {
    config(['social.recycle.max_targets' => 1]);
    $old = publishedPost(100, 1000, 900);
    $newer = publishedPost(40, 1000, 100);
    publishedPost(2, 1000, 100);
    publishedPost(3, 1000, 100);

    $result = $this->svc->proposals($this->user, $this->brand);

    expect(array_column($result['proposals'], 'post_id'))->toBe([$newer->id])
        ->and($result['not_analyzed'])->toBe(1)
        ->and(excludedCounts())->toEqual(['too_recent' => 2]);

    config(['social.recycle.max_targets' => 10]);
    expect($this->svc->proposals($this->user, $this->brand)['not_analyzed'])->toBe(0)
        ->and(proposedIds())->toBe([$old->id, $newer->id]);
});

test('Reciclado: un interbloqueo al reservar la familia es un aviso legible y no un 500', function () {
    $post = publishedPost(40, 1000, 100);
    $svc = new class(app(PostDuplicator::class)) extends RecycleService
    {
        protected function lockFamily(User $user, Brand $brand, int $key): void
        {
            throw new DeadlockException('deadlock');
        }
    };

    expect(fn () => $svc->recycle($this->user, $post))->toThrow(PostValidationException::class);
});
