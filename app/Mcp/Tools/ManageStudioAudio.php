<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\Studio\StudioAudio;
use App\Services\Studio\StudioAudioProbeUnavailable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_studio_audio')]
#[Description('Audio privado por marca. start recibe nombre/MIME/tamaño (hasta100MiB) y devuelve ticket binario local o prefirmado single/multipart; nunca base64 ni URL de origen. Transfiere los bytes por HTTP, luego complete con partes si aplica; get/list consultan estado/biblioteca. Solo listo con duración/códec sondeados puede usarse en timeline.audio_tracks. URLs API locales requieren Bearer designs:write/read; no envíes ese Bearer a URLs de almacenamiento. No publica. get/list requieren designs:read; start/complete designs:write.')]
class ManageStudioAudio extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::DESIGNS_WRITE;
    }

    protected function abilityFor(Request $request): string
    {
        return in_array($request->get('action'), ['list', 'get'], true) ? Abilities::DESIGNS_READ : Abilities::DESIGNS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->brandSchema($schema) + [
            'action' => $schema->string()->enum(['start', 'complete', 'list', 'get'])->required(),
            'media_id' => $schema->integer()->min(1), 'name' => $schema->string(),
            'mime' => $schema->string()->enum(StudioAudio::MIMES), 'size' => $schema->integer()->min(1)->max(104857600),
            'parts' => $schema->array()->items($schema->object(['number' => $schema->integer()->min(1)->max(10000)->required(), 'etag' => $schema->string()->required()])),
            'query' => $schema->string(), 'page' => $schema->integer()->min(1), 'per_page' => $schema->integer()->min(1)->max(100),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        if (array_diff(array_keys($request->all()), ['brand', 'brand_id', 'action', 'media_id', 'name', 'mime', 'size', 'parts', 'query', 'page', 'per_page'])) {
            throw ValidationException::withMessages(['audio' => ['No se admiten rutas, URLs de origen ni contenido base64.']]);
        }
        $data = $request->validate(['action' => ['required', 'in:start,complete,list,get'],
            'media_id' => ['required_if:action,get,complete', 'integer', 'min:1'],
            'name' => ['required_if:action,start', 'string', 'max:255'], 'mime' => ['required_if:action,start', 'string'],
            'size' => ['required_if:action,start', 'integer', 'min:1'], 'parts' => ['sometimes', 'array'],
            'query' => ['sometimes', 'nullable', 'string'], 'page' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer']]);
        $brand = $this->brandFrom($request, $user);
        $audio = app(StudioAudio::class);
        if ($data['action'] === 'start') {
            return $audio->start($user, $brand, array_intersect_key($data, array_flip(['name', 'mime', 'size'])), true);
        }
        if ($data['action'] === 'list') {
            return $audio->listing($user, $brand, array_intersect_key($data, array_flip(['query', 'page', 'per_page'])), true);
        }
        try {
            $asset = $data['action'] === 'get' ? $audio->owned($user, $brand, $data['media_id'])
                : $audio->complete($user, $brand, $data['media_id'], array_intersect_key($data, ['parts' => true]));
        } catch (StudioAudioProbeUnavailable $e) {
            abort(503, $e->getMessage());
        }

        return ['asset' => $audio->present($brand, $asset, true)];
    }
}
