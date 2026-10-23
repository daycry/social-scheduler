<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\GenerateAutolistPosts;
use App\Models\Autolist;
use App\Models\AutolistItem;
use App\Models\AutolistSlot;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AutolistService;
use App\Services\LibraryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    // Domingo 2026-11-01 12:00 UTC.
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->image = MediaAsset::factory()->for($this->user)->create();
    $this->svc = app(AutolistService::class);
});

function autolistMake(bool $recycle = false, array $slots = [['day' => 2, 'time' => '10:00'], ['day' => 4, 'time' => '18:30']]): Autolist
{
    return test()->svc->create(test()->user, test()->brand, 'Semanal', [['account_id' => test()->ig->id, 'content_type' => 'instagram_image']], $slots, $recycle);
}

test('Autolistas: crear valida destinos y franjas', function () {
    $this->actingAs($this->user)->post(route('autolists.store'), [
        'brand_id' => $this->brand->id, 'name' => 'Lista', 'targets' => [['account_id' => $this->ig->id, 'content_type' => 'facebook_text']], 'slots' => [['day' => 1, 'time' => '09:00']],
    ])->assertSessionHasErrors('targets.0');

    $this->actingAs($this->user)->post(route('autolists.store'), [
        'brand_id' => $this->brand->id, 'name' => 'Lista', 'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_image']], 'slots' => [['day' => 9, 'time' => '25:00']],
    ])->assertSessionHasErrors(['slots.0.day', 'slots.0.time']);

    $this->actingAs($this->user)->post(route('autolists.store'), [
        'brand_id' => $this->brand->id, 'name' => 'Lista', 'recycle' => true, 'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_image']], 'slots' => [['day' => 1, 'time' => '09:00'], ['day' => 1, 'time' => '09:00']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $list = Autolist::withoutUserScope()->firstOrFail();
    expect($list->slots)->toBe([['day' => 1, 'time' => '09:00']])->and($list->recycle)->toBeTrue()->and($list->active)->toBeTrue();
});

test('Autolistas: genera programadas en la hora local de la marca usando PostService, con la media validada', function () {
    $list = autolistMake();
    $this->svc->addItem($this->user, $list, null, 'Primero', [$this->image->id]);
    $this->svc->addItem($this->user, $list, null, 'Segundo', [$this->image->id]);

    $res = $this->svc->generate($list, 7);

    expect($res)->toBe(['created' => 2, 'skipped' => 0]);
    $posts = Post::withoutUserScope()->orderBy('scheduled_at')->get();
    expect($posts)->toHaveCount(2)
        // Martes 3-nov 10:00 Madrid (CET, UTC+1) = 09:00 UTC; jueves 5-nov 18:30 = 17:30 UTC.
        ->and($posts[0]->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-03 09:00')
        ->and($posts[1]->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-05 17:30')
        ->and($posts[0]->text)->toBe('Primero')->and($posts[1]->text)->toBe('Segundo')
        ->and($posts[0]->status)->toBe(PostStatus::Scheduled)
        ->and($posts[0]->targets->first()->status)->toBe(PostStatus::Scheduled)
        ->and($posts[0]->media->pluck('id')->all())->toBe([$this->image->id]);
});

test('Autolistas: es idempotente (re-ejecutar no duplica) y respeta el cursor', function () {
    $list = autolistMake(true);
    $this->svc->addItem($this->user, $list, null, 'A', [$this->image->id]);
    $this->svc->addItem($this->user, $list, null, 'B', [$this->image->id]);

    $this->svc->generate($list, 7);
    $again = $this->svc->generate($list->fresh(), 7);

    expect($again)->toBe(['created' => 0, 'skipped' => 0])->and(Post::withoutUserScope()->count())->toBe(2)->and(AutolistSlot::withoutUserScope()->count())->toBe(2)
        ->and($list->fresh()->cursor)->toBe(2);

    // Pasado el tiempo, el job genera solo las franjas nuevas y continua la cola desde donde iba (reciclada).
    Carbon::setTestNow('2026-11-04 12:00:00');
    $res = $this->svc->generate($list->fresh(), 7);
    expect($res['created'])->toBeGreaterThan(0)
        ->and(Post::withoutUserScope()->orderBy('id')->pluck('text')->all()[2])->toBe('A');
});

test('Autolistas: sin reciclado la cola se agota; con reciclado vuelve a empezar', function () {
    $list = autolistMake(false);
    $this->svc->addItem($this->user, $list, null, 'Unico', [$this->image->id]);
    expect($this->svc->generate($list, 14))->toBe(['created' => 1, 'skipped' => 0])->and(Post::withoutUserScope()->count())->toBe(1);

    $r = autolistMake(true);
    $this->svc->addItem($this->user, $r, null, 'Unico', [$this->image->id]);
    expect($this->svc->generate($r, 7)['created'])->toBe(2);
});

test('Autolistas: las plantillas de la Biblioteca aportan texto y media; una lista en pausa no genera', function () {
    $template = app(LibraryService::class)->store($this->user, $this->brand, 'Plantilla', 'Desde la biblioteca', [$this->image->id]);
    $list = autolistMake();
    $this->svc->addItem($this->user, $list, $template->id, null);

    $list->forceFill(['active' => false])->save();
    expect($this->svc->generate($list->fresh(), 7))->toBe(['created' => 0, 'skipped' => 0]);

    $list->forceFill(['active' => true])->save();
    $this->svc->generate($list->fresh(), 7);
    $post = Post::withoutUserScope()->firstOrFail();
    expect($post->text)->toBe('Desde la biblioteca')->and($post->media->pluck('id')->all())->toBe([$this->image->id]);
});

test('Autolistas: una media que ya no es valida omite la franja con motivo y la cola avanza', function () {
    $list = autolistMake();
    $png = MediaAsset::factory()->for($this->user)->create(['mime' => 'image/png', 'path' => 'media/test/x.png']);
    $this->svc->addItem($this->user, $list, null, 'Con PNG', [$png->id]);
    $this->svc->addItem($this->user, $list, null, 'Valido', [$this->image->id]);

    $res = $this->svc->generate($list, 7);

    expect($res)->toBe(['created' => 1, 'skipped' => 1]);
    $skipped = AutolistSlot::withoutUserScope()->whereNull('post_id')->firstOrFail();
    expect($skipped->skipped_reason)->toContain('JPEG')->and(Post::withoutUserScope()->pluck('text')->all())->toBe(['Valido']);
});

test('Autolistas: el job diario recorre las listas activas de todos los usuarios, cada una con lo suyo', function () {
    $list = autolistMake();
    $this->svc->addItem($this->user, $list, null, 'Mio', [$this->image->id]);

    $other = User::factory()->create();
    $ob = Brand::factory()->for($other)->create(['timezone' => 'UTC']);
    $oa = SocialAccount::factory()->for($other)->create(['brand_id' => $ob->id, 'network' => Network::Instagram]);
    $oi = MediaAsset::factory()->for($other)->create();
    $ol = $this->svc->create($other, $ob, 'Ajena', [['account_id' => $oa->id, 'content_type' => 'instagram_image']], [['day' => 3, 'time' => '12:00']]);
    $this->svc->addItem($other, $ol, null, 'De otro', [$oi->id]);

    app(GenerateAutolistPosts::class)->handle($this->svc);

    expect(Post::withoutUserScope()->where('user_id', $this->user->id)->pluck('text')->all())->toBe(['Mio'])
        ->and(Post::withoutUserScope()->where('user_id', $other->id)->pluck('text')->all())->toBe(['De otro']);
});

test('Autolistas: aislamiento (no se puede crear con marca ajena, ni tocar/generar la lista de otro, ni usar plantilla o media ajenas)', function () {
    $other = User::factory()->create();
    $ob = Brand::factory()->for($other)->create();
    $oa = SocialAccount::factory()->for($other)->create(['brand_id' => $ob->id, 'network' => Network::Instagram]);
    $payload = ['name' => 'X', 'targets' => [['account_id' => $oa->id, 'content_type' => 'instagram_image']], 'slots' => [['day' => 1, 'time' => '09:00']]];

    $this->actingAs($this->user)->post(route('autolists.store'), $payload + ['brand_id' => $ob->id])->assertNotFound();
    // Cuenta ajena en una marca propia: destino no valido.
    $this->actingAs($this->user)->post(route('autolists.store'), ['brand_id' => $this->brand->id] + $payload)->assertSessionHasErrors('targets.0');

    $ol = $this->svc->create($other, $ob, 'Ajena', $payload['targets'], $payload['slots']);
    $this->actingAs($this->user)->patch(route('autolists.update', $ol), ['active' => false])->assertNotFound();
    $this->actingAs($this->user)->delete(route('autolists.destroy', $ol))->assertNotFound();
    $this->actingAs($this->user)->post(route('autolists.generate', $ol))->assertNotFound();
    $this->actingAs($this->user)->post(route('autolists.items.store', $ol), ['text' => 'x'])->assertNotFound();
    expect($ol->fresh()->active)->toBeTrue();

    $mine = autolistMake();
    $foreignMedia = MediaAsset::factory()->for($other)->create();
    $foreignTemplate = PostTemplate::unguarded(fn () => PostTemplate::withoutUserScope()->create(['user_id' => $other->id, 'brand_id' => $ob->id, 'name' => 'T', 'text' => 'x']));
    $this->actingAs($this->user)->post(route('autolists.items.store', $mine), ['media' => [$foreignMedia->id]])->assertSessionHasErrors('media');
    $this->actingAs($this->user)->post(route('autolists.items.store', $mine), ['post_template_id' => $foreignTemplate->id])->assertSessionHasErrors('post_template_id');
    expect($mine->items()->count())->toBe(0);
});

test('Autolistas: la pagina lista las autolistas de la marca y las acciones del dueno funcionan', function () {
    $list = autolistMake();

    $this->actingAs($this->user)->get(route('autolists.index'))->assertOk()
        ->assertInertia(fn ($p) => $p->component('Autolists/Index')->has('autolists', 1)->where('autolists.0.name', 'Semanal')->has('accounts', 1));

    $this->actingAs($this->user)->post(route('autolists.items.store', $list), ['text' => 'Hola', 'media' => [$this->image->id]])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('autolists.generate', $list))->assertSessionHas('success');
    expect(Post::withoutUserScope()->count())->toBe(1);
    $this->actingAs($this->user)->patch(route('autolists.update', $list), ['active' => false]);
    expect($list->fresh()->active)->toBeFalse();
    $this->actingAs($this->user)->delete(route('autolists.destroy', $list))->assertRedirect();
    expect(Autolist::withoutUserScope()->count())->toBe(0)->and(Post::withoutUserScope()->count())->toBe(1);
});

test('Autolistas: B2 cambiar la zona horaria de la marca NO duplica las franjas: las futuras se realinean', function () {
    $list = autolistMake(true);
    $this->svc->addItem($this->user, $list, null, 'A', [$this->image->id]);
    $this->svc->generate($list, 7);
    $before = Post::withoutUserScope()->orderBy('scheduled_at')->get()->map(fn ($p) => $p->scheduled_at->utc()->format('Y-m-d H:i'))->all();
    expect($before)->toBe(['2026-11-03 09:00', '2026-11-05 17:30']);

    $this->brand->forceFill(['timezone' => 'America/New_York'])->save(); // EST: UTC-5
    $res = $this->svc->generate($list->fresh(), 7);

    expect($res['created'])->toBe(0)
        ->and(Post::withoutUserScope()->count())->toBe(2)
        ->and(AutolistSlot::withoutUserScope()->count())->toBe(2)
        ->and(Post::withoutUserScope()->orderBy('scheduled_at')->get()->map(fn ($p) => $p->scheduled_at->utc()->format('Y-m-d H:i'))->all())
        ->toBe(['2026-11-03 15:00', '2026-11-05 23:30']);
});

test('Autolistas: B3 con una cuenta pendiente de reconectar no se quema la franja ni se consume el elemento', function () {
    $list = autolistMake();
    $this->svc->addItem($this->user, $list, null, 'Uno', [$this->image->id]);
    $this->svc->addItem($this->user, $list, null, 'Dos', [$this->image->id]);
    $this->ig->forceFill(['status' => SocialAccount::STATUS_RECONNECT])->save();

    $res = $this->svc->generate($list, 7);

    expect($res['created'])->toBe(0)->and($res['deferred'])->toBe(2)
        ->and(AutolistSlot::withoutUserScope()->count())->toBe(0)
        ->and($list->fresh()->cursor)->toBe(0)
        ->and(Post::withoutUserScope()->count())->toBe(0);

    // Al reconectar, la siguiente generacion (franja aun futura) la rellena.
    $this->ig->forceFill(['status' => SocialAccount::STATUS_ACTIVE])->save();
    $again = $this->svc->generate($list->fresh(), 7);
    expect($again['created'])->toBe(2)->and(Post::withoutUserScope()->count())->toBe(2);
});

test('Autolistas: B9 el cursor sigue al elemento (no al indice) y las plantillas borradas salen de la cola', function () {
    $list = autolistMake(false, [['day' => 2, 'time' => '10:00'], ['day' => 4, 'time' => '18:30'], ['day' => 6, 'time' => '09:00']]);
    $a = $this->svc->addItem($this->user, $list, null, 'A', [$this->image->id]);
    $template = app(LibraryService::class)->store($this->user, $this->brand, 'Efimera', 'Plantilla', [$this->image->id]);
    $t = $this->svc->addItem($this->user, $list, $template->id, null);
    $c = $this->svc->addItem($this->user, $list, null, 'C', [$this->image->id]);

    $this->svc->generate($list, 3); // solo la franja del martes: usa A
    expect(Post::withoutUserScope()->pluck('text')->all())->toBe(['A']);

    // Se borra A (ya usado) y la plantilla: el siguiente es C, no uno desplazado por el indice.
    $a->delete();
    $template->delete();
    $this->svc->generate($list->fresh(), 7);

    expect(Post::withoutUserScope()->orderBy('scheduled_at')->pluck('text')->all())->toBe(['A', 'C'])
        ->and(AutolistItem::withoutUserScope()->whereKey($t->id)->exists())->toBeFalse();
});

test('Autolistas: B12 el job programado reparte un job por lista', function () {
    Queue::fake();
    $one = autolistMake();
    $two = autolistMake();

    app(GenerateAutolistPosts::class)->handle($this->svc);

    Queue::assertPushed(GenerateAutolistPosts::class, 2);
    Queue::assertPushed(GenerateAutolistPosts::class, fn ($j) => $j->autolistId === $one->id);
    Queue::assertPushed(GenerateAutolistPosts::class, fn ($j) => $j->autolistId === $two->id);
});

test('Autolistas: una plantilla con texto propio por destino lo conserva en los destinos de la lista (B2)', function () {
    $template = app(LibraryService::class)->store($this->user, $this->brand, 'Con texto por red', 'Texto comun', [$this->image->id], [
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => 'Propio de Instagram'],
        ['account_id' => $this->ig->id + 999, 'content_type' => 'facebook_text', 'text' => 'De una cuenta que la lista no usa'],
    ]);
    $list = autolistMake();
    $this->svc->addItem($this->user, $list, $template->id, null);

    $this->svc->generate($list->fresh(), 7);

    $post = Post::withoutUserScope()->firstOrFail();
    $target = $post->targets->sole();
    expect($post->text)->toBe('Texto comun')
        ->and($target->text)->toBe('Propio de Instagram')
        ->and($target->effectiveText())->toBe('Propio de Instagram');
});

test('Autolistas: una plantilla sin texto propio (o con texto vacio) deja el destino vinculado al texto de la plantilla', function () {
    $template = app(LibraryService::class)->store($this->user, $this->brand, 'Sin texto por red', 'Texto comun', [$this->image->id], [
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => '  '],
    ]);
    $list = autolistMake();
    $this->svc->addItem($this->user, $list, $template->id, null);

    $this->svc->generate($list->fresh(), 7);

    expect(Post::withoutUserScope()->firstOrFail()->targets->sole()->text)->toBeNull();
});
