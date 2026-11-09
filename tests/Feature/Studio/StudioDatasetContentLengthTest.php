<?php

namespace Tests\Feature\Studio;

use App\Models\User;
use App\Services\Studio\StudioDatasets;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class StudioDatasetContentLengthTest extends TestCase
{
    public function createApplication()
    {
        $root = dirname(__DIR__, 3).'/storage/framework/testing/t12-json-content-length/run-'.bin2hex(random_bytes(8));
        mkdir($root, 0700, true);
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->useEnvironmentPath($root);
        $app->loadEnvironmentFrom('absent.env');
        $app->useStoragePath($root);
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($root): void {
            $app['config']->set([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null,
                'filesystems.disks.local' => ['driver' => 'local', 'root' => $root.'/files', 'throw' => true],
                'session.driver' => 'array', 'cache.default' => 'array', 'cache.limiter' => 'array',
                'logging.default' => 'null', 'queue.default' => 'sync', 'mail.default' => 'array',
                'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            ]);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
        });
        DB::table('users')->insert([['id' => 1], ['id' => 2]]);
        DB::table('brands')->insert([['id' => 1, 'user_id' => 1], ['id' => 2, 'user_id' => 2], ['id' => 3, 'user_id' => 1]]);
        (require base_path('database/migrations/2026_10_19_223906_create_personal_access_tokens_table.php'))->up();
        (require base_path('database/migrations/2026_11_06_010000_create_studio_datasets.php'))->up();
    }

    private function headers(int $actor = 1): array
    {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer '.User::findOrFail($actor)->createToken('length-test', ['designs:read'])->plainTextToken];
    }

    private function table(): array
    {
        return ['columns' => [['id' => 'c', 'label' => "雪/é\tlabel\n"]], 'rows' => array_map(
            fn ($value, $i) => ['id' => 'r'.$i, 'values' => [$value]],
            ["雪/é\t\n\"\\", 0.0, -0.0, 12, null], range(0, 4)
        )];
    }

    public function test_json_content_length_matches_delivered_pinned_canonical_bytes(): void
    {
        $service = app(StudioDatasets::class);
        $dataset = $service->create(1, 1, 'Canonical', $this->table());
        $revision = $dataset->revisions()->firstOrFail();
        $service->replace(1, 1, $dataset, 1, 'Changed', ['columns' => $this->table()['columns'], 'rows' => []]);
        $response = $this->getJson('/api/v1/brands/1/datasets/'.$dataset->id.'/export?format=json&revision=1', $this->headers())->assertOk();
        $body = $response->streamedContent();
        $this->assertSame((string) strlen($body), $response->headers->get('Content-Length'));
        $this->assertSame((string) $revision->json_bytes, $response->headers->get('Content-Length'));
        $this->assertSame($revision->sha256, hash('sha256', $body));
        $this->assertSame(Storage::disk('local')->get($revision->path), $body);
        $this->assertSame($this->table(), json_decode($body, true));
        $current = $dataset->revisions()->where('revision', 2)->firstOrFail();
        $response = $this->getJson('/api/v1/brands/1/datasets/'.$dataset->id.'/export?format=json', $this->headers())->assertOk();
        $body = $response->streamedContent();
        $this->assertSame((string) strlen($body), $response->headers->get('Content-Length'));
        $this->assertSame((string) $current->json_bytes, $response->headers->get('Content-Length'));
        $this->assertSame($current->sha256, hash('sha256', $body));
    }

    public function test_delimited_exports_keep_their_body_without_json_length(): void
    {
        $dataset = app(StudioDatasets::class)->create(1, 1, 'Delimited', ['columns' => [['id' => 'c', 'label' => 'Label'], ['id' => 'n', 'label' => 'Number']], 'rows' => [['id' => 'r', 'values' => ['text', 0.0]]]]);
        foreach (['csv' => ',', 'tsv' => "\t"] as $format => $delimiter) {
            $response = $this->getJson('/api/v1/brands/1/datasets/'.$dataset->id.'/export?format='.$format, $this->headers())->assertOk();
            $response->assertHeaderMissing('Content-Length');
            $this->assertSame("'Label".$delimiter."'Number\r\n'text".$delimiter."0.0\r\n", $response->streamedContent());
        }
    }

    public function test_export_authorization_revision_and_corruption_errors_are_preserved(): void
    {
        $dataset = app(StudioDatasets::class)->create(1, 1, 'Owned', $this->table());
        $suffix = '/datasets/'.$dataset->id.'/export?format=json';
        $this->getJson('/api/v1/brands/1'.$suffix)->assertUnauthorized();
        $this->getJson('/api/v1/brands/1'.$suffix, $this->headers(2))->assertNotFound();
        foreach ([2, 3] as $brand) {
            $this->getJson('/api/v1/brands/'.$brand.$suffix, $this->headers())->assertNotFound();
        }
        $this->getJson('/api/v1/brands/1'.$suffix.'&revision=2', $this->headers())->assertNotFound();
        $revision = $dataset->revisions()->firstOrFail();
        DB::table('studio_dataset_revisions')->where('id', $revision->id)->update(['json_bytes' => -1]);
        $this->getJson('/api/v1/brands/1'.$suffix, $this->headers())->assertStatus(503)->assertDontSee($revision->path);
        DB::table('studio_dataset_revisions')->where('id', $revision->id)->update(['json_bytes' => $revision->json_bytes]);
        Storage::disk('local')->put($revision->path, str_repeat('x', $revision->json_bytes));
        $this->getJson('/api/v1/brands/1'.$suffix, $this->headers())->assertStatus(503)->assertDontSee($revision->path);
    }
}
