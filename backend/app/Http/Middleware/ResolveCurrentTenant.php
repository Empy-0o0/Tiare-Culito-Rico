<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentTenant
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        if (! $user->is_active) {
            return ApiResponse::error('FORBIDDEN', 'La cuenta está desactivada.', 403);
        }

        // Token API: la empresa es la fijada al emitir el token y la persona debe seguir perteneciendo a ella.
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $tenant = $token->tenant_id ? $user->accessibleTenants()->whereKey($token->tenant_id)->first() : null;
            if (! $tenant) {
                return ApiResponse::error('FORBIDDEN', 'El token no tiene acceso a una empresa activa.', 403);
            }
            $this->current->set($tenant);

            return $next($request);
        }

        $sessionValue = $request->hasSession() ? $request->session()->get(CurrentTenant::SESSION_KEY) : null;
        $tenant = CurrentTenant::resolveFor($user, $sessionValue);
        $this->current->set($tenant);

        if ($tenant && $request->hasSession() && (int) $sessionValue !== $tenant->getKey()) {
            $request->session()->put(CurrentTenant::SESSION_KEY, $tenant->getKey());
        }

        return $next($request);
    }
}
