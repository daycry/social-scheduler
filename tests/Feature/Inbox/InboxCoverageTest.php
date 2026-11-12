<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\InboxService;

/** T-22 · cobertura del inbox (LinkedIn y Pinterest con su motivo) y ayuda explicita cuando faltan permisos de Meta. */
test('la cobertura incluye LinkedIn y Pinterest como no disponibles, con su motivo', function () {
    $rows = collect(InboxService::coverage())->keyBy('network');

    expect($rows)->toHaveKeys(['linkedin', 'pinterest'])
        ->and($rows['linkedin']['read'])->toBeFalse()->and($rows['linkedin']['note'])->toContain('Community Management')
        ->and($rows['pinterest']['read'])->toBeFalse()->and($rows['pinterest']['note'])->toContain('comentarios');
});

test('el inbox explica que Facebook necesita META_EXTRA_SCOPES y reconectar, con los pasos', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => Network::Facebook, 'name' => 'Mi Pagina', 'scopes' => ['pages_manage_posts']]);

    $this->actingAs($user)->get(route('inbox.index'))->assertInertia(fn ($page) => $page
        ->where('accounts.0.can_read', false)
        ->where('accounts.0.missing_scopes', ['pages_read_user_content'])
        ->where('accounts.0.help', fn ($h) => str_contains($h, 'META_EXTRA_SCOPES') && str_contains($h, 'pages_read_user_content') && str_contains($h, 'reconect'))
    );
});

test('una cuenta con los permisos no lleva ayuda', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => Network::Instagram, 'scopes' => ['instagram_manage_comments']]);

    $this->actingAs($user)->get(route('inbox.index'))->assertInertia(fn ($page) => $page
        ->where('accounts.0.can_read', true)->where('accounts.0.help', null)->where('accounts.0.missing_scopes', []));
});
