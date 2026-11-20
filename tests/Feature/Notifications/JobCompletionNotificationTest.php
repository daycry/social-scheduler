<?php

use App\Jobs\ImportRemoteMedia;
use App\Jobs\RunCanvaJob;
use App\Jobs\SendScheduledReport;
use App\Jobs\Studio\RenderStudioDesign;
use App\Jobs\Studio\RunStudioAiJob;
use App\Models\Brand;
use App\Models\Canva\CanvaJob;
use App\Models\MediaAsset;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAiJob;
use App\Models\User;
use App\Notifications\AsyncJobFailed;
use App\Notifications\StudioDesignActivity;
use App\Services\Reports\ReportBlocks;
use App\Services\Studio\StudioAi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/** T-23 · los jobs asincronos que fallan avisan al usuario (informes, MP4/GIF, IA, Canva, importaciones), sin duplicar y con correo opcional. */
beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

function jobNotices(User $user, string $type)
{
    return $user->notifications()->where('data->type', $type)->get();
}

function canvaFailing(object $t): CanvaJob
{
    return CanvaJob::create(['user_id' => $t->user->id, 'brand_id' => $t->brand->id, 'kind' => 'export', 'status' => 'processing']);
}

test('un informe programado que falla avisa con enlace a Informes', function () {
    $t = ReportTemplate::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'name' => 'Mensual', 'blocks' => ReportBlocks::normalize([['type' => 'kpis']]), 'range_days' => 30, 'compare' => 'previous']);
    $s = ReportSchedule::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'report_template_id' => $t->id, 'frequency' => 'weekly', 'recipients' => ['a@example.com'], 'next_run_at' => now()]);

    (new SendScheduledReport($s->id))->failed(new RuntimeException('secreto interno'));

    $n = jobNotices($this->user, 'job_failed_report');
    expect($n)->toHaveCount(1)->and($n[0]->data['url'])->toBe(route('reports.index', [], false))
        ->and($n[0]->data['message'])->not->toContain('secreto interno');
});

test('una exportacion MP4/GIF interrumpida avisa con enlace al diseno', function () {
    $design = Design::unguarded(fn () => Design::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'name' => 'Mi diseno', 'document' => ['pages' => []], 'width' => 1, 'height' => 1, 'page_count' => 1, 'revision' => 1]));
    $x = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id, 'revision' => 1, 'format' => 'mp4', 'pages' => ['p1'], 'media_ids' => [], 'status' => 'processing']);

    (new RenderStudioDesign($x->id))->failed(new RuntimeException('x'));

    $n = jobNotices($this->user, 'job_failed_studio_export');
    expect($n)->toHaveCount(1)->and($n[0]->data['url'])->toBe(route('studio.designs.edit', $design->id, false))->and($n[0]->data['excerpt'])->toBe('Mi diseno');
});

test('un trabajo de IA que falla avisa una sola vez aunque fallen la cola y el servicio', function () {
    $j = StudioAiJob::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'kind' => 'generate', 'provider' => 'x', 'model' => 'm', 'prompt' => 'p', 'fingerprint' => 'f', 'status' => 'processing']);

    (new RunStudioAiJob($j->id))->failed(new RuntimeException('x'));
    (new RunStudioAiJob($j->id))->failed(new RuntimeException('x'));

    expect(jobNotices($this->user, 'job_failed_studio_ai'))->toHaveCount(1);
});

test('el servicio de IA avisa cuando el proveedor no esta disponible', function () {
    $j = StudioAiJob::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'kind' => 'generate', 'provider' => 'inexistente', 'model' => 'm', 'prompt' => 'p', 'fingerprint' => 'f2', 'status' => 'queued']);

    app(StudioAi::class)->process($j->id);

    expect($j->fresh()->status)->toBe('failed')->and(jobNotices($this->user, 'job_failed_studio_ai'))->toHaveCount(1);
});

test('un trabajo de Canva que falla avisa con enlace a Canva de su marca', function () {
    $j = canvaFailing($this);

    (new RunCanvaJob($j->id))->failed(new RuntimeException('x'));

    $n = jobNotices($this->user, 'job_failed_canva');
    expect($n)->toHaveCount(1)->and($n[0]->data['url'])->toBe(route('canva.index', $this->brand, false));
});

test('una importacion remota que falla avisa con enlace a la biblioteca', function () {
    $asset = MediaAsset::factory()->create(['user_id' => $this->user->id, 'status' => MediaAsset::STATUS_PENDING, 'source' => MediaAsset::SOURCE_URL]);

    (new ImportRemoteMedia($asset->id, 'https://example.com/a.png'))->failed(new RuntimeException('x'));

    $n = jobNotices($this->user, 'job_failed_import');
    expect($n)->toHaveCount(1)->and($n[0]->data['url'])->toBe(route('media.index', [], false));
});

test('el correo de fallo de un job solo viaja si el usuario lo activo', function () {
    Notification::fake();
    (new RunCanvaJob(canvaFailing($this)->id))->failed(null);
    Notification::assertSentTo($this->user, AsyncJobFailed::class, fn ($n) => $n->via($this->user) === ['database']);

    Cache::flush();
    Notification::fake();
    $this->user->forceFill(['notify_by_mail' => true])->save();
    (new RunCanvaJob(canvaFailing($this)->id))->failed(null);
    Notification::assertSentTo($this->user, AsyncJobFailed::class, fn ($n) => $n->via($this->user) === ['database', 'mail'] && str_contains($n->toMail($this->user)->subject, 'Canva'));
});

test('el aviso se limita: el mismo trabajo no duplica y otro trabajo del mismo tipo si avisa', function () {
    $a = canvaFailing($this);
    (new RunCanvaJob($a->id))->failed(null);
    (new RunCanvaJob($a->id))->failed(null);
    expect(jobNotices($this->user, 'job_failed_canva'))->toHaveCount(1);

    (new RunCanvaJob(canvaFailing($this)->id))->failed(null);
    expect(jobNotices($this->user, 'job_failed_canva'))->toHaveCount(2);
});

test('la pagina de avisos entrega la URL de cada tipo, incluidos los del estudio', function () {
    $this->user->notify(new StudioDesignActivity('comment', 7, 'Rebajas', 'Ana ha comentado'));
    $this->user->notify(new StudioDesignActivity('revoked', 7, 'Rebajas', 'Ya no tienes acceso'));

    $this->actingAs($this->user)->get(route('notifications.index'))->assertInertia(fn ($page) => $page
        ->where('notifications', fn ($list) => collect($list)->firstWhere('type', 'studio_design_comment')['url'] === route('studio.designs.edit', 7, false)
            && collect($list)->firstWhere('type', 'studio_design_revoked')['url'] === null));
});

test('el perfil guarda la preferencia de correo', function () {
    $this->actingAs($this->user)->patch(route('profile.update'), ['name' => $this->user->name, 'email' => $this->user->email, 'notify_by_mail' => true])->assertRedirect(route('profile.edit'));

    expect($this->user->fresh()->notify_by_mail)->toBeTrue();
});
