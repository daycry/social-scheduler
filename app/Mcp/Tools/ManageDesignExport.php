<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Studio\DesignExport;
use App\Models\User;
use App\Services\Studio\StudioAvControl;
use App\Services\Studio\StudioExports;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_design_export')]
#[Description('Consulta una exportación propia con get; cancela/reintenta trabajos MP4/GIF con cancel/retry, manteniendo revisión y opciones. post crea solo un borrador con sus medios listos en su marca. get requiere designs:read; cancel/retry designs:write; post exige designs:write y posts:write. Consulta progress y download_url reales; no inventa archivos ni publica. Una exportación ready se conserva y no admite cancel/retry.')]
class ManageDesignExport extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::DESIGNS_WRITE;
    }

    protected function abilityFor(Request $request): string
    {
        if ($request->get('action') === 'get') {
            return Abilities::DESIGNS_READ;
        }
        // Return the missing permission so the shared gate denies/audits either missing capability.
        if ($request->get('action') === 'post' && ! $request->user()->tokenCan(Abilities::POSTS_WRITE)) {
            return Abilities::POSTS_WRITE;
        }

        return Abilities::DESIGNS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'design_export';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['action' => $schema->string()->enum(['get', 'cancel', 'retry', 'post'])->required(),
            'export_id' => $schema->integer()->min(1)->required(), 'text' => $schema->string()->max(100000),
            'targets' => $schema->array()->items($schema->object())];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['action' => ['required', 'in:get,cancel,retry,post'], 'export_id' => ['required', 'integer', 'min:1'],
            'text' => ['nullable', 'string', 'max:100000'], 'targets' => ['sometimes', 'array']]);
        $export = $this->owned(DesignExport::class, $user, $data['export_id'], 'Exportación');
        $exports = app(StudioExports::class);
        if ($data['action'] === 'post') {
            $post = $exports->createPost($user, $export, array_intersect_key($data, array_flip(['text', 'targets'])));

            return ['post' => ['id' => $post->id, 'status' => $post->status->value, 'edit_url' => route('posts.edit', $post, absolute: false)]];
        }
        $current = match ($data['action']) {
            'cancel' => app(StudioAvControl::class)->cancel($user, $export),
            'retry' => app(StudioAvControl::class)->retry($user, $export),
            default => $export,
        };

        return $exports->present($user, $current);
    }
}
