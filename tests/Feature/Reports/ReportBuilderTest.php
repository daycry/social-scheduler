<?php

use App\Connectors\Network;
use App\Console\Commands\SendScheduledReports;
use App\Jobs\SendScheduledReport;
use App\Mail\ScheduledReportMail;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\CompetitorSnapshot;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\CompetitorService;
use App\Services\Reports\ReportBlocks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-11-04 12:00:00'); // miercoles
    Cache::flush();
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Marca A', 'timezone' => 'Europe/Madrid']);
    $this->other = Brand::factory()->for($this->user)->create(['name' => 'Marca B']);
    $this->stranger = User::factory()->create();
    $this->foreignBrand = Brand::factory()->for($this->stranger)->create(['name' => 'Marca ajena']);
});

function builderBlocks(): array
{
    return [
        ['type' => 'text', 'title' => 'Resumen', 'text' => "Hola\nmundo"],
        ['type' => 'kpis'],
        ['type' => 'evolution'],
        ['type' => 'top_posts'],
        ['type' => 'competitors'],
        ['type' => 'ads'],
    ];
}

function makeTemplate(User $user, Brand $brand, string $name = 'Mensual', ?array $blocks = null): ReportTemplate
{
    return ReportTemplate::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => $name, 'blocks' => ReportBlocks::normalize($blocks ?? builderBlocks()), 'range_days' => 30, 'compare' => 'previous']);
}

it('ReportBlocks: normaliza, conserva el orden y rechaza tipos, textos vacios y excesos', function () {
    $blocks = ReportBlocks::normalize([['type' => 'ads', 'text' => 'ignorado'], ['type' => 'text', 'title' => ' T ', 'text' => ' nota ']]);
    expect(array_column($blocks, 'type'))->toBe(['ads', 'text'])->and($blocks[0]['text'])->toBeNull()->and($blocks[1]['title'])->toBe('T')->and($blocks[1]['text'])->toBe('nota');

    foreach ([[], 'kpis', [['type' => 'script']], [['type' => 'text', 'text' => '  ']], [['type' => 'text', 'text' => str_repeat('a', 2001)]], [['type' => 'kpis', 'title' => str_repeat('a', 81)]], array_fill(0, 21, ['type' => 'kpis'])] as $bad) {
        expect(fn () => ReportBlocks::normalize($bad))->toThrow(InvalidArgumentException::class);
    }
});

it('Reports builder: el informe guarda los bloques elegidos y genera un PDF real con ellos', function () {
    $this->actingAs($this->user)->post(route('reports.store'), [
        'brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'formats' => ['pdf', 'csv'], 'blocks' => builderBlocks(),
    ])->assertRedirect(route('reports.index', ['brand_id' => $this->brand->id]));

    $pdf = Report::query()->where('format', 'pdf')->firstOrFail();
    $csv = Report::query()->where('format', 'csv')->firstOrFail();
    expect($pdf->status)->toBe('ready')->and(array_column($pdf->blocks, 'type'))->toBe(['text', 'kpis', 'evolution', 'top_posts', 'competitors', 'ads'])
        ->and($csv->blocks)->toBeNull()
        ->and(Storage::disk('local')->get($pdf->path))->toStartWith('%PDF');
});

it('Reports builder: bloques invalidos se devuelven como error del formulario', function () {
    $this->actingAs($this->user)->post(route('reports.store'), ['brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'formats' => ['pdf'], 'blocks' => [['type' => 'nope']]])
        ->assertSessionHasErrors('blocks');
    expect(Report::count())->toBe(0);
});

it('Reports builder: las notas de texto se escapan en el PDF', function () {
    $html = view('reports.brand', [
        'data' => [
            'brand' => ['id' => 1, 'name' => '<b>Marca</b>'], 'period' => ['from' => '2026-10-01', 'to' => '2026-10-31', 'days' => 31], 'comparison' => null,
            'generated_at' => now()->toIso8601String(), 'cards' => [], 'timeline' => ['label' => null, 'granularity' => 'day', 'points' => []], 'networks' => [], 'notes' => [],
            'blocks' => [['type' => 'text', 'title' => '<i>Titulo</i>', 'text' => "<script>alert(1)</script>\n<img src=x onerror=1>", 'data' => []]],
        ],
        'branding' => ['color' => '#4f46e5', 'logo' => null, 'footer' => ''], 'sections' => ['notes'],
    ])->render();

    expect($html)->not->toContain('<script>')->not->toContain('<img src=x')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;<br />')->toContain('&lt;i&gt;Titulo&lt;/i&gt;');
});

it('Reports builder: la vista previa usa solo datos de la marca elegida (competidores y Ads no se mezclan)', function () {
    $mk = function (Brand $brand, string $username, string $token) {
        SocialAccount::factory()->for($this->user)->create(['brand_id' => $brand->id, 'network' => Network::Facebook, 'refresh_token' => $token, 'scopes' => ['ads_read']]);
        $c = Competitor::query()->forceCreate(['user_id' => $this->user->id, 'brand_id' => $brand->id, 'username' => $username]);
        CompetitorSnapshot::query()->create(['user_id' => $this->user->id, 'competitor_id' => $c->id, 'date' => '2026-10-05', 'followers_count' => 100, 'media_count' => 1]);
    };
    $mk($this->brand, 'rival_a', 'TOKEN-A');
    $mk($this->other, 'rival_b', 'TOKEN-B');
    Http::fake(function ($request) {
        $a = ($request->header('Authorization')[0] ?? '') === 'Bearer TOKEN-A';
        $url = $request->url();
        if (str_contains($url, '/me/adaccounts')) {
            return Http::response(['data' => [['id' => $a ? 'act_1' : 'act_2', 'name' => $a ? 'Cuenta A' : 'Cuenta B', 'currency' => 'EUR']]]);
        }
        if (str_contains($url, '/campaigns')) {
            return Http::response(['data' => [['id' => '9', 'name' => $a ? 'Camp-A' : 'Camp-B']]]);
        }

        return Http::response(['data' => [['campaign_id' => '9', 'spend' => '10']]]);
    });

    $payload = fn (Brand $b) => ['brand_id' => $b->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'blocks' => [['type' => 'competitors'], ['type' => 'ads']]];
    $a = $this->actingAs($this->user)->postJson(route('reports.preview'), $payload($this->brand))->assertOk()->json();
    $b = $this->postJson(route('reports.preview'), $payload($this->other))->assertOk()->json();

    expect($a['brand']['name'])->toBe('Marca A')->and($a['blocks'][0]['data']['competitors'][0]['username'])->toBe('rival_a')->and($a['blocks'][1]['data']['account'])->toBe('Cuenta A')
        ->and(json_encode($a))->not->toContain('rival_b')->not->toContain('Camp-B')
        ->and($b['blocks'][0]['data']['competitors'][0]['username'])->toBe('rival_b')->and($b['blocks'][1]['data']['campaigns'][0]['name'])->toBe('Camp-B')
        ->and(json_encode($b))->not->toContain('rival_a')->not->toContain('Camp-A');
});

it('Reports builder: la vista previa rechaza marca ajena y bloques invalidos', function () {
    $this->actingAs($this->user)->postJson(route('reports.preview'), ['brand_id' => $this->foreignBrand->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'blocks' => [['type' => 'kpis']]])->assertNotFound();
    $this->postJson(route('reports.preview'), ['brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'blocks' => [['type' => 'x']]])->assertStatus(422);
});

it('Templates: se guardan por marca, no se repiten y no se ven ni tocan desde otro usuario', function () {
    $body = ['brand_id' => $this->brand->id, 'name' => 'Mensual', 'blocks' => builderBlocks(), 'range_days' => 30, 'compare' => 'previous'];
    $this->actingAs($this->user)->post(route('report-templates.store'), $body)->assertRedirect();
    $this->post(route('report-templates.store'), $body)->assertSessionHasErrors('name');
    $this->post(route('report-templates.store'), ['name' => 'Mensual', 'brand_id' => $this->other->id] + $body)->assertSessionHasNoErrors(); // mismo nombre en otra marca
    $this->post(route('report-templates.store'), ['brand_id' => $this->foreignBrand->id] + $body)->assertNotFound();
    $this->post(route('report-templates.store'), ['blocks' => [['type' => 'zzz']], 'name' => 'Mala'] + $body)->assertSessionHasErrors('blocks');
    expect(ReportTemplate::count())->toBe(2);

    $this->get(route('reports.index', ['brand_id' => $this->brand->id]))->assertInertia(fn ($p) => $p->has('templates', 2)->where('templates.0.name', 'Mensual'));

    $t = ReportTemplate::query()->where('brand_id', $this->brand->id)->firstOrFail();
    $this->actingAs($this->stranger)->put(route('report-templates.update', $t), ['name' => 'X', 'blocks' => [['type' => 'kpis']], 'range_days' => 7])->assertNotFound();
    $this->delete(route('report-templates.destroy', $t))->assertNotFound();
    $this->get(route('reports.index'))->assertInertia(fn ($p) => $p->has('templates', 0));
    expect($t->fresh()->name)->toBe('Mensual');

    $this->actingAs($this->user)->put(route('report-templates.update', $t), ['name' => 'Semanal', 'blocks' => [['type' => 'kpis']], 'range_days' => 7, 'compare' => 'none'])->assertRedirect();
    expect($t->fresh()->name)->toBe('Semanal')->and($t->fresh()->range_days)->toBe(7);
    $this->delete(route('report-templates.destroy', $t))->assertRedirect();
    expect(ReportTemplate::query()->where('brand_id', $this->brand->id)->count())->toBe(0);
});

it('Schedules: validan destinatarios, plantilla de la misma marca y aislamiento entre usuarios', function () {
    $t = makeTemplate($this->user, $this->brand);
    $other = makeTemplate($this->user, $this->other, 'De B');
    $foreign = makeTemplate($this->stranger, $this->foreignBrand, 'Ajena');
    $body = ['brand_id' => $this->brand->id, 'report_template_id' => $t->id, 'frequency' => 'weekly', 'recipients' => ['Cliente@Example.com', 'cliente@example.com', 'otro@example.com']];

    $this->actingAs($this->user);
    foreach ([['recipients' => ['no-es-email']], ['recipients' => []], ['recipients' => array_map(fn ($i) => "u$i@example.com", range(1, 6))], ['recipients' => ["a@example.com\r\nBcc: x@example.com"]], ['frequency' => 'daily']] as $bad) {
        $this->post(route('report-schedules.store'), $bad + $body)->assertSessionHasErrors();
    }
    $this->post(route('report-schedules.store'), ['report_template_id' => $other->id] + $body)->assertNotFound(); // plantilla de otra marca
    $this->post(route('report-schedules.store'), ['report_template_id' => $foreign->id] + $body)->assertNotFound();
    $this->post(route('report-schedules.store'), ['brand_id' => $this->foreignBrand->id] + $body)->assertNotFound();
    expect(ReportSchedule::count())->toBe(0);

    $this->post(route('report-schedules.store'), $body)->assertRedirect();
    $s = ReportSchedule::firstOrFail();
    expect($s->recipients)->toBe(['cliente@example.com', 'otro@example.com'])->and($s->active)->toBeTrue()
        ->and($s->next_run_at->toIso8601String())->toBe('2026-11-09T07:00:00+00:00'); // lunes 08:00 Madrid (UTC+1)

    $this->actingAs($this->stranger)->put(route('report-schedules.update', $s), ['active' => false])->assertNotFound();
    $this->delete(route('report-schedules.destroy', $s))->assertNotFound();
    expect($s->fresh()->active)->toBeTrue();

    $this->actingAs($this->user)->put(route('report-schedules.update', $s), ['active' => false, 'recipients' => ['solo@example.com']])->assertRedirect();
    expect($s->fresh()->active)->toBeFalse()->and($s->fresh()->recipients)->toBe(['solo@example.com']);
    $this->delete(route('report-schedules.destroy', $s))->assertRedirect();
    expect(ReportSchedule::count())->toBe(0);
});

it('Schedules: el periodo es la semana o el mes cerrados anteriores y la siguiente fecha cae a las 08:00 locales', function () {
    $now = CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC');
    expect(ReportSchedule::periodFor('weekly', 'Europe/Madrid', $now)->toArray())->toMatchArray(['from' => '2026-10-26', 'to' => '2026-11-01'])
        ->and(ReportSchedule::periodFor('monthly', 'Europe/Madrid', $now)->toArray())->toMatchArray(['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->and(ReportSchedule::nextRun('monthly', 'America/Los_Angeles', $now)->toIso8601String())->toBe('2026-12-01T16:00:00+00:00');
});

it('Schedules: el comando encola cada programacion vencida una sola vez y adelanta su fecha', function () {
    Queue::fake();
    $t = makeTemplate($this->user, $this->brand);
    $due = ReportSchedule::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'report_template_id' => $t->id, 'frequency' => 'weekly', 'recipients' => ['a@example.com'], 'next_run_at' => now()->subMinute()]);
    ReportSchedule::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'report_template_id' => $t->id, 'frequency' => 'weekly', 'recipients' => ['a@example.com'], 'next_run_at' => now()->addDay()]);
    ReportSchedule::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'report_template_id' => $t->id, 'frequency' => 'weekly', 'recipients' => ['a@example.com'], 'active' => false, 'next_run_at' => now()->subDay()]);

    $this->artisan('reports:send-scheduled')->assertSuccessful();
    $this->artisan('reports:send-scheduled')->assertSuccessful();

    Queue::assertPushed(SendScheduledReport::class, 1);
    Queue::assertPushed(SendScheduledReport::class, fn ($j) => $j->scheduleId === $due->id && $j->queue === 'reports');
    expect($due->fresh()->next_run_at->isFuture())->toBeTrue();
    expect(SendScheduledReports::class)->toBeString();
});

it('Schedules: el envio genera el PDF de la plantilla y lo manda por separado a cada destinatario (Mail::fake)', function () {
    Mail::fake();
    $t = makeTemplate($this->user, $this->brand, 'Mensual', [['type' => 'text', 'text' => 'Nota'], ['type' => 'kpis']]);
    $s = ReportSchedule::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'report_template_id' => $t->id, 'frequency' => 'weekly', 'recipients' => ['a@example.com', 'b@example.com'], 'next_run_at' => now()]);

    SendScheduledReport::dispatchSync($s->id);

    $report = Report::withoutUserScope()->where('schedule_id', $s->id)->firstOrFail();
    expect($report->status)->toBe('ready')->and($report->format)->toBe('pdf')->and($report->from->format('Y-m-d'))->toBe('2026-10-26')->and($report->to->format('Y-m-d'))->toBe('2026-11-01')
        ->and(Storage::disk('local')->get($report->path))->toStartWith('%PDF');
    Mail::assertSent(ScheduledReportMail::class, 2);
    Mail::assertSent(ScheduledReportMail::class, fn ($m) => $m->hasTo('a@example.com') && ! $m->hasTo('b@example.com') && count($m->attachments()) === 1 && str_contains($m->url, 'signature='));
    expect($s->fresh()->last_error)->toBeNull()->and($s->fresh()->last_run_at)->not->toBeNull();
});

it('Schedules: el enlace del email caduca y el correo respeta el tope diario por usuario', function () {
    Mail::fake();
    $t = makeTemplate($this->user, $this->brand, 'Mensual', [['type' => 'kpis']]);
    $s = ReportSchedule::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'report_template_id' => $t->id, 'frequency' => 'weekly', 'recipients' => ['a@example.com'], 'next_run_at' => now()]);
    SendScheduledReport::dispatchSync($s->id);
    $url = null;
    Mail::assertSent(ScheduledReportMail::class, function ($m) use (&$url) {
        $url = $m->url;

        return true;
    });
    $this->get($url)->assertOk();
    $this->travel(73)->hours();
    $this->get($url)->assertForbidden();
    $this->travelBack();

    config(['reports.mail_daily_limit' => 1]);
    RateLimiter::clear('report-mail:'.$this->user->id);
    $s->update(['recipients' => ['a@example.com', 'b@example.com']]);
    Mail::fake();
    SendScheduledReport::dispatchSync($s->id);
    Mail::assertNothingSent();
    expect($s->fresh()->last_error)->toContain('limite diario');
});

it('Schedules: una programacion cuya marca o plantilla no coincide se desactiva sin enviar', function () {
    Mail::fake();
    $t = makeTemplate($this->user, $this->other);
    $s = ReportSchedule::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'report_template_id' => $t->id, 'frequency' => 'weekly', 'recipients' => ['a@example.com'], 'next_run_at' => now()]);
    SendScheduledReport::dispatchSync($s->id);
    Mail::assertNothingSent();
    expect($s->fresh()->active)->toBeFalse();
});

it('Schedules: el alta y el cambio de destinatarios tienen limite por minuto por usuario', function () {
    $t = makeTemplate($this->user, $this->brand);
    $s = ReportSchedule::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'report_template_id' => $t->id, 'frequency' => 'weekly', 'recipients' => ['a@example.com'], 'next_run_at' => now()->addDay()]);

    $this->actingAs($this->user);
    foreach (range(1, 10) as $i) {
        $this->put(route('report-schedules.update', $s), ['recipients' => ["u$i@example.com"]])->assertRedirect();
    }
    $this->put(route('report-schedules.update', $s), ['recipients' => ['otro@example.com']])->assertStatus(429);
    expect($s->fresh()->recipients)->toBe(['u10@example.com']);

    // Otro usuario tiene su propio cupo.
    $this->actingAs($this->stranger)->put(route('report-schedules.update', $s), ['active' => false])->assertNotFound();
});

it('Ads block: un informe de enero generado en noviembre pide a Meta el rango de enero', function () {
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'refresh_token' => 'TOKEN-A', 'scopes' => ['ads_read']]);
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/me/adaccounts')) {
            return Http::response(['data' => [['id' => 'act_1', 'name' => 'Cuenta A', 'currency' => 'EUR']]]);
        }

        return Http::response(['data' => []]);
    });

    $this->actingAs($this->user)->postJson(route('reports.preview'), ['brand_id' => $this->brand->id, 'from' => '2026-01-01', 'to' => '2026-01-31', 'blocks' => [['type' => 'ads']]])->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/insights') && str_contains(urldecode($r->url()), '"since":"2026-01-01"') && str_contains(urldecode($r->url()), '"until":"2026-01-31"'));
    Http::assertNotSent(fn ($r) => str_contains(urldecode($r->url()), '2026-11-04'));
});

it('Competitors block: dos bloques en el mismo informe calculan la comparacion una sola vez', function () {
    $this->mock(CompetitorService::class, fn ($m) => $m->shouldReceive('comparison')->once()->andReturn(['own' => null, 'competitors' => [], 'range' => ['from' => '2026-10-01', 'to' => '2026-10-31'], 'sort' => 'followers']));

    $this->actingAs($this->user)->postJson(route('reports.preview'), ['brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'blocks' => [['type' => 'competitors'], ['type' => 'competitors', 'title' => 'Otra vez']]])->assertOk();
});

it('Templates: una carrera con el mismo nombre da error de formulario y no un 500', function () {
    ReportTemplate::creating(function (ReportTemplate $t) {
        if ($t->name === 'Carrera') {
            DB::table('report_templates')->insert(['user_id' => $t->user_id, 'brand_id' => $t->brand_id, 'name' => 'Carrera', 'blocks' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    $this->actingAs($this->user)->post(route('report-templates.store'), ['brand_id' => $this->brand->id, 'name' => 'Carrera', 'blocks' => [['type' => 'kpis']], 'range_days' => 30])->assertSessionHasErrors('name');
});

function sentSchedule(array $recipients = ['a@example.com', 'b@example.com']): array
{
    $user = test()->user;
    $t = makeTemplate($user, test()->brand, 'Mensual', [['type' => 'kpis']]);
    $s = ReportSchedule::create(['user_id' => $user->id, 'brand_id' => test()->brand->id, 'report_template_id' => $t->id, 'frequency' => 'weekly', 'recipients' => $recipients, 'next_run_at' => now()]);
    SendScheduledReport::dispatchSync($s->id);

    return [$s, Report::withoutUserScope()->where('schedule_id', $s->id)->firstOrFail()];
}

it('Mail programado: Reply-To del propietario, baja de un clic firmada y texto de por que se recibe', function () {
    Mail::fake();
    [$s] = sentSchedule();

    Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $m) {
        $headers = $m->headers()->text;
        $html = $m->render();

        return $m->hasTo('a@example.com') && $m->hasReplyTo($this->user->email) && str_contains($headers['List-Unsubscribe'], 'signature=') && str_contains($headers['List-Unsubscribe'], urlencode('a@example.com'))
            && ($headers['List-Unsubscribe-Post'] ?? null) === 'List-Unsubscribe=One-Click'
            && str_contains($html, 'Recibes esto porque '.e($this->user->name).' te añadió') && str_contains($html, 'Darme de baja');
    });

    $url = URL::signedRoute('report-schedules.unsubscribe', ['schedule' => $s->id, 'email' => 'a@example.com']);
    $this->get($url.'x')->assertForbidden();
    $this->get(route('report-schedules.unsubscribe.confirm', ['schedule' => $s->id, 'email' => 'a@example.com']))->assertForbidden(); // sin firma
    $this->post($url.'x')->assertForbidden();
    $this->post($url)->assertOk();
    expect($s->fresh()->recipients)->toBe(['b@example.com'])->and($s->fresh()->active)->toBeTrue();

    $this->post(URL::signedRoute('report-schedules.unsubscribe', ['schedule' => $s->id, 'email' => 'b@example.com']))->assertOk();
    expect($s->fresh()->recipients)->toBe([])->and($s->fresh()->active)->toBeFalse();
});

it('Baja programada: el GET firmado solo confirma (los escaneres de correo no dan de baja) y el boton hace POST', function () {
    Mail::fake();
    [$s] = sentSchedule();
    $url = URL::signedRoute('report-schedules.unsubscribe', ['schedule' => $s->id, 'email' => 'a@example.com']);

    $this->get($url)->assertOk()->assertSee('a@example.com')->assertSee('method="POST"', false)->assertSee('action="'.e($url).'"', false);
    $this->get($url)->assertOk();
    expect($s->fresh()->recipients)->toBe(['a@example.com', 'b@example.com']);

    $this->post($url)->assertOk()->assertSee('Ya no recibirás');
    expect($s->fresh()->recipients)->toBe(['b@example.com']);
});

it('Baja programada: el POST one-click de RFC 8058 no pasa por CSRF y el resto de POST si', function () {
    $csrf = new class(app(), app('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false; // en tests el middleware se salta solo; aqui se fuerza
        }
    };
    $run = function (string $path) use ($csrf) {
        $request = Request::create($path, 'POST', ['List-Unsubscribe' => 'One-Click']);
        $request->setLaravelSession(app('session.store'));

        return $csrf->handle($request, fn () => response('ok'));
    };

    expect($run('/report-schedules/5/unsubscribe')->getContent())->toBe('ok');
    expect(fn () => $run('/report-schedules'))->toThrow(TokenMismatchException::class);
});

it('Mail programado: el cupo diario se reserva antes de generar y se libera si el PDF falla o no cabe', function () {
    Mail::fake();
    $key = 'report-mail:'.$this->user->id;
    config(['reports.mail_daily_limit' => 2]);
    [$s] = sentSchedule();
    expect(RateLimiter::attempts($key))->toBe(2);

    // Sin cupo: no se genera ni se reserva nada mas.
    $before = Report::withoutUserScope()->count();
    SendScheduledReport::dispatchSync($s->id);
    expect(RateLimiter::attempts($key))->toBe(2)->and(Report::withoutUserScope()->count())->toBe($before);

    // PDF que falla: el cupo vuelve a su sitio.
    RateLimiter::clear($key);
    config(['reports.driver' => 'inexistente']);
    SendScheduledReport::dispatchSync($s->id);
    expect(RateLimiter::attempts($key))->toBe(0)->and($s->fresh()->last_error)->not->toBeNull();
});

it('Descarga por email: pausar o borrar la programacion invalida los enlaces ya enviados', function () {
    Mail::fake();
    [$s, $report] = sentSchedule();
    $url = $report->signedDownloadUrl(60, $s->id);

    $this->get($url)->assertOk();
    $s->update(['active' => false]);
    $this->get($url)->assertNotFound();
    $s->update(['active' => true]);
    $this->get($url)->assertOk();
    $s->delete();
    $this->get($url)->assertNotFound();

    // Un enlace manual (sin programacion) sigue funcionando como antes.
    $this->get($report->signedDownloadUrl(5))->assertOk();
});
