<?php

use App\Connectors\Network;
use App\Jobs\DispatchDuePosts;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PushSubscription;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\ManualPublishReminder;
use App\Services\ManualPublishService;
use App\Services\PostService;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

// T-32: recordatorio ANTES de la hora de una publicacion manual, una sola vez, en la app y por correo si notify_by_mail, siempre por la cola.

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-09 12:00:00');
    config(['social.series.manual_reminder_minutes' => 60]);
    $this->user = User::factory()->create(['notify_by_mail' => false]);
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
});

function reminderPost(string $at, array $over = []): Post
{
    return app(PostService::class)->create(test()->user, test()->brand, array_replace([
        'text' => 'Texto', 'schedule' => true, 'scheduled_at' => $at, 'media' => [],
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text', 'manual' => true]],
    ], $over));
}

test('el recordatorio sale solo dentro de la antelacion, una sola vez, y es solo in-app sin notify_by_mail', function () {
    Notification::fake();
    reminderPost('2026-11-09T12:45'); // dentro de 45 min
    reminderPost('2026-11-09T16:00'); // aun lejos

    expect(app(ManualPublishService::class)->remindUpcoming())->toBe(1)
        ->and(app(ManualPublishService::class)->remindUpcoming())->toBe(0);

    Notification::assertSentToTimes($this->user, ManualPublishReminder::class, 1);
    Notification::assertSentTo($this->user, ManualPublishReminder::class, fn (ManualPublishReminder $n) => $n->via($this->user) === ['database']);
});

test('con notify_by_mail el recordatorio tambien va por correo y se encola (nunca sincrono en el despachador)', function () {
    Queue::fake();
    $this->user->forceFill(['notify_by_mail' => true])->save();
    $post = reminderPost('2026-11-09T12:30');

    expect(app(ManualPublishService::class)->remindUpcoming())->toBe(1);
    $n = new ManualPublishReminder($post->id, $this->brand->id, 'Marca', 'Recuerda', 'x');
    expect($n)->toBeInstanceOf(ShouldQueue::class)
        ->and($n->via($this->user->fresh()))->toBe(['database', 'mail'])
        ->and($n->toMail($this->user)->actionUrl)->toContain('/posts/'.$post->id.'/manual')
        ->and($n->toArray($this->user)['type'])->toBe('manual_reminder');
    Queue::assertPushed(SendQueuedNotifications::class, 2); // un trabajo por canal (app y correo), encolados y no enviados dentro de la pasada
    expect($this->user->notifications()->count())->toBe(0);
});

test('reprogramar rearma el recordatorio y el aviso de la hora sigue su curso', function () {
    Queue::fake();
    $post = reminderPost('2026-11-09T12:30');
    app(ManualPublishService::class)->remindUpcoming();
    expect($post->targets()->first()->manual_reminded_at)->not->toBeNull();

    app(PostService::class)->reschedule($this->user, $post->fresh(), '2026-11-09T12:50');
    expect($post->targets()->first()->fresh()->manual_reminded_at)->toBeNull()
        ->and(app(ManualPublishService::class)->remindUpcoming())->toBe(1);

    Carbon::setTestNow('2026-11-09 13:01:00');
    app(DispatchDuePosts::class)();
    expect($this->user->notifications()->where('data->type', 'manual_due')->count())->toBe(1);
});

test('un post pendiente de aprobacion no recuerda', function () {
    Queue::fake();
    $post = reminderPost('2026-11-09T12:30', ['requires_approval' => true]);

    expect($post->approval_status)->toBe(Post::APPROVAL_PENDING)->and(app(ManualPublishService::class)->remindUpcoming())->toBe(0);
});

test('el claim atomico repite la ventana, el aviso de la hora y el estado en el propio UPDATE', function () {
    Notification::fake();
    reminderPost('2026-11-09T12:30');
    $updates = [];
    DB::listen(function ($query) use (&$updates) {
        if (str_starts_with(strtolower($query->sql), 'update "post_targets"') || str_starts_with(strtolower($query->sql), 'update `post_targets`')) {
            $updates[] = strtolower($query->sql);
        }
    });

    expect(app(ManualPublishService::class)->remindUpcoming())->toBe(1);

    $claim = collect($updates)->first(fn ($sql) => str_contains($sql, 'manual_reminded_at'));
    expect($claim)->toContain('manual_notified_at')->toContain('scheduled_at')->toContain('approval_status')->toContain('status');
});

test('los destinos manuales programados tienen indice propio', function () {
    expect(Schema::hasIndex('post_targets', 'post_targets_manual_status_idx'))->toBeTrue();
});

test('con el recordatorio desactivado no hay aviso por ningun canal y la hora no se gasta', function () {
    Notification::fake();
    $this->user->forceFill(['notify_manual_reminder' => false, 'notify_by_mail' => true])->save();
    $post = reminderPost('2026-11-09T12:30');

    expect(app(ManualPublishService::class)->remindUpcoming())->toBe(0)
        ->and($post->targets()->first()->manual_reminded_at)->toBeNull();
    Notification::assertNothingSent();

    // Reactivarlo antes de la hora aun lo recibe, una sola vez.
    $this->user->forceFill(['notify_manual_reminder' => true])->save();
    expect(app(ManualPublishService::class)->remindUpcoming())->toBe(1)->and(app(ManualPublishService::class)->remindUpcoming())->toBe(0);
    Notification::assertSentToTimes($this->user, ManualPublishReminder::class, 1);
});

test('un aviso ya encolado se descarta si el usuario apaga el recordatorio antes de que lo procese el worker', function (bool $enabled, int $expected) {
    Queue::fake();
    $this->user->forceFill(['notify_by_mail' => true])->save();
    reminderPost('2026-11-09T12:30');
    expect(app(ManualPublishService::class)->remindUpcoming())->toBe(1);
    $queued = Queue::pushed(SendQueuedNotifications::class);
    expect($queued)->toHaveCount(2); // app y correo, encolados con la preferencia aun encendida

    $this->user->forceFill(['notify_manual_reminder' => $enabled])->save();
    // El worker deserializa el job: el notificable se relee de la base y `shouldSend()` ve la preferencia de ese momento.
    $queued->each(fn ($job) => app()->call([unserialize(serialize($job)), 'handle']));

    expect($this->user->notifications()->count())->toBe($expected);
})->with([
    'sigue encendido' => [true, 1],
    'apagado mientras esperaba' => [false, 0],
]);

test('el aviso de la hora se sigue enviando aunque el recordatorio previo este desactivado', function () {
    Queue::fake();
    $this->user->forceFill(['notify_manual_reminder' => false])->save();
    reminderPost('2026-11-09T12:30');
    Carbon::setTestNow('2026-11-09 12:31:00');

    expect(app(ManualPublishService::class)->notifyDue())->toBe(1)
        ->and($this->user->notifications()->where('data->type', 'manual_due')->count())->toBe(1);
});

test('el recordatorio llega por push a los dispositivos del usuario con un PushSender falso y no se repite', function () {
    // Cola `sync` (la de las pruebas): el aviso, la escucha de push y su job se procesan dentro de la prueba.
    pushTestKeys(); // configura VAPID de prueba (tests/Pest.php)
    $subscription = PushSubscription::forceCreate([
        'user_id' => $this->user->id, 'endpoint_hash' => hash('sha256', 'https://push.example.test/recordatorio'),
        'endpoint' => 'https://push.example.test/recordatorio', 'keys' => ['p256dh' => 'k', 'auth' => 'a'], 'device_name' => 'Movil', 'last_seen_at' => now(),
    ]);
    $sender = new class implements PushSender
    {
        /** @var list<int> */
        public array $sent = [];

        public function send(PushSubscription $subscription): PushResult
        {
            $this->sent[] = $subscription->id;

            return PushResult::Delivered;
        }
    };
    app()->instance(PushSender::class, $sender);
    reminderPost('2026-11-09T12:30');

    expect(app(ManualPublishService::class)->remindUpcoming())->toBe(1)
        ->and($this->user->notifications()->where('data->type', 'manual_reminder')->count())->toBe(1)
        ->and($sender->sent)->toBe([$subscription->id]);

    expect(app(ManualPublishService::class)->remindUpcoming())->toBe(0)->and($sender->sent)->toHaveCount(1);
});
