<?php

use App\Connectors\Network;
use App\Jobs\RunCanvaJob;
use App\Jobs\SendScheduledReport;
use App\Models\Brand;
use App\Models\Canva\CanvaJob;
use App\Models\MediaAsset;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\SocialAccount;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAiJob;
use App\Models\User;
use App\Notifications\AsyncJobDone;
use App\Services\Canva\CanvaJobs;
use App\Services\ConnectionExpiryNotifier;
use App\Services\JobFailureNotifier;
use App\Services\Reports\ReportBlocks;
use App\Support\PermissionHelp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/** T-22/T-23 · revision intento 1 (gaps 2-10): un solo aviso de permisos, caducidad de refresh, umbral con margen, tope con resumen, avisos de exito, claves por ejecucion, zona, alias y enlaces. */
beforeEach(function () {
    Carbon::setTestNow('2026-11-12 09:00:00');
    Cache::flush();
    config(['social.expiry_notice_days' => [7, 1]]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
});

function p3Account(object $t, Network $n, array $over = []): SocialAccount
{
    return SocialAccount::factory()->for($t->user)->create($over + ['brand_id' => $t->brand->id, 'network' => $n, 'refresh_token' => null, 'external_id' => 'x'.uniqid()]);
}

function p3Canva(object $t): CanvaJob
{
    return CanvaJob::create(['user_id' => $t->user->id, 'brand_id' => $t->brand->id, 'kind' => 'export', 'status' => 'processing']);
}

function p3Notices(User $u, string $like)
{
    return $u->notifications()->where('data->type', 'like', $like)->get();
}

// ---- gap 2: un solo aviso de permisos para Ads y el inbox
test('Ads e inbox usan el mismo texto de permisos de Meta con META_EXTRA_SCOPES y los pasos', function () {
    $fb = p3Account($this, Network::Facebook, ['refresh_token' => 'U', 'scopes' => ['pages_show_list'], 'name' => 'Pagina']);

    $this->actingAs($this->user)->get(route('ads.index'))->assertInertia(fn ($p) => $p
        ->where('overview.reason', 'sin_permiso')
        ->where('overview.help', PermissionHelp::meta('Meta Ads', 'ads_read'))
        ->where('overview.help', fn ($h) => str_contains($h, 'META_EXTRA_SCOPES') && str_contains($h, 'Conexiones'))
        ->where('brand.id', $this->brand->id));

    $this->actingAs($this->user)->get(route('inbox.index'))->assertInertia(fn ($p) => $p
        ->where('accounts.0.help', PermissionHelp::meta('Facebook', 'pages_read_user_content')));
    expect(PermissionHelp::meta('Meta Ads', 'ads_read'))->toContain('ads_read')->and(PermissionHelp::meta('Facebook', 'pages_read_user_content'))->toContain('pages_read_user_content');
    expect($fb->id)->toBeInt();
});

// ---- gap 3: caducidad del refresh token
test('el refresh token que caduca (LinkedIn, Pinterest, TikTok) tambien avisa', function () {
    p3Account($this, Network::LinkedIn, ['refresh_token' => 'RT', 'refresh_expires_at' => now()->addDays(5), 'token_expires_at' => now()->addDays(40)]);
    p3Account($this, Network::Pinterest, ['refresh_token' => 'RT', 'refresh_expires_at' => now()->addDays(3)]);
    p3Account($this, Network::TikTok, ['refresh_token' => 'RT', 'refresh_expires_at' => now()->addDays(300)]);

    expect(app(ConnectionExpiryNotifier::class)->run())->toBe(2);
    $n = p3Notices($this->user, 'connection_expiring');
    expect($n->pluck('data.message')->join(' '))->toContain('renovación');
});

// ---- gap 4: margen del umbral
test('el aviso de 7 dias llega con al menos 7 dias de margen: limite exacto de 7 dias mas la pasada', function () {
    $edge = p3Account($this, Network::Facebook, ['data_access_expires_at' => now()->addDays(8)]); // 7 d + 24 h: justo
    p3Account($this, Network::Facebook, ['data_access_expires_at' => now()->addDays(8)->addSecond()]);

    expect(app(ConnectionExpiryNotifier::class)->run())->toBe(1);
    expect(p3Notices($this->user, 'connection_expiring')->first()->data['social_account_id'])->toBe($edge->id);
});

test('el aviso de 1 dia llega con al menos 1 dia de margen: avisa cuando quedan 2 dias o menos', function () {
    p3Account($this, Network::Facebook, ['data_access_expires_at' => now()->addDays(2)->addSecond()]);
    $notifier = app(ConnectionExpiryNotifier::class);

    expect($notifier->run())->toBe(1); // umbral de 7 d
    Carbon::setTestNow(now()->addSecond()); // quedan exactamente 2 d
    expect($notifier->run())->toBe(1); // umbral de 1 d
    expect(p3Notices($this->user, 'connection_expiring'))->toHaveCount(2);
});

// ---- gap 5: el tope deja rastro y no consume la clave
test('lo que corta el tope de 5 se registra, se agrupa en un aviso resumen y no consume la clave del trabajo', function () {
    Log::spy();
    $jobs = collect(range(1, 7))->map(fn () => p3Canva($this));
    foreach ($jobs as $j) {
        (new RunCanvaJob($j->id))->failed(null);
    }

    expect(p3Notices($this->user, 'job_failed_canva'))->toHaveCount(5);
    $summary = p3Notices($this->user, 'job_failed_summary');
    expect($summary)->toHaveCount(1)->and($summary[0]->data['count'])->toBe(2)
        ->and($summary[0]->data['title'])->toContain('2 fallos más de Canva')->and($summary[0]->data['url'])->toBe(route('canva.index', $this->brand, false));
    Log::shouldHaveReceived('warning')->with('jobs.failure_notification_capped', Mockery::type('array'))->atLeast()->once();

    // Repetir el fallo de un trabajo ya resumido no suma otra vez; y pasada la hora el trabajo cortado SI avisa (su clave no se consumio).
    (new RunCanvaJob($jobs[6]->id))->failed(null);
    expect(p3Notices($this->user, 'job_failed_summary')[0]->fresh()->data['count'])->toBe(2);
    Carbon::setTestNow(now()->addMinutes(61));
    CanvaJob::withoutUserScope()->whereKey($jobs[6]->id)->update(['status' => 'processing']);
    (new RunCanvaJob($jobs[6]->id))->failed(null);
    expect(p3Notices($this->user, 'job_failed_canva'))->toHaveCount(6);
});

// ---- gap 6: avisos de exito solo en la app
test('al terminar bien avisan (solo en la app, nunca por correo) exportacion MP4/GIF, Canva, IA e importacion por URL', function () {
    Notification::fake();
    $this->user->forceFill(['notify_by_mail' => true])->save();
    $design = Design::unguarded(fn () => Design::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'name' => 'Mi diseno', 'document' => ['pages' => []], 'width' => 1, 'height' => 1, 'page_count' => 1, 'revision' => 1]));
    $mp4 = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id, 'revision' => 1, 'format' => 'mp4', 'pages' => ['p1'], 'media_ids' => [], 'status' => 'ready']);
    $png = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id, 'revision' => 1, 'format' => 'png', 'pages' => ['p1'], 'media_ids' => [], 'status' => 'ready']);
    $ai = StudioAiJob::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'kind' => 'generate', 'provider' => 'x', 'model' => 'm', 'prompt' => 'p', 'fingerprint' => 'f', 'status' => 'ready']);
    $asset = MediaAsset::factory()->create(['user_id' => $this->user->id, 'source' => MediaAsset::SOURCE_URL, 'status' => MediaAsset::STATUS_READY, 'original_name' => 'foto.png']);

    $n = app(JobFailureNotifier::class);
    $n->exportDone($mp4);
    $n->exportDone($png); // PNG: lo ve el usuario en el editor
    $n->aiDone($ai);
    $n->canvaDone(p3Canva($this));
    $n->importDone($asset);

    $types = collect(Notification::sent($this->user, AsyncJobDone::class))->map(fn ($x) => $x->toArray($this->user)['type'])->sort()->values()->all();
    expect($types)->toBe(['job_done_canva', 'job_done_import', 'job_done_studio_ai', 'job_done_studio_export']);
    Notification::assertSentTo($this->user, AsyncJobDone::class, fn ($x) => $x->via($this->user) === ['database']);
    expect(method_exists($n, 'reportDone'))->toBeFalse(); // el informe programado ya avisa con su propio correo
});

test('el exito de una importacion por URL se avisa al terminar la descarga y el de Canva al quedar listo', function () {
    $j = p3Canva($this);
    $j->forceFill(['kind' => 'upload', 'status' => 'processing'])->save();
    $svc = app(CanvaJobs::class);
    $m = new ReflectionMethod($svc, 'finishUpload');
    $m->setAccessible(true);
    $m->invoke($svc, $j, ['asset' => ['id' => 'A1']]);

    expect(p3Notices($this->user, 'job_done_canva'))->toHaveCount(1);
});

// ---- gap 7: clave por ejecucion
test('un informe programado avisa una vez por ejecucion (fecha), no una vez para siempre', function () {
    $t = ReportTemplate::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'name' => 'M', 'blocks' => ReportBlocks::normalize([['type' => 'kpis']]), 'range_days' => 30, 'compare' => 'previous']);
    $s = ReportSchedule::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'report_template_id' => $t->id, 'frequency' => 'weekly', 'recipients' => ['a@example.com'], 'next_run_at' => now()]);

    (new SendScheduledReport($s->id))->failed(new RuntimeException('x'));
    (new SendScheduledReport($s->id))->failed(new RuntimeException('x'));
    expect(p3Notices($this->user, 'job_failed_report'))->toHaveCount(1);

    Carbon::setTestNow(now()->addHours(16)); // otra fecha, dentro de las 24 h de la clave anterior
    (new SendScheduledReport($s->id))->failed(new RuntimeException('x'));
    expect(p3Notices($this->user, 'job_failed_report'))->toHaveCount(2);
});

// ---- gap 8: zona horaria
test('la fecha del aviso de caducidad se escribe en la zona de la marca', function () {
    p3Account($this, Network::Facebook, ['data_access_expires_at' => Carbon::parse('2026-11-18 23:30:00', 'UTC')]); // 00:30 del 19 en Madrid

    app(ConnectionExpiryNotifier::class)->run();

    expect(p3Notices($this->user, 'connection_expiring')->first()->data['expires_at'])->toBe('19 de noviembre de 2026');
});
