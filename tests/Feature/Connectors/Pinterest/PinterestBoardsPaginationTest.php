<?php

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Network;
use App\Connectors\Pinterest\PinterestConnector;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\NewNetworksFake;

// T-21 (B-04): los tableros se leen paginados con `bookmark` (`GET /v5/boards`, `page_size` 1-250, el `bookmark` de la respuesta pide la
// siguiente pagina; `null` o ausente = ultima) y se pueden refrescar desde Conexiones. `GET /v5/boards` si funciona en el sandbox.

beforeEach(function () {
    Http::preventStrayRequests();
    $this->account = NewNetworksFake::pinterestAccount(['scopes' => ['boards:read', 'pins:read', 'pins:write', 'user_accounts:read']]);
    $this->user = $this->account->user;
    $this->brand = Brand::withoutGlobalScopes()->findOrFail($this->account->brand_id);
    $this->connector = app(PinterestConnector::class);
});

function pinBoards(int $from, int $count): array
{
    return array_map(fn (int $i) => ['id' => (string) (1000 + $i), 'name' => 'Tablero '.$i], range($from, $from + $count - 1));
}

function pinBoardRequests(): array
{
    return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/v5/boards'))->values()->all();
}

test('al conectar sigue el bookmark hasta la ultima pagina', function () {
    Http::fake([
        'api.pinterest.com/v5/oauth/token' => Http::response(['access_token' => 'PI-A', 'refresh_token' => 'PI-R', 'expires_in' => 2592000]),
        'api.pinterest.com/v5/user_account' => Http::response(['username' => 'ana_pins']),
        'api.pinterest.com/v5/boards*' => Http::sequence()
            ->push(['items' => pinBoards(1, 3), 'bookmark' => 'BM1'])
            ->push(['items' => pinBoards(4, 3), 'bookmark' => 'BM2'])
            ->push(['items' => pinBoards(7, 2), 'bookmark' => null]),
    ]);

    [$candidate] = $this->connector->exchangeCode('CODE', 'https://app.test/cb');
    $requests = pinBoardRequests();

    expect($candidate->meta['boards'])->toHaveCount(8)->and($candidate->meta['boards'][7])->toBe(['id' => '1008', 'name' => 'Tablero 8'])
        ->and($requests)->toHaveCount(3);
    parse_str((string) parse_url($requests[1]->url(), PHP_URL_QUERY), $second);
    expect($second['bookmark'])->toBe('BM1')->and($second['page_size'])->toBe('100');
    parse_str((string) parse_url($requests[0]->url(), PHP_URL_QUERY), $first);
    expect($first)->not->toHaveKey('bookmark');
});

test('un bookmark repetido o demasiadas paginas cortan la lectura sin bucle', function () {
    Http::fake(['api.pinterest.com/v5/boards*' => Http::response(['items' => pinBoards(1, 2), 'bookmark' => 'SAME'])]);

    $boards = $this->connector->refreshBoards($this->account);

    expect(pinBoardRequests())->toHaveCount(2)->and($boards)->toHaveCount(2);
});

test('refreshBoards guarda la lista nueva y la fecha en la cuenta', function () {
    Http::fake(['api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => '42', 'name' => 'Nuevo'], ['id' => 'x', 'name' => 'Mal']], 'bookmark' => null])]);

    $boards = $this->connector->refreshBoards($this->account);
    $meta = $this->account->fresh()->meta;

    expect($boards)->toBe([['id' => '42', 'name' => 'Nuevo']])->and($meta['boards'])->toBe([['id' => '42', 'name' => 'Nuevo']])
        ->and($meta['boards_refreshed_at'])->not->toBeNull();
});

test('si el refresco falla se conserva la lista anterior', function () {
    Http::fake(['api.pinterest.com/v5/boards*' => Http::response(['message' => 'forbidden'], 403)]);

    expect(fn () => $this->connector->refreshBoards($this->account))->toThrow(ConnectorException::class);
    expect($this->account->fresh()->meta['boards'])->toBe([['id' => '549755885175', 'name' => 'Recetas']]);
});

test('Conexiones expone el numero de tableros y el boton refresca por POST solo para el propietario', function () {
    Http::fake(['api.pinterest.com/v5/boards*' => Http::response(['items' => pinBoards(1, 4), 'bookmark' => null])]);
    $index = route('brands.connections.index', $this->brand, false);

    $this->actingAs($this->user)->get($index)->assertInertia(fn (Assert $p) => $p->where('accounts.0.boards_count', 1));

    $url = route('brands.connections.pinterest-boards.refresh', [$this->brand, $this->account], false);
    $this->actingAs($this->user)->post($url)->assertRedirect($index)->assertSessionHas('success');
    expect($this->account->fresh()->meta['boards'])->toHaveCount(4);

    $other = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::YouTube]);
    $this->actingAs($this->user)->post(route('brands.connections.pinterest-boards.refresh', [$this->brand, $other], false))->assertNotFound();
    $this->actingAs(User::factory()->create())->post($url)->assertNotFound();
});

test('un fallo de Pinterest al refrescar se avisa sin romper', function () {
    Http::fake(['api.pinterest.com/v5/boards*' => Http::response(['message' => 'forbidden'], 403)]);

    $this->actingAs($this->user)->post(route('brands.connections.pinterest-boards.refresh', [$this->brand, $this->account], false))
        ->assertRedirect()->assertSessionHas('error');
});
