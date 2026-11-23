<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveCurrentTenant;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->prependToGroup('api', AssignRequestId::class);
        $middleware->alias([
            'tenant' => ResolveCurrentTenant::class,
            'permission' => RequirePermission::class,
            'password.fresh' => RequirePasswordChange::class,
            'tenant.required' => \App\Http\Middleware\RequireActiveTenant::class,
        ]);
        // El tenant debe resolverse antes del route-model binding: los modelos con BelongsToTenant
        // se buscan con el global scope del tenant activo (un registro ajeno da 404).
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveCurrentTenant::class);
        // Sin empresa activa se explica el motivo antes de buscar el registro (TD-032).
        $middleware->prependToPriorityList(SubstituteBindings::class, \App\Http\Middleware\RequireActiveTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // El ticket nunca debe aparecer en logs ni respuestas.
        $exceptions->dontFlash(['password', 'password_confirmation', 'ticket']);

        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (MercadoPublicoException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            $headers = $e->retryAfter ? ['Retry-After' => (string) max(1, (int) ceil(now()->diffInSeconds($e->retryAfter, false)))] : [];

            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->internalHttpStatus(), headers: $headers);
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiResponse::error('VALIDATION_FAILED', 'Revisa los campos indicados.', 422, $e->errors()) : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiResponse::error('UNAUTHENTICATED', 'Debes iniciar sesión.', 401) : null;
        });

        $exceptions->render(function (TokenMismatchException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiResponse::error('CSRF_TOKEN_MISMATCH', 'La sesión expiró. Recarga la página.', 419) : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiResponse::error('RATE_LIMITED', 'Demasiadas solicitudes. Intenta en unos minutos.', 429, headers: $e->getHeaders()) : null;
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            // Las políticas de recursos de empresa niegan si no hay empresa activa: se explica el motivo (TD-032).
            if ($request->user() && app(\App\Support\CurrentTenant::class)->get() === null) {
                return \App\Exceptions\NoActiveTenantException::response($request->user()->isPlatformAdmin());
            }

            return ApiResponse::error('FORBIDDEN', 'No tienes permiso para realizar esta acción.', 403);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiResponse::error('RESOURCE_NOT_FOUND', 'Recurso no encontrado.', 404) : null;
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request) || config('app.debug')) {
                return null;
            }
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return ApiResponse::error($status >= 500 ? 'INTERNAL_ERROR' : 'BAD_REQUEST', $status >= 500 ? 'Ocurrió un error inesperado.' : 'Solicitud no válida.', $status);
        });
    })->create();
