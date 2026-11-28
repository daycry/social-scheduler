<?php

namespace App\Services\Canva;

/**
 * Lo que el plan de Canva del usuario permite (T-16). Se calcula con las capacidades que devuelve `GET /users/me/capabilities` y con los
 * permisos (scopes) concedidos en la conexion; cada funcion lleva su motivo para que la interfaz la desactive explicando por que.
 * No se asume nada: sin capacidad conocida, la funcion que la exige se ofrece desactivada. Plantillas de marca exige la capacidad
 * `brand_template` y Autofill, ademas, `autofill` (https://www.canva.dev/docs/apps/rest-apis/autofill-guide/, 2026-11-12).
 */
class CanvaCapabilities
{
    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $scopes
     * @return list<array{key: string, label: string, available: bool, reason: ?string}>
     */
    public static function features(array $capabilities, array $scopes): array
    {
        $scope = fn (string $name) => in_array($name, $scopes, true);
        $cap = fn (string $name) => in_array($name, $capabilities, true);
        $missing = fn (string $name) => in_array($name, (array) config('services.canva.scopes'), true)
            ? "Falta el permiso «{$name}»: desconecta y vuelve a conectar Canva aceptando todos los permisos."
            : "Esta instalación no pide a Canva el permiso «{$name}»: quien administre el servidor debe activar CANVA_BRAND_TEMPLATES y los permisos de plantillas de marca en Canva Developers.";
        $planReason = 'Requiere un plan de Canva con plantillas de marca y Autofill (Pro, Teams o Enterprise).';
        $templatesScopes = $scope('brandtemplate:meta:read') && $scope('brandtemplate:content:read');
        $templatesMissing = $scope('brandtemplate:meta:read') ? $missing('brandtemplate:content:read') : $missing('brandtemplate:meta:read');

        return [
            self::feature('designs', 'Listar y abrir diseños', $scope('design:meta:read'), $missing('design:meta:read')),
            self::feature('create', 'Crear un diseño en Canva', $scope('design:content:write'), $missing('design:content:write')),
            self::feature('export', 'Exportar a la biblioteca (PNG, JPG, MP4) o PDF', $scope('design:content:read'), $missing('design:content:read')),
            self::feature('send', 'Enviar un recurso de la biblioteca a Canva', $scope('asset:write'), $missing('asset:write')),
            self::feature('transparent_png', 'PNG con fondo transparente', $cap('export_png_transparency'), 'Requiere un plan de pago de Canva (por ejemplo Canva Pro).'),
            self::feature('brand_templates', 'Ver tus plantillas de marca y sus campos', $cap('brand_template') && $templatesScopes, $cap('brand_template') ? $templatesMissing : $planReason),
            self::feature('autofill', 'Crear un diseño rellenando una plantilla (Autofill)', $cap('brand_template') && $cap('autofill') && $templatesScopes && $scope('design:content:write'),
                $cap('brand_template') && $cap('autofill') ? ($templatesScopes ? $missing('design:content:write') : $templatesMissing) : $planReason),
        ];
    }

    public static function allows(string $feature, array $capabilities, array $scopes): bool
    {
        foreach (self::features($capabilities, $scopes) as $item) {
            if ($item['key'] === $feature) {
                return $item['available'];
            }
        }

        return false;
    }

    public static function reason(string $feature, array $capabilities, array $scopes): ?string
    {
        foreach (self::features($capabilities, $scopes) as $item) {
            if ($item['key'] === $feature) {
                return $item['reason'];
            }
        }

        return null;
    }

    /** @return array{key: string, label: string, available: bool, reason: ?string} */
    private static function feature(string $key, string $label, bool $available, string $reason): array
    {
        return ['key' => $key, 'label' => $label, 'available' => $available, 'reason' => $available ? null : $reason];
    }
}
