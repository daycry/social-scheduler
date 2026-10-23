<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

// C-03 (T-19): `progress.reconcile.text` guarda el texto efectivo ANTERIOR del destino (nunca el nuevo), en los tres casos.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->service = app(PostService::class);
});

function tfData(string $template, ?string $own): array
{
    return ['text' => $template, 'scheduled_at' => now()->addDay(), 'schedule' => true,
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text', 'text' => $own]]];
}

function tfPost(string $template, ?string $own): Post
{
    $post = test()->service->create(test()->user, test()->brand, tfData($template, $own));
    // Intento ambiguo previo: sin esto no habria nada que reconciliar.
    $post->targets->first()->forceFill(['attempts' => 1, 'error_code' => 'transitorio'])->save();

    return $post->fresh();
}

function tfReconcileText(Post $post): ?string
{
    return $post->targets()->first()->progress['reconcile']['text'] ?? null;
}

test('TargetTextFreshAttempt: destino vinculado y plantilla editada guarda la plantilla vieja', function () {
    $post = tfPost('Plantilla vieja', null);

    $this->service->update($this->user, $post, tfData('Plantilla NUEVA', null));

    expect(tfReconcileText($post))->toBe('Plantilla vieja');
});

test('TargetTextFreshAttempt: destino con texto propio editado guarda el propio viejo, no el nuevo ni la plantilla', function () {
    $post = tfPost('Plantilla', 'Propio viejo');

    $this->service->update($this->user, $post, tfData('Plantilla', 'Propio NUEVO'));

    expect(tfReconcileText($post))->toBe('Propio viejo');
});

test('TargetTextFreshAttempt: vincular y desvincular guardan lo que se publicaba antes', function () {
    // Desvinculado -> vinculado: antes se publicaba el propio.
    $post = tfPost('Plantilla', 'Propio viejo');
    $this->service->update($this->user, $post, tfData('Plantilla', null));
    expect(tfReconcileText($post))->toBe('Propio viejo');

    // Vinculado -> desvinculado: antes se publicaba la plantilla (y se edita tambien la plantilla en la misma peticion).
    $post2 = tfPost('Plantilla vieja', null);
    $this->service->update($this->user, $post2, tfData('Plantilla NUEVA', 'Propio'));
    expect(tfReconcileText($post2))->toBe('Plantilla vieja');
});

test('TargetTextFreshAttempt: plantilla editada con un destino desvinculado NO toca su marcador', function () {
    $post = tfPost('Plantilla', 'Propio');

    $this->service->update($this->user, $post, tfData('Plantilla NUEVA', 'Propio'));

    expect($post->targets()->first()->attempts)->toBe(1)->and(tfReconcileText($post))->toBeNull();
});

test('TargetTextFreshAttempt: dos ediciones seguidas conservan el texto del intento original', function () {
    $post = tfPost('Plantilla', 'Propio original');

    $this->service->update($this->user, $post, tfData('Plantilla', 'Propio 2'));
    $this->service->update($this->user, $post, tfData('Plantilla', 'Propio 3'));

    expect(tfReconcileText($post))->toBe('Propio original');
});
