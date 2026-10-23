<?php

use App\Analytics\Period;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\InboxComment;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\PublishFailed;
use App\Services\AnalyticsService;
use Illuminate\Support\Carbon;

// Revision de la Fase 3 (T-25): el texto efectivo del destino en las superficies por destino (A4) y el texto propio vacio (A3/B1).

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'IG']);
    $this->post = Post::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'text' => 'Texto de la plantilla']);
});

function trTarget(?string $own, array $over = []): PostTarget
{
    return PostTarget::factory()->create($over + [
        'post_id' => test()->post->id, 'user_id' => test()->user->id, 'social_account_id' => test()->ig->id, 'network' => Network::Instagram,
        'status' => PostStatus::Published, 'external_id' => 'm1', 'external_url' => 'https://example.test/m1', 'published_at' => '2026-10-03 10:00:00', 'text' => $own,
    ]);
}

test('TargetTextReview: el aviso de fallo cita el texto efectivo del destino (propio o, si esta vinculado, el de la plantilla)', function () {
    $own = trTarget('Propio de Instagram');
    $linked = trTarget(null, ['external_id' => 'm2', 'content_type' => 'instagram_reel']);

    expect((new PublishFailed($own, 'motivo'))->excerpt)->toBe('Propio de Instagram')
        ->and((new PublishFailed($linked, 'motivo'))->excerpt)->toBe('Texto de la plantilla');
});

test('TargetTextReview: Analytics muestra el texto efectivo del destino en sus publicaciones', function () {
    $t = trTarget('Propio de Instagram');
    PostMetricSnapshot::withoutUserScope()->create(['user_id' => $this->user->id, 'post_target_id' => $t->id, 'social_account_id' => $this->ig->id,
        'network' => 'instagram', 'date' => '2026-10-04', 'metrics' => ['views' => 100, 'interactions' => 10]]);

    $d = app(AnalyticsService::class)->network($this->user, $this->brand, Network::Instagram, Period::make('2026-10-01', '2026-10-10'));

    expect($d['posts'][0]['text'])->toBe('Propio de Instagram');
});

test('TargetTextReview: el buzon muestra el texto efectivo de la publicacion comentada', function () {
    $t = trTarget('Propio de Instagram');
    $comment = new InboxComment(['external_id' => 'c1', 'author_name' => 'ana', 'text' => 'Que bonito!', 'commented_at' => now()]);
    $comment->forceFill(['user_id' => $this->user->id, 'social_account_id' => $this->ig->id, 'post_target_id' => $t->id, 'brand_id' => $this->brand->id, 'network' => 'instagram'])->save();

    $this->actingAs($this->user)->get(route('inbox.index'))->assertOk()
        ->assertInertia(fn ($p) => $p->where('comments.data.0.post_text', 'Propio de Instagram'));
});
