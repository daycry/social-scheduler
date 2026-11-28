<?php

use App\Models\Brand;
use App\Models\User;

test('the shared LIKE search matches wildcard characters literally in qualified columns and grouped conditions', function () {
    $user = User::factory()->create();
    $subject = Brand::factory()->for($user)->create(['name' => 'Literal _%!', 'description' => 'None']);
    $description = Brand::factory()->for($user)->create(['name' => 'Description match', 'description' => 'Literal _%!']);
    Brand::factory()->for($user)->create(['name' => 'Literal abc!', 'description' => 'Literal xy!']);

    $ids = Brand::ownedBy($user)->where(fn ($query) => $query
        ->whereLikeEscaped('brands.name', '_%!')
        ->whereLikeEscaped('brands.description', '_%!', 'or'))
        ->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe([$subject->id, $description->id]);
});
