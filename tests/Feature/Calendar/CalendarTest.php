<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 12:00:00'); // domingo
    $this->user = User::factory()->create();
    $this->brandA = Brand::factory()->for($this->user)->create(['name' => 'Marca A', 'timezone' => 'Europe/Madrid']);
    $this->brandB = Brand::factory()->for($this->user)->create(['name' => 'Marca B', 'timezone' => 'Europe/Madrid']);
    $this->igA = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brandA->id, 'network' => Network::Instagram]);
    $this->fbA = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brandA->id, 'network' => Network::Facebook]);
    $this->igB = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brandB->id, 'network' => Network::Instagram]);
    $this->image = MediaAsset::factory()->for($this->user)->create();
    $this->posts = app(PostService::class);
});

/** Programa una publicacion de IG (o FB) en la marca indicada a una hora LOCAL (Europe/Madrid). */
function schedulePost(string $local, ?Brand $brand = null, ?SocialAccount $account = null, string $type = 'instagram_image', string $text = 'Post'): Post
{
    $brand ??= test()->brandA;
    $account ??= test()->igA;

    return app(PostService::class)->create($brand->user, $brand, [
        'text' => $text, 'scheduled_at' => $local, 'schedule' => true, 'media' => [test()->image->id],
        'targets' => [['account_id' => $account->id, 'content_type' => $type]],
    ]);
}

test('Calendar: la semana muestra cada publicacion en su fecha y hora local con su estado (CA-08)', function () {
    $mon = schedulePost('2026-11-02 09:30', text: 'Lunes');
    $thu = schedulePost('2026-11-05 18:00', text: 'Jueves');
    schedulePost('2026-11-10 10:00', text: 'Semana siguiente');

    $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'week', 'date' => '2026-11-04']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Calendar/Index')
            ->where('view', 'week')->where('from', '2026-11-02')->where('to', '2026-11-08')->where('timezone', 'Europe/Madrid')
            ->has('items', 2)
            ->where('items.0.id', $mon->id)->where('items.0.local_date', '2026-11-02')->where('items.0.local_time', '09:30')
            ->where('items.0.status', 'programada')->where('items.0.editable', true)
            ->where('items.0.targets.0.network', 'instagram')->where('items.0.targets.0.status', 'programada')
            ->where('items.1.id', $thu->id)->where('items.1.local_time', '18:00')
            ->where('prev', '2026-10-28')->where('next', '2026-11-11'));
});

test('Calendar: el mes cubre de lunes a domingo las semanas del mes', function () {
    schedulePost('2026-11-30 10:00', text: 'Fin de mes');
    schedulePost('2026-10-31 10:00', text: 'Octubre'); // dentro de la primera semana visible (lunes 26-oct)

    $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'month', 'date' => '2026-11-15']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('from', '2026-10-26')->where('to', '2026-12-06')->has('items', 2));
});

test('Calendar: filtra por marca y por red', function () {
    schedulePost('2026-11-03 10:00', $this->brandA, $this->igA, text: 'A-IG');
    schedulePost('2026-11-03 11:00', $this->brandA, $this->fbA, 'facebook_photo', text: 'A-FB');
    schedulePost('2026-11-03 12:00', $this->brandB, $this->igB, text: 'B-IG');

    $get = fn (array $q) => $this->actingAs($this->user)->get(route('calendar.index', $q + ['view' => 'week', 'date' => '2026-11-03']));

    $get([])->assertInertia(fn (Assert $p) => $p->has('items', 3));
    $get(['brand_id' => $this->brandA->id])->assertInertia(fn (Assert $p) => $p->has('items', 2)->where('items.0.brand_name', 'Marca A'));
    $get(['network' => 'instagram'])->assertInertia(fn (Assert $p) => $p->has('items', 2));
    $get(['brand_id' => $this->brandA->id, 'network' => 'facebook'])->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.text', 'A-FB')->has('items.0.targets', 1));
});

test('Calendar: la hora se muestra en la zona horaria de la marca filtrada', function () {
    $ny = Brand::factory()->for($this->user)->create(['timezone' => 'America/New_York']);
    $acc = SocialAccount::factory()->for($this->user)->create(['brand_id' => $ny->id, 'network' => Network::Instagram]);
    schedulePost('2026-11-03 09:00', $ny, $acc); // schedulePost usa $brand->timezone: 09:00 hora de Nueva York

    $this->actingAs($this->user)->get(route('calendar.index', ['brand_id' => $ny->id, 'view' => 'week', 'date' => '2026-11-03']))
        ->assertInertia(fn (Assert $p) => $p->where('timezone', 'America/New_York')->where('items.0.local_time', '09:00'));
});

test('Calendar: la lista muestra el estado por red, borradores sin fecha, busqueda y filtro de estado', function () {
    $ok = schedulePost('2026-11-05 10:00', $this->brandA, $this->igA, text: 'Oferta de otono');
    $t = $ok->targets()->first();
    $this->posts->transition($t, PostStatus::Publishing);
    $this->posts->transition($t, PostStatus::Failed, ['error_message' => 'Instagram rechazo el video']);
    schedulePost('2026-11-06 10:00', text: 'Otra cosa');
    $this->posts->create($this->user, $this->brandA, ['text' => 'Idea sin fecha', 'schedule' => false]);

    $get = fn (array $q = []) => $this->actingAs($this->user)->get(route('calendar.index', $q + ['view' => 'list', 'date' => '2026-11-01']));

    $get()->assertInertia(fn (Assert $p) => $p->has('items', 2)->has('undated', 1)->where('undated.0.text', 'Idea sin fecha')
        ->where('items.0.targets.0.status', 'fallida')->where('items.0.targets.0.error_message', 'Instagram rechazo el video')
        ->where('items.0.editable', true));
    $get(['q' => 'otono'])->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.id', $ok->id));
    $get(['status' => 'fallida'])->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.id', $ok->id));
    $get(['status' => 'programada'])->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.text', 'Otra cosa'));
});

test('Calendar: reprograma una publicacion pendiente (E2E-08) conservando la zona horaria', function () {
    $post = schedulePost('2026-11-05 10:00');

    $this->actingAs($this->user)->from(route('calendar.index'))
        ->patch(route('posts.reschedule', $post), ['scheduled_at' => '2026-11-06T17:15'])
        ->assertRedirect(route('calendar.index'))->assertSessionHas('success');

    expect($post->fresh()->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-06 16:15');

    $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'week', 'date' => '2026-11-06']))
        ->assertInertia(fn (Assert $p) => $p->where('items.0.local_date', '2026-11-06')->where('items.0.local_time', '17:15'));
});

test('Calendar: una publicada no se puede reprogramar y una fecha pasada o invalida se rechaza', function () {
    $post = schedulePost('2026-11-05 10:00');
    $original = $post->fresh()->scheduled_at->toIso8601String();

    $this->actingAs($this->user)->from(route('calendar.index'))->patch(route('posts.reschedule', $post), ['scheduled_at' => '2026-10-20T10:00'])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'futura'));
    $this->actingAs($this->user)->from(route('calendar.index'))->patch(route('posts.reschedule', $post), ['scheduled_at' => 'no es una fecha'])
        ->assertSessionHas('error');

    $t = $post->targets()->first();
    $this->posts->transition($t, PostStatus::Publishing);
    $this->posts->transition($t, PostStatus::Published, ['external_id' => '1']);
    $this->actingAs($this->user)->from(route('calendar.index'))->patch(route('posts.reschedule', $post), ['scheduled_at' => '2026-11-20T10:00'])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'ya se ha publicado'));

    expect($post->fresh()->scheduled_at->toIso8601String())->toBe($original);
    $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'week', 'date' => '2026-11-05']))
        ->assertInertia(fn (Assert $p) => $p->where('items.0.editable', false)->where('items.0.status', 'publicada'));
});

test('Calendar: aislamiento, nunca aparecen publicaciones de otro usuario ni se pueden reprogramar', function () {
    $mine = schedulePost('2026-11-05 10:00', text: 'Mia');
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create(['timezone' => 'Europe/Madrid']);
    $otherAcc = SocialAccount::factory()->for($other)->create(['brand_id' => $otherBrand->id, 'network' => Network::Instagram]);
    $otherImg = MediaAsset::factory()->for($other)->create();
    $theirs = app(PostService::class)->create($other, $otherBrand, ['text' => 'Ajena', 'scheduled_at' => '2026-11-05 10:00', 'schedule' => true, 'media' => [$otherImg->id], 'targets' => [['account_id' => $otherAcc->id, 'content_type' => 'instagram_image']]]);

    $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'week', 'date' => '2026-11-05']))
        ->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.id', $mine->id)->has('brands', 2));
    $this->actingAs($this->user)->get(route('calendar.index', ['brand_id' => $otherBrand->id]))->assertNotFound();
    $this->actingAs($this->user)->patch(route('posts.reschedule', $theirs), ['scheduled_at' => '2026-11-20T10:00'])->assertNotFound();

    $this->actingAs($other)->get(route('calendar.index', ['view' => 'week', 'date' => '2026-11-05']))
        ->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.id', $theirs->id));
});

test('Calendar: parametros invalidos y acceso sin sesion', function () {
    $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'anio']))->assertSessionHasErrors('view');
    $this->actingAs($this->user)->get(route('calendar.index', ['date' => '31/12/2026']))->assertSessionHasErrors('date');
    $this->actingAs($this->user)->get(route('calendar.index', ['network' => 'myspace']))->assertNotFound();

    auth()->logout();
    $this->get(route('calendar.index'))->assertRedirect(route('login'));
});
