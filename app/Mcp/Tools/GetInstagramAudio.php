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

#[Name('get_instagram_audio')]
#[Description('Consulta una pista por audio_id del catálogo autorizado de Meta para una cuenta Instagram. Permite identificarla y renovar la URL temporal de preescucha. Un enlace a un Reel no es un audio_id. No modifica ni publica nada. Requiere brands:read.')]
#[IsReadOnly]
class GetInstagramAudio extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::BRANDS_READ;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->integer()->description('ID de cuenta Instagram de list_accounts')->required(),
            'audio_id' => $schema->string()->description('Identificador de pista obtenido de search_instagram_audio; no una URL')->required(),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['account_id' => ['required', 'integer'], 'audio_id' => ['required', 'string', 'regex:/^[0-9]{1,40}$/D']]);
        $account = $this->owned(SocialAccount::class, $user, $data['account_id'], 'Cuenta');

        return ['account_id' => $account->id, 'track' => app(InstagramAudio::class)->get($account, $data['audio_id'])];
    }
}
