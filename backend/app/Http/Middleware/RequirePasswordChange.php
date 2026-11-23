<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Con una contraseña de inicio pendiente de cambio (TD-026), la cuenta solo puede consultar
 * su perfil, cambiar la contraseña o cerrar sesión. Todo lo demás responde 403.
 */
class RequirePasswordChange
{
    private const ALLOWED = ['api/v1/auth/me', 'api/v1/auth/logout', 'api/v1/auth/password'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->must_change_password && ! in_array($request->path(), self::ALLOWED, true)) {
            return ApiResponse::error('PASSWORD_CHANGE_REQUIRED', 'Debes cambiar tu contraseña de inicio antes de continuar.', 403);
        }

        return $next($request);
    }
}
