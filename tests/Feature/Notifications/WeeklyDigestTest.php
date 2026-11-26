<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\SendUserWeeklyDigest;
use App\Jobs\SendWeeklyDigest;
use App\Mail\WeeklyDigest as WeeklyDigestMail;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\PushSubscription;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\WeeklyDigestReady;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;
use App\Services\ReportService;
use App\Services\WeeklyDigest;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

// T-86: resumen semanal. «Ahora» es el lunes 30-11-2026 09:00 UTC: la semana resumida es 23-29 nov y la previa 16-22 nov.

beforeEach(function () {
    Http::preventStrayRequests();
    Mail::fake();
    Carbon::setTestNow('2026-11-30 09:00:00');
    config(['social.ai.enabled' => false, 'social.ai.api_key' => '', 'social.analytics.all_brands_cache_minutes' => 0]);
    $this->user = User::factory()->create(['notify_weekly_digest' => true, 'notify_by_mail' => true]);
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Alfa', 'timezone' => 'UTC']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
});

function digestRun(): void
{
    (new SendWeeklyDigest)->handle(app(WeeklyDigest::class));
}

/** Un snapshot diario de la cuenta por cada dia del rango. */
function digestDays(SocialAccount $account, string $from, string $to, array $metrics): void
{
    for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
        AccountSnapshot::withoutUserScope()->create([
            'user_id' => $account->user_id, 'brand_id' => $account->brand_id, 'social_account_id' => $account->id,
            'network' => $account->network->value, 'date' => $day->format('Y-m-d'), 'metrics' => array_merge(Metric::blankAccount(), $metrics),
            'collected_at' => now(), 'settled_at' => now(),
        ]);
    }
}

/** Dos semanas con datos: 10 visualizaciones al dia la previa y 15 la ultima (+50 %), seguidores de 100 a 120 (+20 %). */
function digestSeed(SocialAccount $account): void
{
    digestDays($account, '2026-11-16', '2026-11-22', [Metric::FOLLOWERS => 100, Metric::VIEWS => 10]);
    digestDays($account, '2026-11-23', '2026-11-29', [Metric::FOLLOWERS => 120, Metric::VIEWS => 15]);
}

function digestPost(User $user, SocialAccount $account, string $text, string $publishedAt, int $interactions): PostTarget
{
    $post = Post::factory()->for($user)->create(['brand_id' => $account->brand_id, 'text' => $text]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $account->id, 'network' => $account->network,
        'status' => PostStatus::Published, 'external_id' => 'e'.$post->id, 'published_at' => $publishedAt,
    ]);
    PostMetricSnapshot::withoutUserScope()->create(['user_id' => $user->id, 'post_target_id' => $target->id, 'social_account_id' => $account->id,
        'network' => $account->network->value, 'date' => '2026-11-29', 'metrics' => [Metric::VIEWS => 200, Metric::INTERACTIONS => $interactions]]);

    return $target;
}

test('el resumen trae las metricas de la semana anterior con su comparacion, las publicadas y la mejor publicacion', function () {
    digestSeed($this->ig);
    digestPost($this->user, $this->ig, 'Una publicacion normal', '2026-11-24 10:00:00', 5);
    digestPost($this->user, $this->ig, 'La mejor de la semana', '2026-11-26 10:00:00', 90);
    digestPost($this->user, $this->ig, 'Fuera de la semana', '2026-11-20 10:00:00', 500);

    digestRun();

    Mail::assertSent(WeeklyDigestMail::class, function (WeeklyDigestMail $mail) {
        $brand = $mail->digest['brands'][0];
        $metrics = collect($brand['metrics'])->keyBy('label');

        expect($mail->digest['period']['from'])->toBe('2026-11-23')->and($mail->digest['period']['to'])->toBe('2026-11-29')
            ->and($brand['name'])->toBe('Alfa')->and($brand['has_data'])->toBeTrue()
            ->and($metrics['Visualizaciones']['value'])->toBe('105')->and($metrics['Visualizaciones']['change'])->toBe('+50,0 %')
            ->and($metrics['Seguidores']['value'])->toBe('120')->and($metrics['Seguidores']['change'])->toBe('+20,0 %')
            ->and($metrics['Alcance']['value'])->toBe(WeeklyDigest::NO_DATA)->and($metrics['Alcance']['change'])->toBe(WeeklyDigest::NO_DATA)
            ->and($brand['published'])->toBe(2)
            ->and($brand['best']['text'])->toBe('La mejor de la semana')->and($brand['best']['interactions'])->toBe('90')
            ->and($brand['best']['network'])->toBe('Instagram');
        $mail->assertSeeInHtml('Visualizaciones')->assertSeeInHtml('+50,0 %')->assertSeeInHtml('La mejor de la semana');
        $mail->assertSeeInText('Visualizaciones: 105 (+50,0 %)')->assertSeeInText('Publicaciones publicadas: 2');

        return $mail->hasTo($this->user->email);
    });
});

test('una marca sin datos sale como «sin datos» y nunca con cifras inventadas', function () {
    digestSeed($this->ig);
    Brand::factory()->for($this->user)->create(['name' => 'Vacia', 'timezone' => 'UTC']);

    digestRun();

    Mail::assertSent(WeeklyDigestMail::class, function (WeeklyDigestMail $mail) {
        $empty = collect($mail->digest['brands'])->firstWhere('name', 'Vacia');

        expect($empty['has_data'])->toBeFalse()->and($empty['published'])->toBe(0)->and($empty['best'])->toBeNull();
        $mail->assertSeeInHtml('sin datos esta semana')->assertSeeInText('sin datos esta semana');

        return true;
    });
});

test('el aviso en la app lleva titulo y enlace y llega por el canal database (el que usa el push)', function () {
    digestSeed($this->ig);

    digestRun();

    $notice = $this->user->notifications()->sole();
    expect($notice->type)->toBe(WeeklyDigestReady::class)
        ->and($notice->data['type'])->toBe('weekly_digest')
        ->and($notice->data['title'])->toBe('Tu resumen semanal: 23 – 29 de noviembre de 2026')
        ->and($notice->data['url'])->toBe('/dashboard')
        ->and($notice->data['week'])->toBe('2026-11-30')
        ->and($notice->data['message'])->toContain('Alfa');
    expect((new WeeklyDigestReady(['period' => ['label' => 'x'], 'brands' => []]))->via($this->user))->toBe(['database']);
    $this->actingAs($this->user)->get('/notifications')->assertOk()->assertSee('Tu resumen semanal');
});

test('el correo solo se envia con notify_by_mail; el aviso en la app siempre', function () {
    $this->user->forceFill(['notify_by_mail' => false])->save();

    digestRun();

    Mail::assertNothingSent();
    expect($this->user->notifications()->count())->toBe(1);
});

test('sin la preferencia del resumen no se envia nada por ningun canal', function () {
    $this->user->forceFill(['notify_weekly_digest' => false])->save();

    digestRun();

    Mail::assertNothingSent();
    expect($this->user->notifications()->count())->toBe(0)->and($this->user->fresh()->weekly_digest_sent_for)->toBeNull();
});

test('un usuario recibe como mucho un resumen por semana aunque el job corra dos veces o a la vez', function () {
    digestRun();
    digestRun();

    Mail::assertSentCount(1);
    expect($this->user->notifications()->count())->toBe(1)
        ->and($this->user->fresh()->weekly_digest_sent_for->format('Y-m-d'))->toBe('2026-11-30');

    // En paralelo: dos pasadas que ya habian leido al usuario sin marca de envio; solo una gana el claim atomico.
    $service = app(WeeklyDigest::class);
    $stale = User::query()->findOrFail($this->user->id);
    $stale->weekly_digest_sent_for = null;
    $other = User::factory()->create(['notify_weekly_digest' => true]);
    expect($service->claim($stale, '2026-11-30'))->toBeFalse()
        ->and($service->claim($other, '2026-11-30'))->toBeTrue()
        ->and($service->claim($other, '2026-11-30'))->toBeFalse();

    // La semana siguiente vuelve a tocar.
    Carbon::setTestNow('2026-12-07 09:00:00');
    digestRun();
    Mail::assertSentCount(2);
    expect($this->user->notifications()->count())->toBe(2);
});

test('fuera del lunes por la manana no se envia', function (string $now) {
    Carbon::setTestNow($now);

    digestRun();

    Mail::assertNothingSent();
    expect($this->user->notifications()->count())->toBe(0);
})->with([
    'martes' => '2026-12-01 09:00:00',
    'domingo' => '2026-11-29 23:00:00',
    'lunes antes de las 8' => '2026-11-30 07:59:00',
]);

test('la zona horaria es la de la marca principal: el lunes 08:00 local, no el UTC', function (string $zone, string $now, bool $sent) {
    $this->brand->forceFill(['timezone' => $zone])->save();
    Brand::factory()->for($this->user)->create(['name' => 'Segunda', 'timezone' => 'Asia/Tokyo']); // no manda: la principal es la mas antigua
    Carbon::setTestNow($now);

    digestRun();

    expect($this->user->notifications()->count())->toBe($sent ? 1 : 0);
})->with([
    'Auckland, ya lunes 09:00 local (domingo 20:00 UTC)' => ['Pacific/Auckland', '2026-11-29 20:00:00', true],
    'Auckland, lunes 01:00 local aun no' => ['Pacific/Auckland', '2026-11-29 12:00:00', false],
    'Los Angeles, sigue domingo a las 09:00 UTC' => ['America/Los_Angeles', '2026-11-30 09:00:00', false],
    'Los Angeles, lunes 08:00 local (16:00 UTC)' => ['America/Los_Angeles', '2026-11-30 16:00:00', true],
]);

test('un fallo al preparar el resumen devuelve la semana y la siguiente pasada lo reintenta', function () {
    $calls = 0;
    $this->mock(ReportService::class)->shouldReceive('topPosts')->twice()->andReturnUsing(function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('boom');
        }

        return [];
    });

    digestRun();

    expect($this->user->fresh()->weekly_digest_sent_for)->toBeNull()->and($this->user->notifications()->count())->toBe(0);
    Mail::assertNothingSent();

    digestRun();

    expect($this->user->notifications()->count())->toBe(1);
    Mail::assertSentCount(1);
});

test('un fallo del correo no devuelve la semana ni duplica el aviso de la app', function () {
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp caido'));

    digestRun();
    digestRun();

    expect($this->user->notifications()->count())->toBe(1);
});

describe('parrafo de IA', function () {
    beforeEach(function () {
        config(['social.ai.enabled' => true, 'social.ai.api_key' => 'sk-test', 'social.ai.daily_limit' => 20]);
        digestSeed($this->ig);
    });

    test('con IA configurada y activada por el usuario el parrafo va en el resumen y solo viajan cifras y nombres de marca', function () {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Semana al alza en visualizaciones.']]])]);
        $this->user->forceFill(['weekly_digest_ai' => true])->save();
        $this->brand->forceFill(['name' => 'Alfa contacto@ejemplo.test'])->save();

        digestRun();

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'Alfa') && str_contains($body, '105')
                && ! str_contains($body, 'contacto@ejemplo.test') && ! str_contains($body, $this->user->email) && ! str_contains($body, $this->user->name);
        });
        Mail::assertSent(WeeklyDigestMail::class, fn (WeeklyDigestMail $mail) => $mail->digest['ai'] === 'Semana al alza en visualizaciones.'
            && str_contains($mail->render(), 'Semana al alza en visualizaciones.'));
    });

    test('sin que el usuario lo active no se llama a la IA', function () {
        Http::fake();

        digestRun();

        Http::assertNothingSent();
        Mail::assertSent(WeeklyDigestMail::class, fn (WeeklyDigestMail $mail) => $mail->digest['ai'] === null);
    });

    test('con el asistente sin configurar el resumen sale igual y sin parrafo', function () {
        config(['social.ai.enabled' => false]);
        Http::fake();
        $this->user->forceFill(['weekly_digest_ai' => true])->save();

        digestRun();

        Http::assertNothingSent();
        Mail::assertSent(WeeklyDigestMail::class, fn (WeeklyDigestMail $mail) => $mail->digest['ai'] === null);
    });

    test('si la IA falla el resumen sale igual sin el parrafo', function () {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'x'], 500)]);
        $this->user->forceFill(['weekly_digest_ai' => true])->save();

        digestRun();

        Mail::assertSent(WeeklyDigestMail::class, fn (WeeklyDigestMail $mail) => $mail->digest['ai'] === null);
        expect($this->user->notifications()->count())->toBe(1);
    });

    test('una respuesta desmesurada de la IA se descarta entera', function () {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => str_repeat('a', WeeklyDigest::AI_MAX_CHARS + 1)]]])]);
        $this->user->forceFill(['weekly_digest_ai' => true])->save();

        digestRun();

        Mail::assertSent(WeeklyDigestMail::class, fn (WeeklyDigestMail $mail) => $mail->digest['ai'] === null);
    });
});

test('el correo trae la baja firmada de un clic: confirma sin efectos, el POST firmado desactiva y una firma manipulada se rechaza', function () {
    digestRun();
    $url = null;
    Mail::assertSent(WeeklyDigestMail::class, function (WeeklyDigestMail $mail) use (&$url) {
        $url = $mail->unsubscribeUrl;
        expect($mail->headers()->text['List-Unsubscribe'])->toBe('<'.$url.'>')
            ->and($mail->headers()->text['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
            ->and($mail->preferencesUrl)->toBe(route('profile.notifications'))
            ->and(str_starts_with($mail->dashboardUrl, 'http'))->toBeTrue();
        $mail->assertSeeInHtml($url, false)->assertSeeInText($url, false);

        return true;
    });

    $this->get($url)->assertOk()->assertSee('Darme de baja')->assertHeader('X-Robots-Tag', 'noindex');
    expect($this->user->fresh()->notify_weekly_digest)->toBeTrue(); // el GET (escaner de correo) no da de baja

    $this->post($url)->assertOk()->assertSee('Listo');
    expect($this->user->fresh()->notify_weekly_digest)->toBeFalse();
    $this->post($url)->assertOk(); // idempotente

    $other = User::factory()->create(['notify_weekly_digest' => true]);
    $tampered = str_replace('/digest/'.$this->user->id.'/', '/digest/'.$other->id.'/', $url);
    $this->post($tampered)->assertForbidden();
    $this->get($tampered)->assertForbidden();
    $this->post(route('digest.unsubscribe', ['user' => $other->id]))->assertForbidden(); // sin firma
    $this->post(URL::signedRoute('digest.unsubscribe', ['user' => $other->id]).'x')->assertForbidden();
    expect($other->fresh()->notify_weekly_digest)->toBeTrue();
});

test('las preferencias por tipo se guardan desde la pantalla de avisos y se validan', function () {
    $this->actingAs($this->user);
    $page = $this->get('/profile/notifications')->assertOk();
    expect($page->inertiaProps('preferences'))->toBe(['notify_weekly_digest' => true, 'weekly_digest_ai' => false, 'notify_manual_reminder' => true])
        ->and($page->inertiaProps('mailEnabled'))->toBeTrue()->and($page->inertiaProps('aiAvailable'))->toBeFalse();

    $this->patch(route('profile.notification-preferences'), ['notify_weekly_digest' => false, 'weekly_digest_ai' => true, 'notify_manual_reminder' => false])
        ->assertRedirect(route('profile.notifications'))->assertSessionHasNoErrors();
    expect($this->user->fresh()->only(['notify_weekly_digest', 'weekly_digest_ai', 'notify_manual_reminder']))
        ->toBe(['notify_weekly_digest' => false, 'weekly_digest_ai' => true, 'notify_manual_reminder' => false]);

    $this->patch(route('profile.notification-preferences'), ['notify_weekly_digest' => 'quizas'])->assertSessionHasErrors(['notify_weekly_digest', 'weekly_digest_ai', 'notify_manual_reminder']);
});

test('los usuarios nuevos reciben el recordatorio manual y no el resumen por defecto', function () {
    $fresh = User::query()->create(['name' => 'Nuevo', 'email' => 'nuevo@example.test', 'password' => 'secreto-largo-1'])->fresh();

    expect($fresh->notify_manual_reminder)->toBeTrue()->and($fresh->notify_weekly_digest)->toBeFalse()->and($fresh->weekly_digest_ai)->toBeFalse();
});

test('el planificador solo reparte jobs: uno por usuario al que le toca, con su semana', function () {
    Queue::fake();
    User::factory()->create(['notify_weekly_digest' => true]); // sin marcas: no hay zona ni nada que resumir
    $sent = User::factory()->create(['notify_weekly_digest' => true, 'weekly_digest_sent_for' => '2026-11-30']);
    Brand::factory()->for($sent)->create(['timezone' => 'UTC']);
    User::factory()->create(['notify_weekly_digest' => false]);

    digestRun();

    Queue::assertPushed(SendUserWeeklyDigest::class, 1);
    Queue::assertPushed(SendUserWeeklyDigest::class, fn (SendUserWeeklyDigest $job) => $job->userId === $this->user->id && $job->week === '2026-11-30');
    expect($this->user->notifications()->count())->toBe(0); // repartir no entrega nada
});

test('si el job de un usuario muere tras el claim, failed() devuelve la semana; si ya entrego, no', function () {
    $digest = app(WeeklyDigest::class);
    $job = new SendUserWeeklyDigest($this->user->id, '2026-11-30');

    // Timeout o kill entre el claim y la entrega: nada llego al usuario, asi que la semana se libera para la siguiente pasada.
    expect($digest->claim($this->user, '2026-11-30'))->toBeTrue();
    $job->failed(new RuntimeException('timeout'));
    expect($this->user->fresh()->weekly_digest_sent_for)->toBeNull();

    // Entregado y marcado: un failed() posterior no repite el resumen.
    $job->handle($digest);
    expect($this->user->notifications()->count())->toBe(1);
    $job->failed(new RuntimeException('el worker cae despues'));
    expect($this->user->fresh()->weekly_digest_sent_for->format('Y-m-d'))->toBe('2026-11-30');
    digestRun();
    expect($this->user->notifications()->count())->toBe(1);
});

test('el fallo de un usuario no consume su semana ni impide el resumen de los demas', function () {
    $second = User::factory()->create(['notify_weekly_digest' => true]);
    Brand::factory()->for($second)->create(['timezone' => 'UTC']);
    $calls = 0;
    $this->mock(ReportService::class)->shouldReceive('topPosts')->andReturnUsing(function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('boom');
        }

        return [];
    });

    digestRun();

    expect($this->user->fresh()->weekly_digest_sent_for)->toBeNull()->and($this->user->notifications()->count())->toBe(0)
        ->and($second->notifications()->count())->toBe(1);
});

test('las publicaciones del resumen salen del calculo del panel: zona de cada marca y sin cuentas desconectadas', function () {
    digestSeed($this->ig);
    digestPost($this->user, $this->ig, 'Cuenta activa', '2026-11-25 10:00:00', 5);
    $gone = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'status' => SocialAccount::STATUS_DISCONNECTED]);
    digestPost($this->user, $gone, 'Cuenta desconectada', '2026-11-25 11:00:00', 5);
    // Tokio va 9 h por delante: las 16:00 UTC del domingo 29 ya son lunes 30 alli, fuera de la semana de esa marca.
    $tokyo = Brand::factory()->for($this->user)->create(['name' => 'Tokio', 'timezone' => 'Asia/Tokyo']);
    $account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $tokyo->id, 'network' => Network::Instagram]);
    digestPost($this->user, $account, 'Domingo noche UTC', '2026-11-29 16:00:00', 5);
    digestPost($this->user, $account, 'Jueves', '2026-11-26 03:00:00', 5);

    digestRun();

    Mail::assertSent(WeeklyDigestMail::class, function (WeeklyDigestMail $mail) {
        $published = collect($mail->digest['brands'])->pluck('published', 'name');

        expect($published['Alfa'])->toBe(1)->and($published['Tokio'])->toBe(1);

        return true;
    });
});

test('el resumen tambien llega por push con un PushSender falso', function () {
    pushTestKeys();
    $subscription = PushSubscription::forceCreate([
        'user_id' => $this->user->id, 'endpoint_hash' => hash('sha256', 'https://push.example.test/resumen'),
        'endpoint' => 'https://push.example.test/resumen', 'keys' => ['p256dh' => 'k', 'auth' => 'a'], 'device_name' => 'Movil', 'last_seen_at' => now(),
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

    digestRun();
    digestRun();

    expect($this->user->notifications()->where('data->type', 'weekly_digest')->count())->toBe(1)->and($sender->sent)->toBe([$subscription->id]);
});

test('si el aviso se guarda y un listener falla despues, la semana no se libera ni se repite el aviso', function () {
    Event::listen(NotificationSent::class, fn () => throw new RuntimeException('listener roto'));

    digestRun();

    expect($this->user->notifications()->count())->toBe(1)
        ->and($this->user->fresh()->weekly_digest_sent_for->format('Y-m-d'))->toBe('2026-11-30');

    digestRun();

    expect($this->user->notifications()->count())->toBe(1);
});
