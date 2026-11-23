<?php

namespace App\Http\Middleware;

use App\Exceptions\NoActiveTenantException;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uso: ->middleware('permission:public_procurement.read') */
class RequirePermission
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('UNAUTHENTICATED', 'Debes iniciar sesión.', 401);
        }
        if (! $user->hasPermission($permission, $this->current->get())) {
            // Sin empresa activa el motivo real es ese, no la falta de permiso (TD-032).
            if ($this->current->get() === null) {
                return NoActiveTenantException::response($user->isPlatformAdmin());
            }

            return ApiResponse::error('FORBIDDEN', 'No tienes permiso para realizar esta acción.', 403);
        }

        return $next($request);
    }
}
