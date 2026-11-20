<?php

use App\Enums\ContentType;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAsset;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\PostService;
use App\Services\Studio\StudioDocument;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->brand = Brand::factory()->for($user)->create();
    $this->asset = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $this->design = $this->postJson("/brands/{$this->brand->id}/studio/designs", ['name' => 'Origen de la publicación', 'document' => StudioDocument::blank()])
        ->assertCreated()->json('design.id');
    $this->export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$this->asset->id],
    ])->assertCreated()->json('export.id');
});

test('StudioBridge guarda la exportación en el borrador aunque se cree sin destinos', function () {
    $post = $this->postJson("/studio/exports/{$this->export}/post", ['text' => 'Prueba'])
        ->assertCreated()->assertJsonPath('post.status', 'borrador')->json('post.id');

    $this->assertDatabaseHas('posts', ['id' => $post, 'design_export_id' => $this->export]);
    $this->assertDatabaseHas('post_media', ['post_id' => $post, 'media_asset_id' => $this->asset->id, 'design_export_id' => $this->export]);
    $draft = Post::findOrFail($post);
    expect($draft->targets)->toBeEmpty();
});

test('StudioBridge conserva la procedencia solo para el archivo exportado al editar un destino', function () {
    $account = SocialAccount::factory()->for($this->brand)->create();
    $other = MediaAsset::factory()->for($this->brand->user)->create(['mime' => 'image/jpeg']);
    $target = [
        'account_id' => $account->id,
        'content_type' => ContentType::InstagramImage->value,
        'media' => [['id' => $this->asset->id]],
    ];
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['targets' => [$target]])
        ->assertCreated()->json('post.id');
    $post = Post::findOrFail($postId);
    $targetId = $post->targets()->sole()->id;
    $this->assertDatabaseHas('post_target_media', [
        'post_target_id' => $targetId, 'media_asset_id' => $this->asset->id, 'design_export_id' => $this->export,
    ]);

    // El usuario puede pasar el mismo archivo de la lista común a la lista propia y editar su texto alternativo.
    $target['media'] = [['id' => $this->asset->id, 'alt' => 'Descripción nueva']];
    app(PostService::class)->update($this->brand->user, $post, ['media' => [], 'targets' => [$target]]);
    $this->assertDatabaseHas('post_target_media', [
        'post_target_id' => $targetId, 'media_asset_id' => $this->asset->id,
        'design_export_id' => $this->export, 'alt' => 'Descripción nueva',
    ]);

    $target['media'] = [['id' => $other->id, 'design_export_id' => $this->export]];
    app(PostService::class)->update($this->brand->user, $post, ['media' => [], 'targets' => [$target]]);
    $this->assertDatabaseHas('post_target_media', [
        'post_target_id' => $targetId, 'media_asset_id' => $other->id, 'design_export_id' => null,
    ]);
});

test('StudioBridge deja los archivos adjuntos intactos al eliminar la exportación', function () {
    $account = SocialAccount::factory()->for($this->brand)->create();
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['targets' => [[
        'account_id' => $account->id,
        'content_type' => ContentType::InstagramImage->value,
        'media' => [['id' => $this->asset->id]],
    ]]])->assertCreated()->json('post.id');
    $target = Post::findOrFail($postId)->targets()->sole();
    $this->assertDatabaseHas('post_target_media', [
        'post_target_id' => $target->id, 'media_asset_id' => $this->asset->id, 'design_export_id' => $this->export,
    ]);

    DesignExport::findOrFail($this->export)->delete();

    $this->assertDatabaseHas('post_media', ['post_id' => $postId, 'media_asset_id' => $this->asset->id]);
    $this->assertDatabaseHas('posts', ['id' => $postId, 'design_export_id' => null]);
    $this->assertDatabaseHas('post_target_media', ['post_target_id' => $target->id, 'media_asset_id' => $this->asset->id, 'design_export_id' => null]);
});

test('StudioBridge enlaza a la edición del diseño en la marca del borrador', function () {
    $postId = $this->postJson("/studio/exports/{$this->export}/post")->assertCreated()->json('post.id');
    $this->get(route('posts.edit', $postId))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Posts/Compose')->where('post.studio_origin_url', route('studio.designs.edit', [
            'design' => $this->design, 'brand_id' => $this->brand->id, 'post_id' => $postId,
        ])));

    $otherBrand = Brand::factory()->for($this->brand->user)->create();
    Post::findOrFail($postId)->forceFill(['brand_id' => $otherBrand->id])->save();
    $this->get(route('posts.edit', $postId))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('post.studio_origin_url', null));
});

test('StudioBridge omite enlaces a diseños archivados', function () {
    $postId = $this->postJson("/studio/exports/{$this->export}/post")->assertCreated()->json('post.id');
    Design::findOrFail($this->design)->delete();

    $this->get(route('posts.edit', $postId))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('post.studio_origin_url', null));
});

test('StudioBridge no atribuye al destino la exportación de otra marca', function () {
    $postId = $this->postJson("/studio/exports/{$this->export}/post")->assertCreated()->json('post.id');
    $otherBrand = Brand::factory()->for($this->brand->user)->create();
    $post = Post::findOrFail($postId);
    $post->forceFill(['brand_id' => $otherBrand->id])->save();
    $account = SocialAccount::factory()->for($otherBrand)->create();

    $fresh = app(PostService::class)->update($this->brand->user, $post, ['targets' => [[
        'account_id' => $account->id, 'content_type' => ContentType::InstagramImage->value,
        'media' => [['id' => $this->asset->id]],
    ]]]);
    $this->assertDatabaseHas('post_target_media', [
        'post_target_id' => $fresh->targets->sole()->id, 'media_asset_id' => $this->asset->id, 'design_export_id' => null,
    ]);
});

test('StudioBridge actualiza el borrador de origen al reexportar y conserva la media que no viene del diseño', function () {
    $user = $this->brand->user;
    $extra = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $replacement = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['text' => 'Texto original'])
        ->assertCreated()->json('post.id');
    $draft = Post::findOrFail($postId);
    $draft->media()->attach($extra->id, ['position' => 1]);

    $export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$replacement->id],
    ])->assertCreated()->json('export.id');

    $this->postJson("/studio/exports/{$export}/post", ['post_id' => $postId])
        ->assertSuccessful()->assertJsonPath('post.id', $postId);

    expect(Post::ownedBy($user->id)->count())->toBe(1);
    $draft->refresh();
    expect($draft->design_export_id)->toBe($export)->and($draft->text)->toBe('Texto original')
        ->and($draft->media->pluck('id')->all())->toBe([$replacement->id, $extra->id]);
});

test('StudioBridge reemplaza un adjunto propio conservando su texto alternativo y su destino programado', function () {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $replacement = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $target = ['account_id' => $account->id, 'content_type' => ContentType::InstagramImage->value,
        'media' => [['id' => $this->asset->id, 'alt' => 'Descripción que se conserva']]];
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['targets' => [$target]])
        ->assertCreated()->json('post.id');
    $draft = Post::findOrFail($postId);
    $when = now()->addDay()->startOfMinute();
    $scheduled = app(PostService::class)->update($user, $draft, ['targets' => [$target], 'schedule' => true, 'scheduled_at' => $when]);
    $targetId = $scheduled->targets->sole()->id;

    $export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$replacement->id],
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$export}/post", ['post_id' => $postId])
        ->assertOk()->assertJsonPath('post.id', $postId)->assertJsonPath('post.status', 'programada');

    expect($draft->fresh()->scheduled_at->equalTo($when))->toBeTrue();
    $this->assertDatabaseHas('post_target_media', [
        'post_target_id' => $targetId, 'media_asset_id' => $replacement->id, 'design_export_id' => $export, 'alt' => 'Descripción que se conserva',
    ]);
});

test('StudioBridge reemplaza solo adjuntos propios con procedencia de la exportación anterior', function () {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $otherAccount = SocialAccount::factory()->for($this->brand)->create();
    $replacement = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['targets' => [
        [
            'account_id' => $account->id, 'content_type' => ContentType::InstagramImage->value,
            'media' => [['id' => $this->asset->id, 'alt' => 'Descripción de la página']],
        ],
        [
            'account_id' => $otherAccount->id, 'content_type' => ContentType::InstagramImage->value,
            'media' => [['id' => $this->asset->id, 'alt' => 'Descripción independiente']],
        ],
    ]])->assertCreated()->json('post.id');
    $post = Post::findOrFail($postId);
    $independent = $post->targets()->where('social_account_id', $otherAccount->id)->sole();
    $independent->mediaItems()->sole()->update(['design_export_id' => null]);

    $export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$replacement->id],
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$export}/post", ['post_id' => $postId])->assertOk();

    $this->assertDatabaseHas('post_target_media', [
        'post_target_id' => $independent->id, 'media_asset_id' => $this->asset->id,
        'design_export_id' => null, 'alt' => 'Descripción independiente',
    ]);
    $this->assertDatabaseHas('post_target_media', [
        'post_target_id' => $post->targets()->where('social_account_id', $account->id)->sole()->id,
        'media_asset_id' => $replacement->id, 'design_export_id' => $export, 'alt' => 'Descripción de la página',
    ]);
});

test('StudioBridge conserva la descripción y la procedencia si la nueva exportación coincide con un adjunto independiente', function () {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $independent = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $extra = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['targets' => [[
        'account_id' => $account->id, 'content_type' => ContentType::InstagramCarousel->value,
        'media' => [
            ['id' => $independent->id, 'alt' => 'Descripción independiente'],
            ['id' => $this->asset->id, 'alt' => 'Descripción de la página anterior'],
            ['id' => $extra->id],
        ],
    ]]])->assertCreated()->json('post.id');

    $export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$independent->id],
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$export}/post", ['post_id' => $postId])->assertOk();

    $target = Post::findOrFail($postId)->targets()->sole();
    expect($target->mediaItems()->pluck('media_asset_id')->all())->toBe([$independent->id, $extra->id]);
    $this->assertDatabaseHas('post_target_media', [
        'post_target_id' => $target->id, 'media_asset_id' => $independent->id,
        'design_export_id' => null, 'alt' => 'Descripción independiente',
    ]);
});

test('StudioBridge conserva en la lista común un archivo retirado y vuelto a adjuntar', function () {
    $user = $this->brand->user;
    $replacement = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['text' => 'Original'])
        ->assertCreated()->json('post.id');
    $draft = Post::findOrFail($postId);
    $posts = app(PostService::class);
    $posts->update($user, $draft, ['text' => 'Sin adjuntos', 'media' => []]);
    $posts->update($user, $draft->fresh(), ['text' => 'Archivo independiente', 'media' => [$this->asset->id]]);

    $export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$replacement->id],
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$export}/post", ['post_id' => $postId])->assertOk();

    expect($draft->fresh()->media->modelKeys())->toBe([$this->asset->id]);
});

test('StudioBridge conserva el origen cuando un destino personaliza los adjuntos que heredaba', function () {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $replacement = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $target = ['account_id' => $account->id, 'content_type' => ContentType::InstagramImage->value];
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['targets' => [$target]])
        ->assertCreated()->json('post.id');

    app(PostService::class)->update($user, Post::findOrFail($postId), [
        'media' => [$this->asset->id],
        'targets' => [[...$target, 'media' => [['id' => $this->asset->id, 'alt' => 'Texto del destino']]]],
    ]);

    $export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$replacement->id],
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$export}/post", ['post_id' => $postId])->assertOk();

    $item = Post::findOrFail($postId)->targets()->sole()->mediaItems()->sole();
    expect($item->media_asset_id)->toBe($replacement->id)
        ->and($item->alt)->toBe('Texto del destino')
        ->and($item->design_export_id)->toBe($export);
});

test('StudioBridge conserva la fecha del borrador y pide aprobación para el contenido nuevo', function (bool $approved, bool $scheduled) {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $replacement = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $target = ['account_id' => $account->id, 'content_type' => ContentType::InstagramImage->value];
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['targets' => [$target]])
        ->assertCreated()->json('post.id');
    $when = now()->addDay()->startOfMinute();
    $post = app(PostService::class)->update($user, Post::findOrFail($postId), [
        'media' => [$this->asset->id], 'targets' => [$target], 'schedule' => false, 'scheduled_at' => $when,
    ]);
    $approval = app(ApprovalService::class);
    $post = $approval->request($user, $post);
    if ($approved) {
        $post = $approval->approve($user, $post, $post->approval_signature);
        expect($post->approval_status)->toBe(Post::APPROVAL_APPROVED);
    }
    if ($scheduled) {
        $post = app(PostService::class)->update($user, $post, [
            'media' => [$this->asset->id], 'targets' => [$target], 'schedule' => true, 'scheduled_at' => $when,
        ]);
        expect($post->status->value)->toBe('programada')
            ->and($post->approval_status)->toBe(Post::APPROVAL_APPROVED);
    }
    $previousSignature = $post->approval_signature;

    $export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$replacement->id],
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$export}/post", ['post_id' => $postId])
        ->assertOk()->assertJsonPath('post.status', 'borrador');

    $post = $post->fresh();
    expect($post->scheduled_at->equalTo($when))->toBeTrue()
        ->and($post->approval_status)->toBe(Post::APPROVAL_PENDING)
        ->and($post->approval_signature)->not->toBe($previousSignature)
        ->and($post->media->modelKeys())->toBe([$replacement->id]);
})->with([[false, false], [true, false], [true, true]]);

test('StudioBridge no marca como exportado un archivo añadido después a un destino nuevo', function () {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $replacement = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['text' => 'Original'])
        ->assertCreated()->json('post.id');
    $draft = Post::findOrFail($postId);
    app(PostService::class)->update($user, $draft, [
        'text' => $draft->text, 'media' => [$this->asset->id], 'targets' => [[
            'account_id' => $account->id, 'content_type' => ContentType::InstagramImage->value,
            'media' => [['id' => $this->asset->id, 'alt' => 'Adjunto independiente']],
        ]],
    ]);

    $export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$replacement->id],
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$export}/post", ['post_id' => $postId])->assertOk();

    $target = $draft->targets()->sole();
    $this->assertDatabaseHas('post_target_media', [
        'post_target_id' => $target->id, 'media_asset_id' => $this->asset->id,
        'design_export_id' => null, 'alt' => 'Adjunto independiente',
    ]);
    expect($draft->fresh()->media->modelKeys())->toBe([$replacement->id]);
});

test('StudioBridge desvincula un adjunto retirado y readjuntado antes de guardar el formulario', function () {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $target = [
        'account_id' => $account->id, 'content_type' => ContentType::InstagramImage->value,
        'media' => [['id' => $this->asset->id]],
    ];
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['targets' => [$target]])->assertCreated()->json('post.id');
    $payload = [
        'schedule' => false,
        'media' => [$this->asset->id], 'detached_media' => [$this->asset->id],
        'targets' => [[...$target, 'detached_media' => [$this->asset->id]]],
    ];

    $this->put(route('posts.update', $postId), $payload)->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('post_media', ['post_id' => $postId, 'media_asset_id' => $this->asset->id, 'design_export_id' => null]);
    $this->assertDatabaseHas('post_target_media', [
        'post_target_id' => Post::findOrFail($postId)->targets()->sole()->id,
        'media_asset_id' => $this->asset->id, 'design_export_id' => null,
    ]);
});

test('StudioBridge rechaza texto y destinos nuevos al actualizar una publicación desde el diseño', function () {
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['text' => 'Original'])
        ->assertCreated()->json('post.id');

    $this->postJson("/studio/exports/{$this->export}/post", [
        'post_id' => $postId, 'text' => 'Otro texto', 'targets' => [],
    ])->assertUnprocessable()->assertJsonValidationErrors(['text', 'targets']);

    expect(Post::findOrFail($postId)->text)->toBe('Original');
});

test('StudioBridge informa si la publicación ya salió o está en vuelo y conserva su exportación', function (string $state) {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $replacement = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['targets' => [[
        'account_id' => $account->id, 'content_type' => ContentType::InstagramImage->value,
    ]]])->assertCreated()->json('post.id');
    $draft = Post::findOrFail($postId);
    $draft->targets()->sole()->forceFill(['status' => $state])->save();
    $draft->syncStatus();

    $export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$replacement->id],
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$export}/post", ['post_id' => $postId])
        ->assertUnprocessable()->assertJsonValidationErrors('post');

    expect(Post::ownedBy($user->id)->count())->toBe(1);
    expect($draft->fresh()->design_export_id)->toBe($this->export)
        ->and($draft->fresh()->media->pluck('id')->all())->toBe([$this->asset->id]);
})->with(['publicada', 'publicando', 'procesando']);

test('StudioBridge conserva los estados de cada destino cuando conviven programados y fallidos', function () {
    $user = $this->brand->user;
    $accounts = SocialAccount::factory()->count(2)->for($this->brand)->create();
    $replacement = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $targets = $accounts->map(fn (SocialAccount $account) => [
        'account_id' => $account->id, 'content_type' => ContentType::InstagramImage->value,
    ])->all();
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['targets' => $targets])
        ->assertCreated()->json('post.id');
    $post = app(PostService::class)->update($user, Post::findOrFail($postId), [
        'media' => [$this->asset->id], 'targets' => $targets, 'schedule' => true, 'scheduled_at' => now()->addDay(),
    ]);
    $failed = $post->targets->first();
    $scheduled = $post->targets->last();
    $failed->forceFill(['status' => 'fallida'])->save();

    $export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$replacement->id],
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$export}/post", ['post_id' => $postId])
        ->assertOk()->assertJsonPath('post.status', 'programada');

    expect($failed->fresh()->status->value)->toBe('fallida')
        ->and($scheduled->fresh()->status->value)->toBe('programada');
    expect($post->fresh()->media->pluck('id')->all())->toBe([$replacement->id]);
});

test('StudioBridge abre el diseño si el enlace de la publicación de origen ha caducado', function () {
    $postId = $this->postJson("/studio/exports/{$this->export}/post")->assertCreated()->json('post.id');
    Post::findOrFail($postId)->delete();

    $this->get(route('studio.designs.edit', ['design' => $this->design, 'post_id' => $postId]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Studio/Editor')->where('source_post_id', null)->where('source_post_unavailable', true));
});

test('StudioBridge preserva los originales si un destino ya se publicó y otro sigue programado', function () {
    $user = $this->brand->user;
    $accounts = SocialAccount::factory()->count(2)->for($this->brand)->create();
    $replacement = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $targets = $accounts->map(fn (SocialAccount $account) => [
        'account_id' => $account->id, 'content_type' => ContentType::InstagramImage->value,
        'media' => [['id' => $this->asset->id]],
    ])->all();
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['targets' => $targets])
        ->assertCreated()->json('post.id');
    $post = Post::findOrFail($postId);
    [$published, $scheduled] = $post->targets()->orderBy('id')->get();
    $published->forceFill(['status' => 'publicada'])->save();
    $scheduled->forceFill(['status' => 'programada'])->save();
    $post->syncStatus();

    $export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$replacement->id],
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$export}/post", ['post_id' => $postId])
        ->assertUnprocessable()->assertJsonValidationErrors('post');

    expect($post->fresh()->design_export_id)->toBe($this->export);
    foreach ([$published, $scheduled] as $target) {
        $this->assertDatabaseHas('post_target_media', ['post_target_id' => $target->id, 'media_asset_id' => $this->asset->id]);
    }
});

test('StudioBridge permite actualizar los adjuntos de una publicación vencida que sigue en cola', function () {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $replacement = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $target = ['account_id' => $account->id, 'content_type' => ContentType::InstagramImage->value];
    $postId = $this->postJson("/studio/exports/{$this->export}/post", ['targets' => [$target]])
        ->assertCreated()->json('post.id');
    $post = app(PostService::class)->update($user, Post::findOrFail($postId), [
        'media' => [$this->asset->id], 'targets' => [$target], 'schedule' => true, 'scheduled_at' => now()->addDay(),
    ]);
    $due = now()->subMinutes(5)->startOfMinute();
    $post->forceFill(['scheduled_at' => $due])->save();

    $export = $this->postJson("/studio/designs/{$this->design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$replacement->id],
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$export}/post", ['post_id' => $postId])
        ->assertOk()->assertJsonPath('post.status', 'programada');

    expect($post->fresh()->scheduled_at->equalTo($due))->toBeTrue()
        ->and($post->fresh()->media->pluck('id')->all())->toBe([$replacement->id]);
});

test('StudioBridge conserva el texto alternativo de cada página al reducir y reordenar una exportación', function () {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $original = MediaAsset::factory()->for($user)->count(3)->create(['mime' => 'image/jpeg']);
    $replacement = MediaAsset::factory()->for($user)->count(2)->create(['mime' => 'image/jpeg']);
    $document = StudioDocument::blank();
    foreach (['page_2', 'page_3'] as $id) {
        $document['pages'][] = ['id' => $id] + $document['pages'][0];
    }

    $design = $this->postJson("/brands/{$this->brand->id}/studio/designs", ['name' => 'Carrusel', 'document' => $document])
        ->assertCreated()->json('design.id');
    $before = $this->postJson("/studio/designs/{$design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1', 'page_2', 'page_3'], 'media_ids' => $original->modelKeys(),
    ])->assertCreated()->json('export.id');
    $postId = $this->postJson("/studio/exports/{$before}/post", ['targets' => [[
        'account_id' => $account->id, 'content_type' => ContentType::InstagramCarousel->value,
        'media' => $original->map(fn (MediaAsset $asset, int $index) => ['id' => $asset->id, 'alt' => 'Página '.($index + 1)])->all(),
    ]]])->assertCreated()->json('post.id');

    $after = $this->postJson("/studio/designs/{$design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_3', 'page_1'], 'media_ids' => $replacement->modelKeys(),
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$after}/post", ['post_id' => $postId])->assertOk();

    $items = Post::findOrFail($postId)->targets()->sole()->mediaItems()->get();
    expect($items->pluck('media_asset_id')->all())->toBe($replacement->modelKeys())
        ->and($items->pluck('alt')->all())->toBe(['Página 3', 'Página 1']);
});

test('StudioBridge mantiene los adjuntos independientes intercalados en ambas listas al ampliar la exportación', function () {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $files = MediaAsset::factory()->for($user)->count(7)->create(['mime' => 'image/jpeg'])->modelKeys();
    [$first, $second, $middle, $tail, $newFirst, $newSecond, $newThird] = $files;
    $document = StudioDocument::blank();
    foreach (['page_2', 'page_3'] as $page) {
        $document['pages'][] = ['id' => $page] + $document['pages'][0];
    }
    $design = $this->postJson("/brands/{$this->brand->id}/studio/designs", ['name' => 'Carrusel mixto', 'document' => $document])
        ->assertCreated()->json('design.id');
    $export = fn (array $pages, array $media) => $this->postJson("/studio/designs/{$design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => $pages, 'media_ids' => $media,
    ])->assertCreated()->json('export.id');
    $before = $export(['page_1', 'page_2'], [$first, $second]);
    $target = ['account_id' => $account->id, 'content_type' => ContentType::InstagramCarousel->value];
    $postId = $this->postJson("/studio/exports/{$before}/post", ['targets' => [[...$target, 'media' => [$first, $second]]]])
        ->assertCreated()->json('post.id');
    $ordered = [$first, $middle, $second, $tail];
    app(PostService::class)->update($user, Post::findOrFail($postId), [
        'media' => $ordered,
        'targets' => [[...$target, 'media' => array_map(fn (int $id) => ['id' => $id, 'alt' => "Adjunto {$id}"], $ordered)]],
    ]);

    $after = $export(['page_1', 'page_2', 'page_3'], [$newFirst, $newSecond, $newThird]);
    $this->postJson("/studio/exports/{$after}/post", ['post_id' => $postId])->assertOk();

    $post = Post::findOrFail($postId);
    $items = $post->targets()->sole()->mediaItems()->get();
    $expected = [$newFirst, $middle, $newSecond, $newThird, $tail];
    expect($post->media->modelKeys())->toBe($expected)
        ->and($items->pluck('media_asset_id')->all())->toBe($expected)
        ->and($items->pluck('alt')->all())->toBe(["Adjunto {$first}", "Adjunto {$middle}", "Adjunto {$second}", null, "Adjunto {$tail}"])
        ->and($items->pluck('design_export_id')->all())->toBe([$after, null, $after, $after, null]);
});

test('StudioBridge conserva la selección parcial al reordenar las páginas del diseño', function () {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $files = MediaAsset::factory()->for($user)->count(4)->create(['mime' => 'image/jpeg'])->modelKeys();
    [$first, $second, $newSecond, $newFirst] = $files;
    $document = StudioDocument::blank();
    $document['pages'][] = ['id' => 'page_2'] + $document['pages'][0];
    $design = $this->postJson("/brands/{$this->brand->id}/studio/designs", ['name' => 'Selección parcial', 'document' => $document])
        ->assertCreated()->json('design.id');
    $export = fn (array $pages, array $media) => $this->postJson("/studio/designs/{$design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => $pages, 'media_ids' => $media,
    ])->assertCreated()->json('export.id');
    $before = $export(['page_1', 'page_2'], [$first, $second]);
    $postId = $this->postJson("/studio/exports/{$before}/post", ['targets' => [[
        'account_id' => $account->id,
        'content_type' => ContentType::InstagramImage->value,
        'media' => [['id' => $second, 'alt' => 'Solo la segunda página']],
    ]]])->assertCreated()->json('post.id');

    $after = $export(['page_2', 'page_1'], [$newSecond, $newFirst]);
    $this->postJson("/studio/exports/{$after}/post", ['post_id' => $postId])->assertOk();

    $post = Post::findOrFail($postId);
    $item = $post->targets()->sole()->mediaItems()->sole();
    expect($post->media->modelKeys())->toBe([$newSecond, $newFirst])
        ->and($item->media_asset_id)->toBe($newSecond)
        ->and($item->alt)->toBe('Solo la segunda página')
        ->and($item->design_export_id)->toBe($after);
});

test('StudioBridge no reutiliza el texto alternativo de una página sustituida por otra', function () {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $replacement = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg']);
    $document = StudioDocument::blank();
    $document['pages'][] = ['id' => 'page_2'] + $document['pages'][0];
    $design = $this->postJson("/brands/{$this->brand->id}/studio/designs", ['name' => 'Dos páginas', 'document' => $document])
        ->assertCreated()->json('design.id');
    $before = $this->postJson("/studio/designs/{$design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_1'], 'media_ids' => [$this->asset->id],
    ])->assertCreated()->json('export.id');
    $postId = $this->postJson("/studio/exports/{$before}/post", ['targets' => [[
        'account_id' => $account->id, 'content_type' => ContentType::InstagramImage->value,
        'media' => [['id' => $this->asset->id, 'alt' => 'Descripción de la primera página']],
    ]]])->assertCreated()->json('post.id');

    $after = $this->postJson("/studio/designs/{$design}/exports", [
        'revision' => 1, 'format' => 'jpeg', 'pages' => ['page_2'], 'media_ids' => [$replacement->id],
    ])->assertCreated()->json('export.id');
    $this->postJson("/studio/exports/{$after}/post", ['post_id' => $postId])->assertOk();

    $item = Post::findOrFail($postId)->targets()->sole()->mediaItems()->sole();
    expect($item->media_asset_id)->toBe($replacement->id)->and($item->alt)->toBeNull();
});

test('StudioBridge conserva la portada al rechazar un vídeo combinado que cambia de páginas', function () {
    $user = $this->brand->user;
    $account = SocialAccount::factory()->for($this->brand)->create();
    $videos = [
        MediaAsset::factory()->for($user)->video(12, 1080, 1920, 30)->create(),
        MediaAsset::factory()->for($user)->video(12, 1080, 1920, 30)->create(),
    ];
    foreach ($videos as $video) {
        StudioAsset::create(['user_id' => $user->id, 'brand_id' => $this->brand->id, 'media_asset_id' => $video->id]);
    }
    $cover = MediaAsset::factory()->for($user)->create(['mime' => 'image/jpeg', 'width' => 1080, 'height' => 1920]);
    $document = StudioDocument::blank();
    $document['pages'][] = ['id' => 'page_2'] + $document['pages'][0];
    $design = $this->postJson("/brands/{$this->brand->id}/studio/designs", ['name' => 'Vídeo combinado', 'document' => $document])
        ->assertCreated()->json('design.id');
    $export = fn (MediaAsset $video, array $pages) => DesignExport::create([
        'user_id' => $user->id, 'brand_id' => $this->brand->id, 'design_id' => $design,
        'revision' => 1, 'format' => 'mp4', 'pages' => $pages, 'options' => [], 'status' => 'ready', 'media_ids' => [$video->id],
    ]);
    $before = $export($videos[0], ['page_1', 'page_2']);
    $postId = $this->postJson("/studio/exports/{$before->id}/post", ['targets' => [[
        'account_id' => $account->id, 'content_type' => ContentType::InstagramReel->value,
        'media' => [['id' => $videos[0]->id, 'cover_id' => $cover->id]],
    ]]])->assertCreated()->json('post.id');
    $after = $export($videos[1], ['page_2', 'page_1']);

    $this->postJson("/studio/exports/{$after->id}/post", ['post_id' => $postId])
        ->assertUnprocessable()->assertJsonValidationErrors('post');
    $post = Post::findOrFail($postId);
    $item = $post->targets()->sole()->mediaItems()->sole();
    expect($post->design_export_id)->toBe($before->id)
        ->and($item->media_asset_id)->toBe($videos[0]->id)
        ->and($item->cover_id)->toBe($cover->id);
});
