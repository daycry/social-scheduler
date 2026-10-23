<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
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

test('CalendarEffectiveText: una plantilla con texto manda sobre el texto propio de los destinos', function () {
    ceCreate([['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => 'Propio']], 'Plantilla');

    expect(ceItemText())->toBe('Plantilla');
});

test('CalendarEffectiveText: se salta los destinos con texto propio vacio y se recorta a 140', function () {
    ceCreate([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => ''],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo', 'text' => str_repeat('b', 200)],
    ], null);

    expect(ceItemText())->toBe(str_repeat('b', 140));
});
