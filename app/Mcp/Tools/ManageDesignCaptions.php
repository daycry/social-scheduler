<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\StudioCaptionData;
use App\Services\Studio\StudioCaptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_design_captions')]
#[Description('Subtítulos propios del diseño: get paginado50/máximo100, export SRT/VTT texto por revisión, import texto UTF-8 hasta512KiB; sin mode añade a la pista, mode:replace reemplaza solo de forma explícita. replace/upsert/delete/offset/style/clear con revision optimista. get/export requieren designs:read, cambios designs:write. v1 requiere activate_temporal:true explícito. Máximo5000cues,8simultáneos,500caracteres/cue. No URLs/rutas/base64/HTML, no ASR/traducción ni publicación. font_id debe pertenecer a su marca. Sigue next_page usando la revision devuelta; no se trunca silenciosamente.')]
class ManageDesignCaptions extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::DESIGNS_WRITE;
    }

    protected function abilityFor(Request $request): string
    {
        return in_array($request->get('action'), ['get', 'export'], true) ? Abilities::DESIGNS_READ : Abilities::DESIGNS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'design';
    }

    public function schema(JsonSchema $schema): array
    {
        $cue = $schema->object(['id' => $schema->string()->required(), 'start' => $schema->number()->required(), 'end' => $schema->number()->required(), 'text' => $schema->string()->max(500)->required()]);

        return ['design_id' => $schema->integer()->min(1)->required(), 'action' => $schema->string()->enum(['get', 'export', 'import', 'replace', 'upsert', 'delete', 'offset', 'style', 'clear'])->required(),
            'revision' => $schema->integer()->min(1), 'page' => $schema->integer()->min(1)->max(5001), 'per_page' => $schema->integer()->min(1)->max(100), 'query' => $schema->string()->max(120),
            'activate_temporal' => $schema->boolean(), 'format' => $schema->string()->enum(['srt', 'vtt']), 'text' => $schema->string(), 'mode' => $schema->string()->enum(['replace', 'append'])->description('Por defecto append conserva la pista. replace requiere selección explícita.'),
            'captions' => $schema->object(['style' => $schema->object()->required(), 'cues' => $schema->array()->items($cue)->required()]), 'cue' => $cue,
            'ids' => $schema->array()->items($schema->string()), 'seconds' => $schema->number(), 'style' => $schema->object()];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['design_id' => ['required', 'integer', 'min:1'], 'action' => ['required', 'in:get,export,import,replace,upsert,delete,offset,style,clear']]);
        $design = $this->owned(Design::class, $user, $data['design_id'], 'Diseño', masculine: true);
        $payload = array_diff_key($request->all(), array_flip(['design_id']));
        $captions = app(StudioCaptions::class);
        if ($data['action'] === 'get') {
            StudioCaptionData::keys($payload, ['action', 'revision', 'page', 'per_page', 'query'], 'consulta');

            return $captions->get($user, $design, array_diff_key($payload, ['action' => true]));
        }
        if ($data['action'] === 'export') {
            StudioCaptionData::keys($payload, ['action', 'revision', 'format'], 'exportación');

            return $captions->export($user, $design, array_diff_key($payload, ['action' => true]));
        }

        return $captions->mutate($user, $design, $payload);
    }
}
