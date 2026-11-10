<?php

namespace App\Services\Studio\Ai;

use RuntimeException;

/** Fallo de un proveedor de imagen con un codigo estable y un mensaje en español para el usuario (nunca el cuerpo del proveedor). */
class AiProviderException extends RuntimeException
{
    public const MESSAGES = [
        'not_configured' => 'Este proveedor de IA no está configurado.',
        'invalid_key' => 'La clave del proveedor de IA no es válida o no tiene permiso. Revisa la configuración.',
        'quota' => 'Se agotó la cuota o el saldo de la cuenta del proveedor de IA. Revisa su facturación o inténtalo más tarde.',
        'rate_limited' => 'El proveedor de IA está limitando las peticiones y no se pudo completar tras varios intentos. Inténtalo en unos minutos.',
        'policy' => 'El proveedor rechazó la petición por su política de contenido. Reformula la descripción o cambia la imagen.',
        'bad_request' => 'El proveedor rechazó la petición. Revisa la descripción, el tamaño o la imagen de origen.',
        'server' => 'El proveedor de IA tuvo un error y no se pudo completar tras varios intentos. Inténtalo de nuevo más tarde.',
        'timeout' => 'El proveedor de IA no respondió a tiempo tras varios intentos. Inténtalo de nuevo.',
        'timeout_ambiguous' => 'El proveedor no respondió a tiempo; puede haber generado la imagen. Revisa el consumo en tu cuenta del proveedor antes de volver a intentarlo: la cuota diaria no se ha devuelto.',
        'model_unavailable' => 'Modelo no disponible: el proveedor no reconoce el modelo configurado. Revisa STUDIO_AI_OPENAI_MODEL o STUDIO_AI_GOOGLE_MODEL.',
        'invalid_response' => 'El proveedor devolvió una respuesta que no es una imagen válida.',
        'output_too_large' => 'La imagen devuelta por el proveedor supera el tamaño permitido.',
        'unsupported' => 'Este proveedor no admite esa operación.',
    ];

    public function __construct(public readonly string $errorCode, public readonly bool $retryable = false, public readonly int $retryAfter = 0, public readonly ?int $status = null)
    {
        parent::__construct(self::MESSAGES[$errorCode] ?? self::MESSAGES['server']);
    }
}
