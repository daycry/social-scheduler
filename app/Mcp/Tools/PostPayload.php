<?php

namespace App\Mcp\Tools;

use App\Http\Requests\PostRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

/** Esquema y validacion compartidos por create_post/update_post (las mismas reglas que el compositor web). */
trait PostPayload
{
    /** @return array<string, mixed> */
    protected function payloadSchema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('Texto de la publicacion'),
            'scheduled_at' => $schema->string()->description('Fecha/hora YYYY-MM-DD HH:MM; sin zona = zona horaria de la marca. Debe ser futura si schedule=true'),
            'schedule' => $schema->boolean()->description('true = programar; false = guardar como borrador')->required(),
            'publish_now' => $schema->boolean()->description('true = publicar ahora (sin fecha: la pone el servidor; no se puede combinar con scheduled_at). Equivale a programar y encolar de inmediato'),
            'media_ids' => $schema->array()->items($schema->integer())->description('IDs de media (list_media), en orden de publicacion'),
            'targets' => $schema->array()->items($schema->object([
                'account_id' => $schema->integer()->description('ID de cuenta (list_accounts)')->required(),
                'content_type' => $schema->string()->description('Uno de content_types de la cuenta')->required(),
                'options' => $schema->object()->description('Opciones por red: link, title, privacy, privacy_level, share_to_feed, disable_comment, shorts_hashtag'),
            ]))->description('Cuentas y tipo de contenido de destino'),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra  reglas adicionales (brand_id / post_id)
     * @return array<string, mixed> datos para PostService
     */
    protected function validatedPayload(Request $request, array $extra): array
    {
        $rules = array_merge($extra, (new PostRequest)->rules());
        $rules['media_ids'] = $rules['media'];
        $rules['media_ids.*'] = $rules['media.*'];
        unset($rules['media'], $rules['media.*']);

        $data = $request->validate($rules);
        $data['media'] = $data['media_ids'] ?? [];
        unset($data['media_ids']);

        return $data;
    }
}
