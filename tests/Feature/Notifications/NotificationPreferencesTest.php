<?php

use App\Jobs\SendPushNotification;
use App\Jobs\SendScheduledReport;
use App\Jobs\SendUserWeeklyDigest;
use App\Mail\ScheduledReportMail;
use App\Mail\WeeklyDigest as WeeklyDigestMail;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PushSubscription;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\AsyncJobDone;
use App\Notifications\AsyncJobFailed;
use App\Notifications\AsyncJobFailureSummary;
use App\Notifications\ConnectionExpiring;
use App\Notifications\FirstCommentFailed;
use App\Notifications\GoalAlert;
use App\Notifications\ManualPublishReminder;
use App\Notifications\PasskeyAdded;
use App\Notifications\PostMediaMissing;
use App\Notifications\PostWorkflowActivity;
use App\Notifications\PublishFailed;
use App\Notifications\ReconnectRequired;
use App\Notifications\SeriesGenerationFailed;
use App\Notifications\SiteMessagesFull;
use App\Notifications\SmartLinkSubscribersFull;
use App\Notifications\StudioDesignActivity;
use App\Notifications\WeeklyDigestReady;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;
use App\Services\ReportService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

function saveNotificationTypes(User $user, array $channels): void
{
    test()->actingAs($user)->patch('/profile/notification-preferences', [
        'notify_weekly_digest' => true, 'weekly_digest_ai' => false, 'notify_manual_reminder' => true,
        'notification_channels' => $channels,
    ])->assertRedirect('/profile/notifications')->assertSessionHasNoErrors();
}

test('stores channel preferences for the authenticated account only', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    saveNotificationTypes($user, ['job_failed' => ['database' => false, 'mail' => true, 'push' => false]]);

    $this->actingAs($user->refresh())->get('/profile/notifications')->assertOk()
        ->assertInertia(fn ($page) => $page->where('notificationChannels.job_failed.database', false)
            ->where('notificationChannels.job_failed.mail', true));
    $this->actingAs($other)->get('/profile/notifications')->assertOk()
        ->assertInertia(fn ($page) => $page->where('notificationChannels.job_failed.database', true)
            ->where('notificationChannels.job_failed.mail', false));
});

function notificationPreferenceDevice(User $user): PushSubscription
{
    pushTestKeys();

    return PushSubscription::forceCreate([
        'user_id' => $user->id, 'endpoint' => 'https://push.example.test/preferences',
        'endpoint_hash' => hash('sha256', 'https://push.example.test/preferences'),
        'keys' => ['p256dh' => 'k', 'auth' => 'a'], 'device_name' => 'Pruebas', 'last_seen_at' => now(),
    ]);
}

test('respects independent in-app, email and push delivery, including queued notifications', function (string $enabled) {
    $user = User::factory()->create(['notify_by_mail' => true]);
    $brand = Brand::factory()->for($user)->create();
    notificationPreferenceDevice($user);
    $sender = Mockery::mock(PushSender::class);
    $sender->shouldReceive('send')->times((int) ($enabled === 'push'))->andReturn(PushResult::Delivered);
    app()->instance(PushSender::class, $sender);

    $sent = [];
    Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sent): void {
        $sent[] = $event->channel;
    });
    saveNotificationTypes($user, ['job_failed' => [
        'database' => $enabled === 'database', 'mail' => $enabled === 'mail', 'push' => $enabled === 'push',
    ]]);

    $user->refresh()->notify(new AsyncJobFailed('canva', 'Fallo', 'Motivo', brandId: $brand->id));

    expect($user->notifications()->count())->toBe((int) ($enabled === 'database'))
        ->and(array_count_values($sent)['mail'] ?? 0)->toBe((int) ($enabled === 'mail'));
})->with(['database', 'mail', 'push', 'none']);

test('resolves every app notice type independently at dispatch and at delivery', function () {
    $user = User::factory()->create(['notify_by_mail' => true]);
    $brand = Brand::factory()->for($user)->create();
    $account = SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id]);
    $post = Post::factory()->for($user)->create(['brand_id' => $brand->id]);
    $target = PostTarget::factory()->for($post)->create(['social_account_id' => $account->id]);
    notificationPreferenceDevice($user);
    $notices = [
        'weekly_digest' => [new WeeklyDigestReady(['week' => '2026-11-30', 'period' => ['label' => 'Noviembre'], 'brands' => []])],
        'manual_reminder' => [new ManualPublishReminder($post->id, $brand->id, $brand->name, 'Recuerda')],
        'manual_due' => [new PostWorkflowActivity('manual_due', $post->id, $brand->id, $brand->name, 'Publica')],
        'approval_activity' => [new PostWorkflowActivity('approval_requested', $post->id, $brand->id, $brand->name, 'Revisa')],
        'collaboration' => [new PostWorkflowActivity('collaborator_invited', null, $brand->id, $brand->name, 'Invitación')],
        'publish_failed' => [new PublishFailed($target, 'Fallo')],
        'first_comment_failed' => [new FirstCommentFailed($target, 'Fallo')],
        'media_missing' => [new PostMediaMissing($post->id, [1])],
        'series_failed' => [new SeriesGenerationFailed(1, $brand->id, $brand->name, 'Fallo')],
        'connection_expiring' => [new ConnectionExpiring($account, 'Caduca', '30 de noviembre')],
        'reconnect_required' => [new ReconnectRequired($account, 'Reconecta')],
        'job_done' => [new AsyncJobDone('canva', 'Completado', 'Listo')],
        'job_failed' => [new AsyncJobFailed('canva', 'Fallo', 'Motivo'), new AsyncJobFailureSummary('canva', 1, 'exportación')],
        'goal_alert' => [new GoalAlert($user->id, ['goal_id' => 1, 'title' => 'Objetivo'])],
        'studio_activity' => [new StudioDesignActivity('comment', 1, 'Diseño', 'Comentario')],
        'site_messages_full' => [new SiteMessagesFull(1, 'Sitio', 10)],
        'smartlink_subscribers_full' => [new SmartLinkSubscribersFull(1, 1, 'enlace', 10)],
    ];
    $mailTypes = ['weekly_digest', 'manual_reminder', 'publish_failed', 'connection_expiring', 'reconnect_required', 'job_failed', 'goal_alert'];
    $disabled = [];
    foreach (array_keys($notices) as $type) {
        $disabled[$type] = ['database' => false, 'push' => false];
        if (in_array($type, $mailTypes, true)) {
            $disabled[$type]['mail'] = false;
        }
    }
    saveNotificationTypes($user, $disabled);
    Queue::fake([SendPushNotification::class]);
    $user->refresh();
    $sent = [];
    Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sent): void {
        $sent[] = $event->channel;
    });
    foreach ($notices as $type => $examples) {
        $sent = [];
        foreach ($examples as $notice) {
            $user->notify($notice);
        }
        expect($sent)->toBe([], $type);
        Queue::assertNotPushed(SendPushNotification::class);
    }

    foreach ($notices as $type => $examples) {
        $user->notification_channels = array_replace_recursive($disabled, [$type => ['database' => true]]);
        $user->save();
        $before = $user->notifications()->count();
        foreach ($examples as $notice) {
            $user->notify($notice);
        }
        expect($user->notifications()->count() - $before)->toBe(count($examples), $type);
        Queue::assertNotPushed(SendPushNotification::class);
    }
});

test('rechecks the current push setting when a queued push runs', function (bool $keepInbox) {
    $user = User::factory()->create();
    notificationPreferenceDevice($user);
    saveNotificationTypes($user, ['approval_activity' => ['database' => $keepInbox, 'push' => true]]);
    Queue::fake([SendPushNotification::class]);
    $user->refresh()->notify(new PostWorkflowActivity('approval_requested', null, null, '', 'Revisa'));
    Queue::assertPushed(SendPushNotification::class, 1);

    saveNotificationTypes($user, ['approval_activity' => ['push' => false]]);
    $sender = Mockery::mock(PushSender::class);
    $sender->shouldNotReceive('send');
    app()->instance(PushSender::class, $sender);
    app()->call([Queue::pushed(SendPushNotification::class)->sole(), 'handle']);
})->with([true, false]);

test('checks preferences for the digest mailable, manual reminder and immediate database sends', function () {
    $this->travelTo(Carbon::parse('2026-11-30 09:00:00'));
    Mail::fake();
    $user = User::factory()->create(['notify_by_mail' => true]);
    $brand = Brand::factory()->for($user)->create(['timezone' => 'UTC']);
    saveNotificationTypes($user, [
        'weekly_digest' => ['database' => false, 'mail' => false, 'push' => false],
        'manual_reminder' => ['database' => false, 'mail' => false, 'push' => false],
        'approval_activity' => ['database' => false, 'push' => false],
    ]);

    app()->call([new SendUserWeeklyDigest($user->id, '2026-11-30'), 'handle']);
    $user->refresh()->notify(new ManualPublishReminder(1, $brand->id, $brand->name, 'Recuerda'));
    Notification::sendNow($user, new PostWorkflowActivity('approval_requested', null, $brand->id, $brand->name, 'Petición'));

    expect($user->notifications()->count())->toBe(0);
    Mail::assertNotSent(WeeklyDigestMail::class);
});

test('password reset, verification and account security notices still send with mail opted out', function () {
    $user = User::factory()->unverified()->create(['notify_by_mail' => false]);
    $sent = [];
    Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sent): void {
        if ($event->channel === 'mail') {
            $sent[] = $event->notification::class;
        }
    });
    $user->sendPasswordResetNotification('test-token');
    $user->sendEmailVerificationNotification();
    $user->notify(new PasskeyAdded('Llave', 'Hoy', null));

    expect($sent)->toBe([ResetPassword::class, VerifyEmail::class, PasskeyAdded::class]);
});

test('el informe programado registra que se omitió el correo por preferencias', function (bool $disabledDuringGeneration) {
    Mail::fake();
    $this->freezeSecond();
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $template = ReportTemplate::create([
        'user_id' => $user->id, 'brand_id' => $brand->id, 'name' => 'Semanal', 'blocks' => [['type' => 'kpis']],
    ]);
    $schedule = ReportSchedule::create([
        'user_id' => $user->id, 'brand_id' => $brand->id, 'report_template_id' => $template->id,
        'frequency' => 'weekly', 'recipients' => ['recipient@example.test'], 'next_run_at' => now(),
    ]);
    if ($disabledDuringGeneration) {
        $this->partialMock(ReportService::class)->shouldReceive('generate')->once()->andReturnUsing(function (Report $report) use ($user): Report {
            $user->forceFill(['notification_channels' => ['scheduled_report' => ['mail' => false]]])->save();

            return $report->forceFill(['status' => Report::STATUS_READY]);
        });
    } else {
        saveNotificationTypes($user, ['scheduled_report' => ['mail' => false]]);
    }

    SendScheduledReport::dispatchSync($schedule->id);

    Mail::assertNotSent(ScheduledReportMail::class);
    expect(Report::ownedBy($user->id)->where('schedule_id', $schedule->id)->exists())->toBe($disabledDuringGeneration)
        ->and(RateLimiter::attempts('report-mail:'.$user->id))->toBe(0);
    $this->actingAs($user)->get(route('reports.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('schedules.0.id', $schedule->id)
            ->where('schedules.0.active', true)
            ->where('schedules.0.last_run_at', now()->toIso8601String())
            ->where('schedules.0.last_error', 'Envío omitido por preferencias de notificación.'));
})->with(['antes de generar' => [false], 'durante la generación' => [true]]);

test('una cuenta sin verificar puede desactivar los atajos desde el perfil', function () {
    $user = User::factory()->unverified()->create(['single_key_shortcuts' => true]);

    $this->actingAs($user)->from(route('profile.edit'))->patch(route('profile.shortcuts'), ['single_key_shortcuts' => false])
        ->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();

    expect($user->fresh()->single_key_shortcuts)->toBeFalse();
});

test('persists onboarding progress, skipping and restarting without affecting another user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('onboarding', 'welcome'));

    foreach (['connection', 'skipped', 'brand', 'post', 'completed'] as $step) {
        $this->from('/dashboard')->patch('/profile/onboarding', ['step' => $step, 'user_id' => $other->id])
            ->assertRedirect('/dashboard')->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('onboarding', $step));
    }

    $this->actingAs($other)->get('/dashboard')->assertInertia(fn ($page) => $page->where('onboarding', 'welcome'));
});

test('does not offer onboarding automatically when the account already has a brand', function () {
    $user = User::factory()->create();
    Brand::factory()->for($user)->create();
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('onboarding', null));
});
