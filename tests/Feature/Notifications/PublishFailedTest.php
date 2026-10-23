<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\PublishFailed;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePublisher;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['publishing.backoff_seconds' => [60, 300, 900], 'publishing.max_attempts' => 3, 'publishing.quota_policy' => 'reschedule']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Mi Marca', 'timezone' => 'Europe/Madrid']);
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'aipocalipsis']);
    $this->publisher = new FakePublisher;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Instagram, $this->publisher));
});

function failingTarget(array $postAttrs = []): PostTarget
{
    $post = Post::factory()->create(array_merge(['brand_id' => test()->brand->id, 'user_id' => test()->user->id, 'text' => 'Oferta de otono', 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled], $postAttrs));

    return PostTarget::factory()->create(['post_id' => $post->id, 'user_id' => test()->user->id, 'social_account_id' => test()->account->id]);
}

function runTick(int $seconds = 0): void
{
    Carbon::setTestNow(now()->addSeconds($seconds));
    app(DispatchDuePosts::class)();
}

test('PublishFailed: un fallo permanente avisa una vez por base de datos y email con el motivo legible (CA-07)', function () {
    Notification::fake();
    $this->publisher->script = [new PermanentPublishException('Instagram no admite el formato del video (usa MP4/MOV con H.264 y audio AAC).', 'video_no_admitido')];
    $target = failingTarget();

    runTick();
    runTick(3600); // sin reintentos: no hay segundo aviso

    Notification::assertSentToTimes($this->user, PublishFailed::class, 1);
    Notification::assertSentTo($this->user, PublishFailed::class, function (PublishFailed $n, array $channels) use ($target) {
        $mail = $n->toMail($this->user);
        $data = $n->toArray($this->user);

        return $channels === ['database', 'mail']
            && $data['message'] === 'Instagram no admite el formato del video (usa MP4/MOV con H.264 y audio AAC).'
            && $data['post_id'] === $target->post_id && $data['network'] === 'instagram' && $data['error_code'] === 'video_no_admitido'
            && $data['brand_name'] === 'Mi Marca' && $data['scheduled_at'] === '01/11/2026 12:59'
            && str_contains($mail->subject, 'No se pudo publicar en Instagram')
            && str_contains(implode(' ', $mail->introLines), 'Motivo: Instagram no admite el formato del video')
            && str_contains(implode(' ', $mail->introLines), 'aipocalipsis')
            && $mail->actionUrl === route('posts.edit', $target->post_id)
            && ! str_contains(json_encode($data), $this->account->access_token);
    });
});

test('PublishFailed: sin fake, el aviso queda en la base de datos del usuario y se envia el email', function () {
    $this->publisher->script = [new PermanentPublishException('Contenido rechazado por Instagram', 'rechazada')];
    failingTarget();

    runTick();

    $notifications = DatabaseNotification::where('notifiable_id', $this->user->id)->get();
    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]->data['type'])->toBe('publish_failed')
        ->and($notifications[0]->data['message'])->toBe('Contenido rechazado por Instagram')
        ->and($notifications[0]->read_at)->toBeNull();
    expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

test('PublishFailed: un error transitorio no avisa mientras reintenta, y avisa una vez al agotar los intentos', function () {
    Notification::fake();
    $this->publisher->script = array_fill(0, 5, new TransientPublishException('Meta no responde', 'timeout'));
    $target = failingTarget();

    runTick();
    runTick(61);
    Notification::assertNothingSent(); // dos intentos fallidos: sigue reintentando

    runTick(301);
    expect($target->fresh()->status)->toBe(PostStatus::Failed);
    Notification::assertSentToTimes($this->user, PublishFailed::class, 1);
    Notification::assertSentTo($this->user, PublishFailed::class, fn (PublishFailed $n) => str_contains($n->toArray($this->user)['message'], 'tras 3 intentos'));
});

test('PublishFailed: las vencidas y las de cuenta eliminada tambien avisan', function () {
    Notification::fake();
    failingTarget(['scheduled_at' => now()->subHours(30)]);
    $orphan = failingTarget();
    SocialAccount::withoutUserScope()->whereKey($this->account->id)->delete();

    runTick();

    Notification::assertSentToTimes($this->user, PublishFailed::class, 2);
    expect($orphan->fresh()->error_code)->toBe('cuenta_no_disponible');
});

test('PublishFailed: la cuota agotada se aplaza por defecto (reprogramar) sin avisar', function () {
    Notification::fake();
    $this->publisher->script = [new QuotaExceededException('Instagram permite 25 publicaciones cada 24 h y ya se han usado 25.', now()->addHours(3))];
    $target = failingTarget();

    runTick();

    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Scheduled)->and($t->error_code)->toBe('cuota')
        ->and($t->next_attempt_at->toDateTimeString())->toBe(now()->addHours(3)->toDateTimeString());
    Notification::assertNothingSent();

    runTick(3 * 3600 + 1);
    expect($target->fresh()->status)->toBe(PostStatus::Published);
});

test('PublishFailed: con la politica fail la cuota agotada falla con motivo cuota y avisa', function () {
    Notification::fake();
    config(['publishing.quota_policy' => 'fail']);
    $this->publisher->script = [new QuotaExceededException('Limite diario de Instagram alcanzado.', now()->addHours(3))];
    $target = failingTarget();

    runTick();

    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Failed)->and($t->error_code)->toBe('cuota')->and($t->error_message)->toContain('Cuota de publicacion agotada')->toContain('Limite diario');
    Notification::assertSentToTimes($this->user, PublishFailed::class, 1);
});

test('PublishFailed: con reschedule, si la cuota no se libera dentro del margen de retraso, falla con motivo cuota', function () {
    Notification::fake();
    $this->publisher->script = [new QuotaExceededException('Cupo agotado.', now()->addHours(40))];
    $target = failingTarget(); // programada hace 1 min; margen 24 h

    runTick();

    expect($target->fresh()->status)->toBe(PostStatus::Failed)->and($target->fresh()->error_message)->toContain('no se libera a tiempo');
    Notification::assertSentToTimes($this->user, PublishFailed::class, 1);
});

test('PublishFailed: la pantalla de avisos lista los del usuario, cuenta los no leidos y permite marcarlos', function () {
    $this->publisher->script = [new PermanentPublishException('Motivo X', 'rechazada')];
    $target = failingTarget();
    runTick();

    $this->actingAs($this->user)->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Notifications/Index')->has('notifications', 1)
            ->where('notifications.0.message', 'Motivo X')->where('notifications.0.post_id', $target->post_id)->where('notifications.0.read', false)
            ->where('notifications.0.network_label', 'Instagram')->where('notifications.0.type', 'publish_failed'));

    $this->actingAs($this->user)->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->where('unreadNotifications', 1));

    $id = $this->user->notifications()->first()->id;
    $this->actingAs($this->user)->post(route('notifications.read', $id))->assertRedirect();
    expect($this->user->fresh()->unreadNotifications()->count())->toBe(0);
});

test('PublishFailed: aislamiento, otro usuario no ve ni marca los avisos ajenos', function () {
    $this->publisher->script = [new PermanentPublishException('Privado', 'rechazada')];
    failingTarget();
    runTick();
    $id = $this->user->notifications()->first()->id;
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('notifications.index'))->assertInertia(fn (Assert $p) => $p->has('notifications', 0));
    $this->actingAs($other)->post(route('notifications.read', $id))->assertNotFound();
    $this->actingAs($other)->post(route('notifications.read-all'))->assertRedirect();

    expect($this->user->fresh()->unreadNotifications()->count())->toBe(1);
    auth()->logout();
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});
