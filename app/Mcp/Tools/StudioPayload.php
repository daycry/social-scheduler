<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

trait StudioPayload
{
    protected function designPayloadSchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Nombre del diseño, hasta 255 caracteres')->required(),
            'document' => $schema->object([
                'schema_version' => $schema->integer()->enum([1])->required(),
                'width' => $schema->integer()->min(40)->max(8000)->required(),
                'height' => $schema->integer()->min(40)->max(8000)->required(),
                'pages' => $schema->array()->items($schema->object([
                    'id' => $schema->string()->required(), 'name' => $schema->string(),
                    'background' => $schema->anyOf([$schema->string(), $schema->object()])->required(),
                    'duration' => $schema->number(),
                    'elements' => $schema->array()->items($schema->object())->required(),
                ]))->required(),
            ])->description('Documento nativo v1, nunca JSON Fabric. 1–100 páginas, 500 elementos/página, 2 MiB. Elementos text/rect/ellipse/triangle/line/path/image/video/group con id único, x,y,width,height; grupos elements; imágenes/vídeos asset_id registrado de la misma marca, nunca URL/src. Texto plano; fill hexadecimal o gradiente lineal. get_design devuelve documento y recursos para editar conservando sus propiedades.')->required(),
            'folder_id' => $schema->integer()->nullable()->description('Carpeta de la misma marca; null elimina asociación'),
            'is_template' => $schema->boolean()->description('Guardar como plantilla reutilizable'),
        ];
    }
}
