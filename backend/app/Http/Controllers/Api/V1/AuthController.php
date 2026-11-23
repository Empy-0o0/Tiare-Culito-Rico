<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PortalAccess;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Autenticación SPA con Sanctum (cookie de sesión + CSRF). */
class AuthController extends Controller
{
    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        if (! $request->hasSession()) {
            // Solo la SPA autorizada (dominio "stateful" de Sanctum) inicia sesión por cookie.
            return ApiResponse::error('BAD_REQUEST', 'El inicio de sesión requiere el cliente web autorizado.', 400);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        if (! Auth::guard('web')->attempt($credentials + ['is_active' => true])) {
            $audit->log('auth.login_failed', metadata: ['email_hash' => hash('sha256', strtolower($credentials['email']))]);

            return ApiResponse::error('INVALID_CREDENTIALS', 'Correo o contraseña incorrectos.', 422);
        }

        $request->session()->regenerate();
        /** @var User $user */
        $user = Auth::guard('web')->user();
        $user->forceFill(['last_login_at' => now()])->save();

        $tenant = CurrentTenant::resolveFor($user, null);
        $request->session()->put(CurrentTenant::SESSION_KEY, $tenant?->getKey());
        app(CurrentTenant::class)->set($tenant);
        $audit->log('auth.login', $user->getKey());

        return ApiResponse::ok($this->profile($user, $tenant));
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $audit->log('auth.logout');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::ok(['logged_out' => true]);
    }

    public function me(Request $request, CurrentTenant $current): JsonResponse
    {
        return ApiResponse::ok($this->profile($request->user(), $current->get()));
    }

    public function switchTenant(Request $request, CurrentTenant $current, AuditLogger $audit): JsonResponse
    {
        if (! $request->hasSession()) {
            return ApiResponse::error('BAD_REQUEST', 'El cambio de empresa requiere una sesión web.', 400);
        }
        $data = $request->validate(['tenant_public_id' => ['required', 'string', 'size:26']]);
        $user = $request->user();
        $tenant = $user->accessibleTenants()->where('tenants.public_id', $data['tenant_public_id'])->first();
        if (! $tenant) {
            return ApiResponse::error('RESOURCE_NOT_FOUND', 'Empresa no encontrada.', 404);
        }

        $request->session()->put(CurrentTenant::SESSION_KEY, $tenant->getKey());
        $current->set($tenant);
        $audit->log('auth.tenant_switched', auditableType: 'tenant', auditableId: $tenant->public_id);

        return ApiResponse::ok($this->profile($user, $tenant));
    }

    /** @return array{type: string, name: ?string}|null */
    private function portalOf(User $user, ?Tenant $tenant): ?array
    {
        if (! $tenant) {
            return null;
        }
        $access = PortalAccess::withoutTenantScope()->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->first();
        if (! $access) {
            return null;
        }
        $party = $access->party_type === PortalAccess::CUSTOMER
            ? Customer::withoutTenantScope()->with('organization')->find($access->customer_id)
            : Supplier::withoutTenantScope()->with('organization')->find($access->supplier_id);

        return ['type' => $access->party_type, 'name' => $party?->organization?->legal_name];
    }

    private function profile(User $user, ?Tenant $tenant): array
    {
        return [
            'user' => [
                'public_id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'platform_role' => $user->platformRole?->key,
            ],
            'is_platform_admin' => $user->isPlatformAdmin(),
            // Contraseña de inicio pendiente: la SPA lleva a "Mi cuenta" y el backend bloquea lo demás.
            'must_change_password' => (bool) $user->must_change_password,
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            // Portal de cliente/proveedor en la empresa activa (TD-028): la SPA muestra solo "Mi perfil".
            'portal' => $this->portalOf($user, $tenant),
            'current_tenant' => $tenant ? [
                'public_id' => $tenant->public_id,
                'name' => $tenant->name,
                'is_demo' => $tenant->is_demo,
                'subscription' => $tenant->currentSubscription()?->toSummary(),
            ] : null,
            'tenants' => $user->accessibleTenants()->orderBy('tenants.name')->get()->map(fn (Tenant $t) => [
                'public_id' => $t->public_id,
                'name' => $t->name,
                'is_demo' => $t->is_demo,
            ])->values(),
            'permissions' => $user->permissionsFor($tenant),
        ];
    }
}
