<?php

namespace Tests\Feature\Studio;

use App\Http\Middleware\LimitStudioDatasetPayload;
use App\Mcp\Tools\ManageStudioDataset;
use App\Models\Brand;
use App\Models\User;
use App\Services\Studio\StudioDatasets;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\McpClient;
use Tests\TestCase;

final class StudioDatasetApiTest extends TestCase
{
    use DatabaseMigrations, McpClient;

    private User $owner;

    private Brand $brand;

    private string $key;

    private string $fixtureDirectory;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('storage/framework/testing/t12-dataset-api/absent.env');
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => storage_path('framework/testing/t12-dataset-api/files-'.bin2hex(random_bytes(8))), 'throw' => true]);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDirectory = storage_path('framework/testing/t12-dataset-api/fixtures-'.bin2hex(random_bytes(16)));
        mkdir($this->fixtureDirectory, 0700, true);
        $this->owner = User::factory()->create();
        $this->brand = Brand::factory()->for($this->owner)->create();
        $this->key = $this->mcpKey($this->owner, ['designs:read', 'designs:write']);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/brands/'.$this->brand->id.'/datasets'.$suffix;
    }

    private function headers(?string $key = null): array
    {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer '.($key ?? $this->key)];
    }

    private function table(): array
    {
        return ['columns' => [['id' => 'c', 'label' => ' =Label ']], 'rows' => [['id' => 'r', 'values' => ['  =1/é  ']], ['id' => 'r2', 'values' => ['']]]];
    }

    public function test_routes_create_list_and_read_safe_metadata_preserve_typed_table(): void
    {
        $created = $this->postJson($this->url(), ['name' => 'Data', 'table' => $this->table()], $this->headers())->assertCreated()->json('dataset');
        $this->assertSame(1, $created['revision']);
        $this->assertArrayNotHasKey('path', $created);
        $this->assertArrayNotHasKey('user_id', $created);
        $this->getJson($this->url(), $this->headers())->assertOk()->assertJsonPath('datasets.0.id', $created['id'])->assertJsonPath('pagination.total', 1);
        $this->getJson($this->url('/'.$created['id']), $this->headers())->assertOk()->assertJsonPath('dataset.name', 'Data');
        $this->assertSame($this->table(), app(StudioDatasets::class)->read($this->owner->id, $this->brand->id, $created['id']));
    }

    public function test_replace_conflict_archive_restore_history_and_isolation(): void
    {
        $id = app(StudioDatasets::class)->create($this->owner->id, $this->brand->id, 'Old', $this->table())->id;
        $this->putJson($this->url('/'.$id), ['name' => 'New', 'table' => $this->table(), 'revision' => 1], $this->headers())->assertOk()->assertJsonPath('dataset.revision', 2);
        $this->putJson($this->url('/'.$id), ['name' => 'Stale', 'table' => $this->table(), 'revision' => 1], $this->headers())->assertConflict();
        $this->deleteJson($this->url('/'.$id), [], $this->headers())->assertOk();
        $this->getJson($this->url('/'.$id.'/revisions?per_page=1'), $this->headers())->assertOk()->assertJsonPath('pagination.total', 2)->assertJsonPath('revisions.0.revision', 2);
        $this->putJson($this->url('/'.$id), ['name' => 'Archived', 'table' => $this->table(), 'revision' => 2], $this->headers())->assertNotFound();
        $this->postJson($this->url('/'.$id.'/restore'), [], $this->headers())->assertOk()->assertJsonPath('dataset.archived_at', null);
        $other = Brand::factory()->for($this->owner)->create();
        $this->getJson('/api/v1/brands/'.$other->id.'/datasets/'.$id, $this->headers())->assertNotFound();
        $this->getJson($this->url('/'.$id), $this->headers($this->mcpKey(User::factory()->create())))->assertNotFound();
    }

    public function test_abilities_unknown_keys_and_object_list_shape_validation(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $read = $this->mcpKey($this->owner, ['designs:read']);
        $this->postJson($this->url(), ['name' => 'Denied', 'table' => $this->table()], $this->headers($read))->assertForbidden();
        $this->postJson($this->url(), ['name' => 'Invalid', 'table' => ['columns' => $this->table()['columns'], 'rows' => (object) []]], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('table');
        $this->postJson($this->url(), ['name' => 'Invalid', 'table' => $this->table(), 'path' => '/secret'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('path');
        $this->getJson($this->url('?per_page=101'), $this->headers())->assertUnprocessable();
    }

    public function test_rows_are_revision_pinned_paginated_and_corruption_returns_503(): void
    {
        $service = app(StudioDatasets::class);
        $dataset = $service->create($this->owner->id, $this->brand->id, 'Data', $this->table());
        $url = $this->url('/'.$dataset->id.'/table');
        $first = $this->getJson($url.'?limit=1', $this->headers())->assertOk()->assertJsonPath('rows.0.values.0', '  =1/é  ')->assertJsonPath('pagination.next_offset', 1)->json();
        $service->replace($this->owner->id, $this->brand->id, $dataset, 1, 'Changed', ['columns' => $this->table()['columns'], 'rows' => []]);
        $this->getJson($url.'?revision=1&offset=1&limit=1', $this->headers())->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('sha256', $first['sha256'])->assertJsonPath('rows.0.id', 'r2')->assertJsonPath('pagination.next_offset', null);
        $snapshot = $dataset->revisions()->where('revision', 1)->firstOrFail();
        Storage::disk('local')->put($snapshot->path, 'broken');
        $this->getJson($url.'?revision=1', $this->headers())->assertStatus(503)->assertDontSee($snapshot->path);
    }

    public function test_rows_budget_counts_unescaped_bytes_without_skipping_or_truncating(): void
    {
        $table = ['columns' => [], 'rows' => []];
        for ($i = 0; $i < 100; $i++) {
            $table['columns'][] = ['id' => 'c'.$i, 'label' => 'é/'];
        }
        for ($i = 0; $i < 10; $i++) {
            $table['rows'][] = ['id' => 'r'.$i, 'values' => array_fill(0, 100, str_repeat('é/', 3000))];
        }
        $dataset = app(StudioDatasets::class)->create($this->owner->id, $this->brand->id, 'Large rows', $table);
        $page = $this->getJson($this->url('/'.$dataset->id.'/table?limit=100'), $this->headers())->assertOk()->json();
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $this->assertLessThanOrEqual(6 * 1024 * 1024, strlen(json_encode($page['rows'], $flags)));
        $this->assertSame(6, count($page['rows']));
        $this->assertSame(6, $page['pagination']['next_offset']);
        $this->assertSame($table['rows'][5], $page['rows'][5]);
        $this->getJson($this->url('/'.$dataset->id.'/table?offset=6'), $this->headers())->assertOk()->assertJsonPath('rows.0.id', 'r6');
    }

    public function test_json_body_stream_is_bounded_before_decode_without_trusting_content_length(): void
    {
        $path = tempnam($this->fixtureDirectory, 'body');
        $stream = fopen($path, 'w+b');
        $chunk = str_repeat('x', 1024 * 1024);
        for ($i = 0; $i < 54; $i++) {
            fwrite($stream, $chunk);
        }
        unset($chunk);
        rewind($stream);
        try {
            $request = Request::create($this->url(), 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => '1', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->key], $stream);
            $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
            $this->assertSame(413, $response->getStatusCode());
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function test_export_streams_safe_default_faithful_text_and_typed_json(): void
    {
        $id = app(StudioDatasets::class)->create($this->owner->id, $this->brand->id, 'Data', $this->table())->id;
        $url = $this->url('/'.$id.'/export');
        $safe = $this->get($url, $this->headers())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame("' =Label \r\n'  =1/é  \r\n'\r\n", $safe->streamedContent());
        $text = $this->get($url.'?mode=text', $this->headers())->assertOk()->streamedContent();
        $this->assertSame(" =Label \r\n  =1/é  \r\n\r\n", $text);
        $json = $this->get($url.'?format=json', $this->headers())->assertOk()->streamedContent();
        $this->assertSame($this->table(), json_decode($json, true));
        $this->getJson($url.'?format=json&header=true', $this->headers())->assertUnprocessable();
        $this->getJson($url.'?format=csv&mode=other', $this->headers())->assertUnprocessable();
        $this->assertSame($this->table(), app(StudioDatasets::class)->read($this->owner->id, $this->brand->id, $id));
    }

    public function test_multipart_import_creates_replaces_and_preserves_json_identity(): void
    {
        $file = UploadedFile::fake()->createWithContent('data.csv', "Label\r\n  =1/é  \r\n");
        $created = $this->post($this->url('/import'), ['name' => 'CSV', 'format' => 'csv', 'file' => $file], $this->headers() + ['Accept' => 'application/json'])->assertCreated()->json('dataset');
        $json = UploadedFile::fake()->createWithContent('data.json', json_encode($this->table()));
        $this->post($this->url('/'.$created['id'].'/import'), ['name' => 'Typed', 'format' => 'json', 'revision' => 1, 'file' => $json], $this->headers() + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('dataset.revision', 2)->assertJsonMissingPath('table');
        $this->assertSame($this->table(), app(StudioDatasets::class)->read($this->owner->id, $this->brand->id, $created['id']));
        $bad = UploadedFile::fake()->createWithContent('data.json', '{"columns":[{"id":"c","label":""}],"rows":{}}');
        $this->post($this->url('/import'), ['name' => 'Invalid', 'format' => 'json', 'file' => $bad], $this->headers() + ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('table');
        $this->post($this->url('/import'), ['name' => 'Invalid', 'format' => 'json', 'header' => true, 'file' => $json], $this->headers() + ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('header');
    }

    public function test_mcp_dataset_is_discovered_and_shares_abilities_ownership_and_facade(): void
    {
        $names = [];
        $cursor = null;
        do {
            $page = $this->rpc($this->key, 'tools/list', $cursor ? ['cursor' => $cursor] : [])->assertOk()->json('result');
            $names = [...$names, ...array_column($page['tools'], 'name')];
            $cursor = $page['nextCursor'] ?? null;
        } while ($cursor !== null);
        $this->assertContains('manage_studio_dataset', $names);
        [$error, $created] = $this->callTool($this->key, 'manage_studio_dataset', ['action' => 'create', 'brand_id' => $this->brand->id, 'name' => 'MCP', 'table' => $this->table()]);
        $this->assertFalse($error);
        [$denied] = $this->callTool($this->mcpKey($this->owner, ['designs:read']), 'manage_studio_dataset', ['action' => 'archive', 'brand_id' => $this->brand->id, 'dataset_id' => $created['dataset']['id']]);
        $this->assertTrue($denied);
        [$readError, $rows] = $this->callTool($this->key, 'manage_studio_dataset', ['action' => 'rows', 'brand_id' => $this->brand->id, 'dataset_id' => $created['dataset']['id']]);
        $this->assertFalse($readError);
        $this->assertSame($this->table()['rows'], $rows['rows']);
        [$exportError, $download] = $this->callTool($this->key, 'manage_studio_dataset', ['action' => 'export', 'brand_id' => $this->brand->id, 'dataset_id' => $created['dataset']['id'], 'format' => 'json']);
        $this->assertFalse($exportError);
        $this->get($download['download_url'], $this->headers())->assertOk();
    }

    public function test_public_upload_accepts_real_25000000_byte_csv_and_paginates(): void
    {
        $path = tempnam($this->fixtureDirectory, 'csv');
        $stream = fopen($path, 'wb');
        $row = implode(',', array_fill(0, 100, str_repeat('x', 249)))."\n";
        for ($i = 0; $i < 1000; $i++) {
            fwrite($stream, $row);
        }
        fclose($stream);
        $this->assertSame(25000000, filesize($path));
        try {
            $file = new UploadedFile($path, 'large.csv', 'text/csv', null, true);
            $created = $this->post($this->url('/import'), ['name' => '25 MB', 'format' => 'csv', 'header' => 'false', 'file' => $file], $this->headers() + ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('table')->json('dataset');
            $this->assertSame(1000, $created['row_count']);
            $this->getJson($this->url('/'.$created['id'].'/table?offset=999&limit=1'), $this->headers())->assertOk()->assertJsonPath('rows.0.id', 'row_1000')->assertJsonPath('pagination.next_offset', null);
            fwrite(STDOUT, ' allocator_peak_bytes='.memory_get_peak_usage(true)."\n");
        } finally {
            unlink($path);
        }
    }

    public function test_web_routes_require_verified_owner_and_csrf_with_preserved_strings(): void
    {
        $url = '/brands/'.$this->brand->id.'/studio/datasets';
        $this->actingAs($this->owner)->getJson($url)->assertOk();
        $this->app['env'] = 'local';
        $this->postJson($url, ['name' => 'Web', 'table' => $this->table()])->assertStatus(419);
        $this->withSession(['_token' => 'dataset-csrf'])->postJson($url, ['_token' => 'dataset-csrf', 'name' => 'Web', 'table' => $this->table()])->assertCreated();
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->getJson($url)->assertForbidden();
    }

    public function test_mcp_rejects_explicit_null_optional_pagination_and_revision(): void
    {
        [$error] = $this->callTool($this->key, 'manage_studio_dataset', ['action' => 'list', 'brand_id' => $this->brand->id, 'page' => null]);
        $this->assertTrue($error);
        $dataset = app(StudioDatasets::class)->create($this->owner->id, $this->brand->id, 'Data', $this->table());
        [$error] = $this->callTool($this->key, 'manage_studio_dataset', ['action' => 'rows', 'brand_id' => $this->brand->id, 'dataset_id' => $dataset->id, 'revision' => null]);
        $this->assertTrue($error);
    }

    public function test_http_and_mcp_reject_object_collections_and_invalid_mutations_without_writes(): void
    {
        $service = app(StudioDatasets::class);
        $dataset = $service->create($this->owner->id, $this->brand->id, 'Original', $this->table());
        $files = Storage::disk('local')->allFiles();
        foreach ([0, -1, null, 1.5] as $revision) {
            $this->putJson($this->url('/'.$dataset->id), ['name' => 'Invalid', 'table' => $this->table(), 'revision' => $revision], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('revision');
        }
        $bad = ['columns' => (object) ['0' => (object) $this->table()['columns'][0]], 'rows' => []];
        $this->postJson($this->url(), ['name' => 'Invalid', 'table' => $bad], $this->headers())->assertUnprocessable();
        [$error] = $this->callTool($this->key, 'manage_studio_dataset', ['action' => 'create', 'brand_id' => $this->brand->id, 'name' => 'Invalid', 'table' => ['columns' => $this->table()['columns'], 'rows' => (object) []]]);
        $this->assertTrue($error);
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(1, $dataset->fresh()->revision);
    }

    public function test_invalid_json_without_length_is_rejected_and_guard_preserves_request_resolvers(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, '{invalid');
        rewind($stream);
        $request = Request::create($this->url(), 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->key], $stream);
        $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
        $this->assertSame(422, $response->getStatusCode());
        fclose($stream);
        $probe = Request::create($this->url(), 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":" b "}');
        $probe->setUserResolver(fn () => $this->owner);
        $probe->setRouteResolver(fn () => 'route-sentinel');
        $probe->headers->set('X-Test-Header', 'sentinel');
        $result = (new LimitStudioDatasetPayload)->handle($probe, function ($same) use ($probe) {
            $this->assertSame($probe, $same);
            $this->assertSame($this->owner, $same->user());
            $this->assertSame('route-sentinel', $same->route());
            $this->assertSame('sentinel', $same->header('X-Test-Header'));
            $this->assertSame(' b ', $same->json('a'));

            return response('ok');
        });
        $this->assertSame(200, $result->getStatusCode());
    }

    public function test_corrupt_export_fails_before_headers_and_mcp_exposes_safe_status(): void
    {
        $dataset = app(StudioDatasets::class)->create($this->owner->id, $this->brand->id, 'Original', $this->table());
        $snapshot = $dataset->revisions()->firstOrFail();
        Storage::disk('local')->delete($snapshot->path);
        $this->getJson($this->url('/'.$dataset->id.'/export'), $this->headers())->assertStatus(503)->assertDontSee($snapshot->path)->assertHeaderMissing('Content-Disposition');
        [$error, $message] = $this->callTool($this->key, 'manage_studio_dataset', ['action' => 'export', 'brand_id' => $this->brand->id, 'dataset_id' => $dataset->id]);
        $this->assertTrue($error);
        $this->assertStringContainsString('503', $message);
        $this->assertStringNotContainsString($snapshot->path, $message);
    }

    public function test_mcp_large_escaped_typed_json_import_with_explicit_memory_profile(): void
    {
        if (getenv('T12_DATASET_MCP_LARGE') !== '1') {
            $this->markTestSkipped('Opt-in single 51 MB typed JSON / 153 MB escaped MCP envelope probe.');
        }
        $path = tempnam($this->fixtureDirectory, 'rpc');
        $stream = fopen($path, 'w+b');
        fwrite($stream, '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"manage_studio_dataset","arguments":{"action":"import","brand_id":'.$this->brand->id.',"name":"Large MCP","format":"json","text":"');
        $columns = [];
        for ($i = 0; $i < 100; $i++) {
            $columns[] = ['id' => 'c'.$i, 'label' => ''];
        }
        $writeText = static function (string $value) use ($stream): void {
            fwrite($stream, substr(json_encode($value, JSON_THROW_ON_ERROR), 1, -1));
        };
        $writeText('{"columns":'.json_encode($columns).',"rows":[');
        $cell = str_repeat('é', 5000);
        $typedBytes = 0;
        for ($r = 0; $r < 51; $r++) {
            $record = ($r ? ',' : '').json_encode(['id' => 'r'.$r, 'values' => array_fill(0, 100, $cell)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $typedBytes += strlen($record);
            $writeText($record);
        }
        $writeText(']}');
        fwrite($stream, '"}}}');
        unset($record, $cell, $writeText);
        $envelopeBytes = ftell($stream);
        rewind($stream);
        try {
            $request = Request::create('/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->key], $stream);
            $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
            $this->assertSame(200, $response->getStatusCode(), substr($response->getContent(), 0, 200));
            $rpc = json_decode($response->getContent(), true);
            $this->assertFalse($rpc['result']['isError'] ?? false);
            $metadata = json_decode($rpc['result']['content'][0]['text'], true);
            $this->assertSame(51, $metadata['dataset']['row_count']);
            $this->assertSame(100, $metadata['dataset']['column_count']);
            $this->assertArrayNotHasKey('table', $metadata);
            fwrite(STDOUT, ' typed_row_bytes='.$typedBytes.' envelope_bytes='.$envelopeBytes.' memory_limit='.ini_get('memory_limit').' allocator_peak_bytes='.memory_get_peak_usage(true)."\n");
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function test_invalid_upload_and_storage_failure_do_not_mutate_prior_revision(): void
    {
        $service = app(StudioDatasets::class);
        $dataset = $service->create($this->owner->id, $this->brand->id, 'Original', $this->table());
        $this->post($this->url('/import'), ['name' => 'Bad', 'format' => 'csv', 'file' => null], $this->headers() + ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $files = Storage::disk('local')->allFiles();
        $disk = Storage::disk('local');
        $failed = \Mockery::mock($disk)->makePartial();
        $failed->shouldReceive('put')->andThrow(new \RuntimeException('private-path-sentinel'));
        Storage::shouldReceive('disk')->with('local')->andReturn($failed);
        $this->app['config']->set('app.debug', false);
        $this->putJson($this->url('/'.$dataset->id), ['name' => 'Must rollback', 'table' => $this->table(), 'revision' => 1], $this->headers())->assertStatus(500)->assertDontSee('private-path-sentinel');
        $this->assertSame(1, $dataset->fresh()->revision);
        $this->assertSame(1, $dataset->revisions()->count());
        $this->assertSame($files, $disk->allFiles());
    }

    public function test_dataset_audit_uses_dataset_id_and_malformed_id_does_not_crash(): void
    {
        [$error] = $this->callTool($this->key, 'manage_studio_dataset', ['action' => 'get', 'brand_id' => $this->brand->id, 'dataset_id' => []]);
        $this->assertTrue($error);
        $dataset = app(StudioDatasets::class)->create($this->owner->id, $this->brand->id, 'Original', $this->table());
        [$error] = $this->callTool($this->key, 'manage_studio_dataset', ['action' => 'get', 'brand_id' => $this->brand->id, 'dataset_id' => $dataset->id]);
        $this->assertFalse($error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'manage_studio_dataset', 'resource' => 'studio_dataset', 'resource_id' => $dataset->id, 'outcome' => 'ok']);
    }

    public function test_api_rejects_csrf_field_as_an_unknown_key(): void
    {
        $this->postJson($this->url(), ['name' => 'No extra keys', 'table' => $this->table(), '_token' => 'ignored'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('_token');
    }

    public function test_read_operations_reject_unknown_json_body_fields(): void
    {
        $server = $this->transformHeadersToServerVars($this->headers() + ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        $this->call('GET', $this->url(), [], [], [], $server, '{"disk":"local"}')->assertUnprocessable()->assertJsonValidationErrors('disk');
    }

    public function test_mcp_schema_is_closed_and_declares_typed_cell_string_limit(): void
    {
        $schema = (new ManageStudioDataset)->toArray()['inputSchema'];
        $this->assertFalse($schema['additionalProperties'] ?? true);
        $this->assertContains('brand_id', $schema['required']);
        $cell = $schema['properties']['table']['properties']['rows']['items']['properties']['values']['items'];
        $this->assertSame(10000, $cell['anyOf'][0]['maxLength'] ?? null);
    }

    public function test_mcp_import_replace_history_archive_restore_and_literal_paging(): void
    {
        $args = ['brand_id' => $this->brand->id];
        [$error, $created] = $this->callTool($this->key, 'manage_studio_dataset', $args + ['action' => 'import', 'name' => 'Literal %', 'format' => 'tsv', 'header' => false, 'text' => "  001  \t=1\n"]);
        $this->assertFalse($error);
        $args['dataset_id'] = $created['dataset']['id'];
        [$error, $replaced] = $this->callTool($this->key, 'manage_studio_dataset', $args + ['action' => 'replace', 'name' => 'Literal %', 'revision' => 1, 'table' => $this->table()]);
        $this->assertFalse($error);
        $this->assertSame(2, $replaced['dataset']['revision']);
        [$error, $history] = $this->callTool($this->key, 'manage_studio_dataset', $args + ['action' => 'history', 'per_page' => 1, 'page' => 2]);
        $this->assertFalse($error);
        $this->assertSame(1, $history['revisions'][0]['revision']);
        $this->assertArrayNotHasKey('path', $history['revisions'][0]);
        [$error] = $this->callTool($this->key, 'manage_studio_dataset', $args + ['action' => 'archive']);
        $this->assertFalse($error);
        [$error] = $this->callTool($this->key, 'manage_studio_dataset', $args + ['action' => 'restore']);
        $this->assertFalse($error);
        [$error, $list] = $this->callTool($this->key, 'manage_studio_dataset', ['brand_id' => $this->brand->id, 'action' => 'list', 'query' => '%']);
        $this->assertFalse($error);
        $this->assertSame(1, $list['pagination']['total']);
    }

    public function test_typed_numeric_null_unicode_roundtrip_and_private_stream_headers(): void
    {
        $table = ['columns' => [['id' => 'c', 'label' => " \t=Label/é "]], 'rows' => [['id' => 'r1', 'values' => [0.0]], ['id' => 'r2', 'values' => [null]], ['id' => 'r3', 'values' => ["'=1\n/é\t"]]]];
        $headers = $this->transformHeadersToServerVars($this->headers() + ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        $id = $this->call('POST', $this->url(), [], [], [], $headers, json_encode(['name' => 'Typed', 'table' => $table], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR))->assertCreated()->json('dataset.id');
        $response = $this->getJson($this->url('/'.$id.'/table'), $this->headers())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('[0.0]', $response->getContent());
        $this->assertSame($table['rows'], $response->json('rows'));
        $export = $this->get($this->url('/'.$id.'/export?format=json'), $this->headers())->assertOk()->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $this->assertStringContainsString('no-store', $export->headers->get('Cache-Control'));
        $this->assertStringContainsString('dataset-'.$id.'-r1.json', $export->headers->get('Content-Disposition'));
        $this->assertSame($table, json_decode($export->streamedContent(), true));
    }
}
