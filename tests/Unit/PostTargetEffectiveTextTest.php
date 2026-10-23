<?php

use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('PostTargetEffectiveText: vinculado usa la plantilla, desvinculado el propio y la cadena vacia es propia', function () {
    $post = Post::factory()->create(['text' => 'Plantilla']);

    $linked = PostTarget::factory()->create(['post_id' => $post->id]);
    expect($linked->fresh()->text)->toBeNull()
        ->and($linked->fresh()->effectiveText())->toBe('Plantilla');

    $own = PostTarget::factory()->create(['post_id' => $post->id, 'content_type' => 'instagram_reel', 'text' => 'Propio']);
    expect($own->fresh()->effectiveText())->toBe('Propio');

    $empty = PostTarget::factory()->create(['post_id' => $post->id, 'content_type' => 'instagram_story', 'text' => '']);
    expect($empty->fresh()->text)->toBe('')
        ->and($empty->fresh()->effectiveText())->toBe('');

    $post->forceFill(['text' => 'Nueva'])->save();
    expect($linked->fresh()->effectiveText())->toBe('Nueva')
        ->and($own->fresh()->effectiveText())->toBe('Propio');
});

test('PostTargetEffectiveText: la columna post_targets.text existe y es nullable', function () {
    expect(Schema::hasColumn('post_targets', 'text'))->toBeTrue();
});
