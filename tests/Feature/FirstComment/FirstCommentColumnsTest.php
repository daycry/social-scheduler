<?php

use App\Enums\FirstCommentStatus;
use App\Enums\PostStatus;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Schema;

// C-01b (A1): el estado del primer comentario vive en columnas de post_targets, independiente de PostStatus.

test('post_targets tiene las seis columnas del primer comentario', function () {
    expect(Schema::hasColumns('post_targets', [
        'first_comment', 'first_comment_status', 'first_comment_attempts', 'first_comment_external_id', 'first_comment_error', 'first_comment_at',
    ]))->toBeTrue();
});

test('el enum del comentario tiene los cinco valores', function () {
    expect(array_map(fn ($c) => $c->value, FirstCommentStatus::cases()))
        ->toBe(['pendiente', 'comentando', 'publicado', 'fallido', 'no_admitido']);
});

test('PostTarget castea el estado del comentario al enum y es independiente del estado de publicacion', function () {
    $target = PostTarget::factory()->status(PostStatus::Published)->create([
        'first_comment' => 'Mas info en el enlace',
        'first_comment_status' => FirstCommentStatus::Failed,
        'first_comment_error' => 'Comentarios cerrados',
        'first_comment_attempts' => 2,
        'first_comment_at' => now(),
    ])->fresh();

    expect($target->first_comment_status)->toBe(FirstCommentStatus::Failed)
        ->and($target->first_comment_attempts)->toBe(2)
        ->and($target->first_comment_at)->not->toBeNull()
        ->and($target->status)->toBe(PostStatus::Published);
});

test('un destino sin comentario conserva todo nulo', function () {
    $target = PostTarget::factory()->create()->fresh();

    expect($target->first_comment)->toBeNull()->and($target->first_comment_status)->toBeNull()
        ->and($target->first_comment_external_id)->toBeNull();
});
