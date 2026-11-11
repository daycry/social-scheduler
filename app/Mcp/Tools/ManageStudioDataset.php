<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\Studio\StudioDatasetActions;
use App\Services\Studio\StudioDatasetInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_studio_dataset')]
#[Description('Datasets privados y versionados por brand_id obligatorio. list/get/rows/history/export requieren designs:read; create/replace/import/archive/restore designs:write. replace/import de recurso exigen revision positiva; conflicto409. table conserva IDs/tipos; rows pagina hasta100 registros y6MiB con revision/SHA fijos. import recibe textCSV/TSV hasta25.000.000 bytes oJSON tipado hasta52.000.000 si el transporte permite el envelope; para clientes limitados usa upload multipart autenticado /api/v1/brands/{brand}/datasets/import (o/{dataset}/import). No hay protocolo de chunks ni XLSX. Export devuelve enlace privado API que requiere Bearer designs:read; csv/tsv usan spreadsheet seguro por defecto, mode:text es fiel y permite interpretación externa. JSON no admite header/mode. Nunca rutas, URLs remotas ni perfiles de cliente. No publica.')]
final class ManageStudioDataset extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::DESIGNS_WRITE;
    }

    protected function abilityFor(Request $request): string
    {
        return in_array($request->get('action'), ['list', 'get', 'rows', 'history', 'export'], true) ? Abilities::DESIGNS_READ : Abilities::DESIGNS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'studio_dataset';
    }

    protected function resultResponse(array $result): Response
    {
        return Response::text(json_encode($result, StudioDatasetInput::JSON_FLAGS));
    }

    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    public function schema(JsonSchema $schema): array
    {
        $id = fn () => $schema->string()->min(1)->max(64)->pattern('^[A-Za-z0-9_-]+$');

        return [
            'action' => $schema->string()->enum(['list', 'get', 'create', 'replace', 'rows', 'history', 'import', 'export', 'archive', 'restore'])->required(),
            'brand_id' => $schema->integer()->min(1)->required(), 'dataset_id' => $schema->integer()->min(1),
            'name' => $schema->string()->min(1)->max(255), 'revision' => $schema->integer()->min(1),
            'query' => $schema->string()->max(120), 'page' => $schema->integer()->min(1), 'per_page' => $schema->integer()->min(1)->max(100),
            'offset' => $schema->integer()->min(0)->max(1000), 'limit' => $schema->integer()->min(1)->max(100),
            'format' => $schema->string()->enum(['csv', 'tsv', 'json']), 'mode' => $schema->string()->enum(['spreadsheet', 'text']), 'header' => $schema->boolean(),
            'text' => $schema->string()->max(52000000),
            'table' => $schema->object([
                'columns' => $schema->array()->min(1)->max(100)->items($schema->object(['id' => $id()->required(), 'label' => $schema->string()->max(500)->required()])->withoutAdditionalProperties())->required(),
                'rows' => $schema->array()->max(1000)->items($schema->object(['id' => $id()->required(), 'values' => $schema->array()->max(100)->items($schema->anyOf([$schema->string()->max(10000)->nullable(), $schema->number()]))->required()])->withoutAdditionalProperties())->required(),
            ])->withoutAdditionalProperties(),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $input = $request->all();
        // The MCP SDK uses associative decoding. Re-read typed table arguments as
        // objects from the already bounded HTTP body so {} cannot become [].
        if (array_key_exists('table', $input) && request()->is('mcp')) {
            $envelope = StudioDatasetInput::objectJson(request()->getContent(), 'body');
            $params = $envelope['params'] ?? null;
            if ($params instanceof \stdClass && ($params->name ?? null) === 'manage_studio_dataset' && ($params->arguments ?? null) instanceof \stdClass) {
                $input = get_object_vars($params->arguments);
            }
        }
        $action = StudioDatasetInput::text($input, 'action', 7);
        if (! in_array($action, ['list', 'get', 'create', 'replace', 'rows', 'history', 'import', 'export', 'archive', 'restore'], true)) {
            StudioDatasetInput::fail('action', 'Unsupported dataset action.');
        }
        $brandId = StudioDatasetInput::integer($input, 'brand_id');
        $datasetId = in_array($action, ['list', 'create'], true) || ($action === 'import' && ! array_key_exists('dataset_id', $input)) ? null : StudioDatasetInput::integer($input, 'dataset_id');
        if ($datasetId === null && array_key_exists('dataset_id', $input)) {
            StudioDatasetInput::fail('dataset_id', 'Not accepted for this action.');
        }
        unset($input['action'], $input['brand_id'], $input['dataset_id']);
        if (array_key_exists('file', $input)) {
            StudioDatasetInput::fail('file', 'MCP import accepts text; use multipart API for files.');
        }

        return app(StudioDatasetActions::class)->execute($user->id, $brandId, $action, $datasetId, $input);
    }
}
