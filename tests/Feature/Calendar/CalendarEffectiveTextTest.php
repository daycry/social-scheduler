<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// C-03 (T-22): el calendario y la lista muestran el primer texto efectivo si la plantilla esta vacia.

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->image = MediaAsset::factory()->for($this->user)->create();
});

function ceCreate(array $targets, ?string $template): void
{
    app(PostService::class)->create(test()->user, test()->brand, [
        'text' => $template, 'scheduled_at' => '2026-11-03 10:00', 'schedule' => true, 'media' => [test()->image->id], 'targets' => $targets,
    ]);
}

function ceItemText(string $view = 'week'): ?string
{
    $text = null;
    test()->actingAs(test()->user)->get(route('calendar.index', ['view' => $view, 'date' => '2026-11-03']))->assertOk()
        ->assertInertia(function (Assert $page) use (&$text) {
            $text = $page->toArray()['props']['items'][0]['text'] ?? null;
        });

    return $text;
}

test('CalendarEffectiveText: con la plantilla vacia y todos los destinos desvinculados muestra el primer texto efectivo', function () {
    ceCreate([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => 'Primero IG'],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo', 'text' => 'Segundo FB'],
    ], null);

    foreach (['week', 'month', 'list'] as $view) {
        expect(ceItemText($view))->toBe('Primero IG');
    }
});

test('CalendarEffectiveText: la plantilla con texto manda mientras algun destino la publique (vinculado)', function () {
    ceCreate([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => 'Propio'],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo'],
    ], 'Plantilla');

    expect(ceItemText())->toBe('Plantilla');
});

test('CalendarEffectiveText: si todos los destinos tienen texto propio se muestra el primero aunque la plantilla tenga texto (A2)', function () {
    ceCreate([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => 'Propio IG'],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo', 'text' => 'Propio FB'],
    ], 'Plantilla que no se publica');

    foreach (['week', 'month', 'list'] as $view) {
        expect(ceItemText($view))->toBe('Propio IG');
    }
});

test('CalendarEffectiveText: mostrar el texto de la plantilla no consulta el post de cada destino (sin N+1)', function () {
    ceCreate([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image'],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo'],
    ], null);

    DB::enableQueryLog();
    ceItemText();
    $postQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "posts" where "posts"."id" = ?'))->count();

    expect($postQueries)->toBe(0);
});

test('CalendarEffectiveText: se salta los destinos con texto propio vacio y se recorta a 140', function () {
    ceCreate([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => ''],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo', 'text' => str_repeat('b', 200)],
    ], null);

    expect(ceItemText())->toBe(str_repeat('b', 140));
});
