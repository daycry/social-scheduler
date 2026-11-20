<?php

namespace App\Support;

/** Texto UNICO de «falta un permiso de Meta» (T-22): lo usan el inbox y Meta Ads, con la misma instruccion, y la UI lo pinta con `PermissionNotice`. */
final class PermissionHelp
{
    public static function meta(string $feature, string $scope): string
    {
        return "{$feature} necesita el permiso {$scope}, que no se pide por defecto: actívalo en los casos de uso de la app de Meta, "
            .'añádelo a META_EXTRA_SCOPES en el servidor (separado por comas, p. ej. pages_read_user_content,ads_read) y reconecta la cuenta desde Conexiones.';
    }
}
