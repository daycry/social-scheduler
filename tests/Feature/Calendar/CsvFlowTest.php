<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\CalendarView;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Marca A', 'timezone' => 'Europe/Madrid']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'ig_a']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => 'fb_a']);
});

function flowPost(string $local, SocialAccount $account, string $text, string $type): Post
{
    return app(PostService::class)->create($account->user, Brand::withoutUserScope()->findOrFail($account->brand_id), [
        'text' => $text, 'scheduled_at' => $local, 'schedule' => true,
        'media' => $type === 'facebook_text' ? [] : [MediaAsset::factory()->for($account->user)->create()->id],
        'targets' => [['account_id' => $account->id, 'content_type' => $type]],
    ]);
}

it('CA-13: la lista filtrada y su CSV contienen las mismas publicaciones y el texto con = sale neutralizado', function () {
    $formula = flowPost('2026-11-03 10:00', $this->ig, '=cmd|\' /C calc\'!A0', 'instagram_image');
    $plain = flowPost('2026-11-04 10:00', $this->ig, 'Texto normal', 'instagram_image');
    flowPost('2026-11-05 10:00', $this->fb, 'Solo Facebook', 'facebook_text');

    $filters = ['view' => 'list', 'date' => '2026-11-01', 'network' => 'instagram'];

    // Lo que ve el usuario en la lista...
    $listed = [];
    $this->actingAs($this->user)->get(route('calendar.index', $filters))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$listed) {
            $listed = collect($page->toArray()['props']['items'])->pluck('id')->sort()->values()->all();
            $page->where('export.total', 2)->where('export.truncated', false);
        });
    expect($listed)->toBe(collect([$formula->id, $plain->id])->sort()->values()->all());

    // ...es exactamente lo que descarga.
    $csv = $this->actingAs($this->user)->get(route('exports.posts', $filters))->streamedContent();
    $lines = array_filter(explode("\n", substr($csv, 3)));

    expect($lines)->toHaveCount(3) // cabecera + 2 filas
        ->and($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('Texto normal')->and($csv)->not->toContain('Solo Facebook')
        ->and($csv)->toContain('"\'=cmd|\' /C calc\'!A0"') // empieza por ' (neutralizado) dentro de comillas
        ->and($csv)->not->toContain(';"=cmd');

    // Ninguna celda de texto de la fila empieza por una formula.
    foreach ($lines as $line) {
        foreach (str_getcsv($line, ';') as $cell) {
            expect($cell === '' || ! preg_match('/^[=+\-@\t\r]/', $cell))->toBeTrue("celda con formula sin neutralizar: $cell");
        }
    }
});

it('CA-13: la misma exportacion desde la vista guardada coincide con la del filtro explicito', function () {
    flowPost('2026-11-03 10:00', $this->ig, 'Instagram uno', 'instagram_image');
    flowPost('2026-11-05 10:00', $this->fb, 'Facebook uno', 'facebook_text');

    $view = CalendarView::factory()->for($this->user)->create(['name' => 'Solo IG', 'filters' => ['network' => 'instagram', 'view' => 'list']]);
    $byView = $this->actingAs($this->user)->get(route('exports.posts', ['saved_view' => $view->id, 'date' => '2026-11-01']))->streamedContent();
    $byFilter = $this->actingAs($this->user)->get(route('exports.posts', ['network' => 'instagram', 'date' => '2026-11-01']))->streamedContent();

    expect($byView)->toBe($byFilter)->and($byView)->toContain('Instagram uno')->and($byView)->not->toContain('Facebook uno');
});
