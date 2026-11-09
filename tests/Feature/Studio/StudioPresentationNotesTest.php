<?php

namespace Tests\Feature\Studio;

use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\Studio\DesignRevision;
use App\Models\User;
use App\Services\Studio\StudioDocument;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\McpClient;
use Tests\Support\StudioPresentationTestApplication;
use Tests\TestCase;

final class StudioPresentationNotesTest extends TestCase
{
    use DatabaseMigrations, McpClient;

    public function createApplication()
    {
        return StudioPresentationTestApplication::create();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            StudioPresentationTestApplication::restoreEnvironment();
        }
    }

    private function document(int $version, mixed $notes = null, bool $present = true): array
    {
        $doc = StudioDocument::blank(320, 180);
        if ($version === 2) {
            $doc['schema_version'] = 2;
            $doc['timeline'] = ['fps' => 30, 'audio_tracks' => []];
        }
        if ($present) {
            $doc['pages'][0]['notes'] = $notes;
        }

        return $doc;
    }

    private function identity(): array
    {
        $owner = User::factory()->create();
        $brand = Brand::factory()->for($owner)->create();

        return [$owner, $brand, $this->mcpKey($owner, ['designs:read', 'designs:write'])];
    }

    private function headers(string $key): array
    {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer '.$key];
    }

    public function test_web_notes_roundtrip_duplicate_and_revision_restore_are_exact_for_v1_and_v2(): void
    {
        [$owner, $brand] = $this->identity();
        $this->actingAs($owner);
        foreach ([1, 2] as $version) {
            foreach (['', "  \r\n🎤 e\u{0301}\n\t  ", str_repeat('🎤', 20000)] as $notes) {
                $doc = $this->document($version, $notes);
                $created = $this->postJson('/brands/'.$brand->id.'/studio/designs', ['name' => 'Notes', 'document' => $doc])->assertCreated()->json('design');
                $url = '/studio/designs/'.$created['id'];
                $this->getJson($url)->assertOk()->assertJsonPath('design.document', $doc);
                $this->postJson($url.'/duplicate')->assertCreated()->assertJsonPath('design.document', $doc);
                $changed = $doc;
                $changed['pages'][0]['notes'] = 'new revision';
                $this->patchJson($url, ['name' => 'Edited', 'document' => $changed, 'revision' => 1])->assertOk();
                $snapshot = DesignRevision::withoutUserScope()->where('design_id', $created['id'])->where('revision', 1)->firstOrFail();
                $this->assertSame($doc, $snapshot->document);
                $this->patchJson($url, ['name' => 'Recovered', 'document' => $snapshot->document, 'revision' => 2])->assertOk()->assertJsonPath('design.document', $doc);
                $this->getJson($url)->assertOk()->assertJsonPath('design.document', $doc);
            }
        }
    }

    public function test_api_and_mcp_create_update_reopen_and_duplicate_keep_notes(): void
    {
        [$owner, $brand, $key] = $this->identity();
        foreach ([1, 2] as $version) {
            $doc = $this->document($version, " \r\n🎤 <b>plain</b>  ");
            $created = $this->postJson('/api/v1/brands/'.$brand->id.'/designs', ['name' => 'API notes', 'document' => $doc], $this->headers($key))->assertCreated()->json();
            $this->getJson('/api/v1/designs/'.$created['id'], $this->headers($key))->assertOk()->assertJsonPath('document', $doc);
            [$error, $mcp] = $this->callTool($key, 'create_design', ['brand_id' => $brand->id, 'name' => 'MCP notes', 'document' => $doc]);
            $this->assertFalse($error);
            $this->assertSame($doc, $mcp['document']);
            $doc['pages'][0]['notes'] = '';
            [$error, $saved] = $this->callTool($key, 'update_design', ['design_id' => $mcp['id'], 'revision' => 1, 'name' => 'Updated', 'document' => $doc]);
            $this->assertFalse($error);
            $this->assertSame($doc, $saved['document']);
            [$error, $read] = $this->callTool($key, 'get_design', ['design_id' => $mcp['id']]);
            $this->assertFalse($error);
            $this->assertSame($doc, $read['document']);
            [$error, $copy] = $this->callTool($key, 'manage_design', ['action' => 'duplicate', 'design_id' => $mcp['id']]);
            $this->assertFalse($error);
            $this->assertSame($doc, $copy['document']);
            $this->patchJson('/api/v1/designs/'.$created['id'], ['name' => 'Updated API', 'document' => $doc, 'revision' => 1], $this->headers($key))->assertOk()->assertJsonPath('document', $doc);
            $this->getJson('/api/v1/designs/'.$created['id'], $this->headers($key))->assertOk()->assertJsonPath('document', $doc);
        }
    }

    public function test_invalid_types_and_20001_codepoints_are_rejected_by_web_api_and_mcp_without_writes(): void
    {
        [$owner, $brand, $key] = $this->identity();
        foreach ([1, 2] as $version) {
            foreach ([null, false, true, 0, 3.5, [], (object) [], ['text'], str_repeat('🎤', 20001)] as $notes) {
                $doc = $this->document($version, $notes);
                $this->actingAs($owner);
                $this->postJson('/brands/'.$brand->id.'/studio/designs', ['name' => 'Invalid', 'document' => $doc])->assertUnprocessable()->assertJsonValidationErrors('document.pages.0.notes');
                $this->postJson('/api/v1/brands/'.$brand->id.'/designs', ['name' => 'Invalid', 'document' => $doc], $this->headers($key))->assertUnprocessable()->assertJsonValidationErrors('document.pages.0.notes');
                [$error] = $this->callTool($key, 'create_design', ['brand_id' => $brand->id, 'name' => 'Invalid', 'document' => $doc]);
                $this->assertTrue($error);
            }
        }
        $this->assertSame(0, Design::withoutUserScope()->count());
        $this->assertSame(0, DesignRevision::withoutUserScope()->count());
    }

    public function test_missing_notes_remain_absent_and_total_document_cap_still_applies(): void
    {
        [$owner, $brand] = $this->identity();
        $this->actingAs($owner);
        foreach ([1, 2] as $version) {
            $doc = $this->document($version, present: false);
            $created = $this->postJson('/brands/'.$brand->id.'/studio/designs', ['name' => 'Legacy', 'document' => $doc])->assertCreated()->json('design');
            $this->assertSame($doc, $created['document']);
            $doc = $this->document($version, str_repeat('🎤', 20000));
            $page = $doc['pages'][0];
            $doc['pages'] = [];
            for ($i = 0; $i < 30; $i++) {
                $doc['pages'][] = array_replace($page, ['id' => 'page_'.$i]);
            }
            $this->postJson('/brands/'.$brand->id.'/studio/designs', ['name' => 'Too large', 'document' => $doc])->assertUnprocessable()->assertJsonValidationErrors('document');
        }
    }
}
