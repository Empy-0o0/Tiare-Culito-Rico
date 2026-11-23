<?php

namespace App\Http\Middleware;

use App\Exceptions\NoActiveTenantException;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas con datos privados de una empresa (TD-032): sin empresa activa responden 409 con el motivo,
 * en vez de listas vacías engañosas o errores al crear registros sin empresa.
 */
class RequireActiveTenant
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $this->current->get() === null) {
            return NoActiveTenantException::response($request->user()->isPlatformAdmin());
        }

        return $next($request);
    }
}
