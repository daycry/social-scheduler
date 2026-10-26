<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\MediaUploadService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('import_media_from_url')]
#[Description('Importa media (imagen JPG/PNG/WebP o video MP4/MOV) desde una URL publica http/https (puertos 80/443). Devuelve el asset en estado «pendiente»: la descarga ocurre en segundo plano; consulta el progreso con list_media (status=pendiente: bytes y total) hasta que pase a «listo» (list_media por defecto) o a «invalido» (status=invalido: error con el motivo). Se rechazan direcciones internas, credenciales en la URL y esquemas distintos de http/https. Maximo 3 importaciones en curso por usuario.')]
class ImportMediaFromUrl extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'media';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('URL publica http o https del archivo (maximo 2048 caracteres)')->required(),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2048']]);

        // UnsafeUrlException (UrlGuard) es un InvalidArgumentException: la base lo traduce a un mensaje legible y lo audita.
        $asset = app(MediaUploadService::class)->importFromUrl($user, $data['url']);

        return ['asset' => $asset->toPublicArray()];
    }
}
