<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// Panel de campaña (C-03, CA-11): props con KPIs, desglose por red, serie diaria y posts.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Alfa']);
    $this->campaign = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'name' => 'Otoño']);
});

it('renderiza Campaigns/Show con los KPIs agregados y «sin datos» como null', function () {
    $ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'campaign_id' => $this->campaign->id, 'text' => 'Hola']);
    $t = PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $ig->id, 'network' => Network::Instagram,
        'status' => PostStatus::Published, 'external_id' => 'x', 'published_at' => '2026-10-03 10:00:00']);
    PostMetricSnapshot::withoutUserScope()->create(['user_id' => $this->user->id, 'post_target_id' => $t->id, 'social_account_id' => $ig->id,
        'network' => 'instagram', 'date' => '2026-10-04', 'metrics' => [Metric::VIEWS => 100, Metric::INTERACTIONS => null]]);

    $this->actingAs($this->user)->get(route('campaigns.show', $this->campaign->id))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Campaigns/Show')
            ->where('campaign.name', 'Otoño')->where('campaign.id', $this->campaign->id)->where('brand.name', 'Alfa')
            ->where('summary.kpis.posts', 1)->where('summary.kpis.views', 100)->where('summary.kpis.interactions', null)
            ->where('summary.by_network.0.network_label', 'Instagram')
            ->has('summary.daily', 1)->has('summary.posts', 1)->where('summary.frozen_days', 30));
});

it('una campaña sin posts muestra los KPIs vacíos', function () {
    $this->actingAs($this->user)->get(route('campaigns.show', $this->campaign->id))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('summary.kpis.posts', 0)->where('summary.kpis.views', null)->has('summary.posts', 0));
});

it('la campaña de otro usuario responde 404 igual que una inexistente', function () {
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('campaigns.show', $this->campaign->id))->assertNotFound();
    $this->actingAs($this->user)->get(route('campaigns.show', 999999))->assertNotFound();
});

it('el listado enlaza al panel de cada campaña', function () {
    $this->actingAs($this->user)->get(route('campaigns.index', ['brand_id' => $this->brand->id]))
        ->assertInertia(fn (Assert $p) => $p->where('campaigns.0.show_url', route('campaigns.show', $this->campaign->id, false)));
});
