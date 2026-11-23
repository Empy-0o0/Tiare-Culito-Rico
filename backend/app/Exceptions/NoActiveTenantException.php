<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * La acción necesita una empresa activa y la sesión no tiene ninguna (TD-032).
 * Se responde 409 con un mensaje que explica qué hacer, en vez de un 403/404 engañoso.
 */
class NoActiveTenantException extends RuntimeException
{
    public const CODE = 'NO_ACTIVE_TENANT';

    public static function message(bool $platformAdmin): string
    {
        return $platformAdmin
            ? 'No hay una empresa activa. Crea una en Administración TIARE → Empresas o elige una en el selector de empresa.'
            : 'Tu cuenta no tiene acceso a una empresa activa. Pide acceso al administrador de tu empresa o a TIARE.';
    }

    public static function response(bool $platformAdmin): JsonResponse
    {
        return ApiResponse::error(self::CODE, self::message($platformAdmin), 409);
    }

    public function render(): JsonResponse
    {
        return self::response((bool) request()->user()?->isPlatformAdmin());
    }
}
