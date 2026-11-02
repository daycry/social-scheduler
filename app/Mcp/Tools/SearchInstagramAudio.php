<?php

namespace App\Mcp\Tools;

use App\Connectors\Meta\Instagram\InstagramAudio;
use App\Mcp\Abilities;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_instagram_audio')]
#[Description('Busca música y audio original autorizados por Meta para Reels de una cuenta Instagram conectada mediante Facebook. q omitido = tendencias; type=all|music|original_sound. Devuelve results, next_cursor y has_more. Para continuar, pasa next_cursor como cursor conservando account_id, q y type; termina cuando next_cursor sea null. No es el catálogo completo de la app. Devuelve audio_id, título, artista y preescucha temporal cuando existe; no selecciona ni publica. Usa el audio_id elegido en targets[].options.instagram_audio de create_post/update_post. Requiere brands:read.')]
#[IsReadOnly]
class SearchInstagramAudio extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::BRANDS_READ;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->integer()->description('ID de cuenta Instagram de list_accounts')->required(),
            'q' => $schema->string()->description('Título, artista o palabra de búsqueda (máximo 200 caracteres); omitido = tendencias'),
            'type' => $schema->string()->enum(['all', 'music', 'original_sound'])->description('Filtro de audio; por defecto all'),
            'cursor' => $schema->string()->description('next_cursor de la página anterior, sin modificar. Mantén cuenta, q y type; caduca en 30 minutos.'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['account_id' => ['required', 'integer'], 'q' => ['nullable', 'string', 'max:200'], 'type' => ['nullable', 'in:all,music,original_sound'], 'cursor' => ['nullable', 'string', 'max:8192']]);
        $account = $this->owned(SocialAccount::class, $user, $data['account_id'], 'Cuenta');

        return ['account_id' => $account->id] + app(InstagramAudio::class)->search($account, $data['q'] ?? '', $data['type'] ?? 'all', $data['cursor'] ?? null);
    }
}
