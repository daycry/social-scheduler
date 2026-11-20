<?php

namespace App\Services\Canva;

use App\Models\Canva\CanvaConnection;
use Illuminate\Support\Str;

/**
 * Plantillas de marca de Canva y sus campos (T-27), solo si el plan (`brand_template`) y los permisos de la conexion lo permiten.
 * Contrato: https://www.canva.dev/docs/apps/rest-apis/reference/brand-templates/list-brand-templates/ y `.../get-brand-template-dataset/`.
 * Rellenar una plantilla es de `CanvaJobs::startAutofill()`.
 */
class CanvaBrandTemplates
{
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{4,40}$/';

    public function __construct(private readonly CanvaClient $client, private readonly CanvaConnections $connections) {}

    /** @return array{items: list<array<string, mixed>>, continuation: ?string} */
    public function list(CanvaConnection $connection, ?string $query, ?string $continuation): array
    {
        $this->assertAllowed($connection, 'brand_templates');
        $query = $query !== null ? trim(mb_substr($query, 0, 255)) : null;
        $page = $this->client->brandTemplates($this->connections->accessToken($connection), $query ?: null, $continuation !== null ? mb_substr($continuation, 0, 500) : null);

        return ['items' => array_values(array_filter(array_map(self::present(...), $page['items']))), 'continuation' => $page['continuation']];
    }

    /**
     * Campos de una plantilla. Solo los de texto se pueden rellenar desde la app (`supported`): imagen, vídeo, gráfico y hoja necesitan activos o
     * datos que esta integración no prepara y conservan el valor de la plantilla (Canva usa el valor por defecto de los campos que se omiten).
     *
     * @return list<array{name: string, type: string, supported: bool}>
     */
    public function dataset(CanvaConnection $connection, string $templateId): array
    {
        $this->assertAllowed($connection, 'brand_templates');
        $dataset = $this->client->brandTemplateDataset($this->connections->accessToken($connection), self::validId($templateId));
        $fields = [];
        foreach ($dataset as $name => $definition) {
            $type = is_string($definition['type'] ?? null) ? $definition['type'] : 'unknown';
            $fields[] = ['name' => Str::limit((string) $name, 100, ''), 'type' => $type, 'supported' => $type === 'text'];
        }

        return $fields;
    }

    public static function validId(string $templateId): string
    {
        if (! preg_match(self::ID_PATTERN, $templateId)) {
            throw new CanvaException('El identificador de la plantilla no es válido.', 404, 'not_found');
        }

        return $templateId;
    }

    /** @param  array<string, mixed>  $template @return array<string, mixed>|null */
    private static function present(array $template): ?array
    {
        $id = $template['id'] ?? null;
        if (! is_string($id) || ! preg_match(self::ID_PATTERN, $id)) {
            return null;
        }
        $thumb = $template['thumbnail']['url'] ?? null;

        return [
            'id' => $id,
            'title' => Str::limit((string) ($template['title'] ?? 'Sin título'), 255, ''),
            'thumbnail' => is_string($thumb) && str_starts_with($thumb, 'https://') ? $thumb : null,
            'view_url' => CanvaDesigns::canvaUrl($template['view_url'] ?? null),
            'created_at' => isset($template['created_at']) ? (int) $template['created_at'] : null,
        ];
    }

    private function assertAllowed(CanvaConnection $connection, string $feature): void
    {
        $caps = $this->connections->capabilities($connection);
        $scopes = array_values((array) $connection->scopes);
        if (! CanvaCapabilities::allows($feature, $caps, $scopes)) {
            throw new CanvaException((string) CanvaCapabilities::reason($feature, $caps, $scopes), 403, 'capability_required');
        }
    }
}
