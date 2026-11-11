<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\Studio\StudioFonts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_studio_font')]
#[Description('Gestiona tipografías privadas de la marca. list/get requieren designs:read; upload/delete/archive/restore designs:write. upload recibe file_base64 TTF/OTF/WOFF/WOFF2 hasta 8 MiB; no acepta URLs. DELETE rechaza referencias históricas con 409. file_url requiere Bearer designs:read.')]
class ManageStudioFont extends SchedulerTool
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
            'action' => $schema->string()->enum(['list', 'get', 'upload', 'delete', 'archive', 'restore'])->required(),
            'font_id' => $schema->integer()->min(1), 'name' => $schema->string(), 'file_base64' => $schema->string(),
            'query' => $schema->string(), 'page' => $schema->integer()->min(1), 'per_page' => $schema->integer()->min(1)->max(100), 'archived' => $schema->boolean(),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['action' => ['required', 'in:list,get,upload,delete,archive,restore'],
            'font_id' => ['required_unless:action,list,upload', 'integer', 'min:1'], 'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'file_base64' => ['required_if:action,upload', 'string', 'max:11184812'],
            'query' => ['sometimes', 'string'], 'page' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer'], 'archived' => ['sometimes', 'boolean']]);
        $brand = $this->brandFrom($request, $user);
        $fonts = app(StudioFonts::class);
        if ($data['action'] === 'list') {
            return $fonts->listing($user, $brand, array_intersect_key($data, array_flip(['query', 'page', 'per_page', 'archived'])), true);
        }
        if ($data['action'] === 'upload') {
            $bytes = base64_decode($data['file_base64'], true);
            if ($bytes === false || strlen($bytes) > 8 * 1024 * 1024) {
                throw ValidationException::withMessages(['file_base64' => ['Indica un archivo base64 válido de hasta 8 MiB.']]);
            }
            $disk = Storage::disk('local');
            $path = 'studio-font-upload/'.$user->id.'/'.Str::uuid().'.bin';
            abort_unless($disk->put($path, $bytes), 503);
            try {
                $font = $fonts->upload($user, $brand, new UploadedFile($disk->path($path), 'upload.font', null, null, true), $data['name'] ?? null);
            } finally {
                $disk->delete($path);
            }

            return ['font' => $fonts->present($font, true)];
        }
        if ($data['action'] === 'delete') {
            $fonts->delete($user, $brand, $data['font_id']);

            return ['deleted' => true];
        }
        $font = $data['action'] === 'get' ? $fonts->owned($user, $brand, $data['font_id']) : $fonts->archive($user, $brand, $data['font_id'], $data['action'] === 'archive');

        return ['font' => $fonts->present($font, true)];
    }
}
