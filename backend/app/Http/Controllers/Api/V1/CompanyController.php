<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\NoActiveTenantException;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\ValidChileanRut;
use App\Services\Audit\AuditLogger;
use App\Services\Company\MembershipService;
use App\Support\ApiResponse;
use App\Support\ChileanRut;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Configuración de la empresa activa: datos de la empresa y usuarios.
 * El tenant siempre es el resuelto en backend (CurrentTenant); nunca uno enviado por el navegador.
 */
class CompanyController extends Controller
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function show(Request $request): JsonResponse
    {
        $this->tenantOrFail();

        return ApiResponse::ok($this->companyPayload($request->user()));
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $tenant = $this->tenantOrFail();
        $v = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'rut' => ['nullable', 'string', 'max:14', new ValidChileanRut],
        ]);
        if (array_key_exists('rut', $v)) {
            $v['rut'] = $v['rut'] ? ChileanRut::format($v['rut']) : null;
            if ($v['rut'] && Tenant::query()->where('rut', $v['rut'])->whereKeyNot($tenant->getKey())->exists()) {
                return ApiResponse::error('VALIDATION_FAILED', 'Revisa los campos indicados.', 422, ['rut' => ['Otra empresa ya usa este RUT.']]);
            }
        }

        $tenant->fill($v)->save();
        $audit->log('company.updated', auditableType: 'tenant', auditableId: $tenant->public_id, metadata: ['fields' => array_keys($v)]);

        return ApiResponse::ok($this->companyPayload($request->user()));
    }

    public function users(): JsonResponse
    {
        $tenant = $this->tenantOrFail();
        $roles = Role::query()->where('scope', 'tenant')->get()->keyBy('id');

        $memberships = app(MembershipService::class);
        $actor = request()->user();

        // Los accesos de portal de clientes/proveedores se gestionan en sus fichas, no como equipo (TD-028).
        $users = $tenant->users()->wherePivotNotIn('role_id', Role::portalRoleIds())->orderBy('users.name')->get()->map(fn (User $u) => [
            'public_id' => $u->public_id,
            'name' => $u->name,
            'must_change_password' => (bool) $u->must_change_password,
            // null = la empresa puede generarle una contraseña de inicio; si no, el motivo (TD-027).
            'initial_password_blocker' => $memberships->initialPasswordBlocker($tenant, $u, $actor),
            'email' => $u->email,
            'role' => ['key' => $roles[$u->pivot->role_id]->key ?? null, 'name' => $roles[$u->pivot->role_id]->name ?? 'Rol de plataforma'],
            'is_active' => (bool) $u->pivot->is_active && $u->is_active,
            // Monto máximo que puede aprobar (CLP). Null = sin límite.
            'approval_limit_clp' => $u->pivot->approval_limit_clp !== null ? number_format((float) $u->pivot->approval_limit_clp, 2, '.', '') : null,
            'account_active' => $u->is_active,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'joined_at' => $u->pivot->created_at?->toIso8601String(),
        ])->values();

        return ApiResponse::ok($users);
    }

    public function roles(): JsonResponse
    {
        $this->tenantOrFail();
        $roles = Role::query()->where('scope', 'tenant')->whereNotIn('key', Role::PORTAL_KEYS)->with('permissions')->orderBy('id')->get()->map(fn (Role $r) => [
            'key' => $r->key,
            'name' => $r->name,
            'permissions' => $r->permissionKeys(),
        ]);

        return ApiResponse::ok($roles);
    }

    public function invite(Request $request, MembershipService $memberships): JsonResponse
    {
        $tenant = $this->tenantOrFail();
        $v = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'role_key' => ['required', 'string', Rule::exists('roles', 'key')->where('scope', 'tenant')->whereNull('deleted_at')],
            'credential' => ['nullable', Rule::in(['link', 'password'])],
        ]);

        $result = $memberships->invite($tenant, $v['name'], $v['email'], $v['role_key'], $request->user(), $v['credential'] ?? 'link');

        return ApiResponse::ok([
            'public_id' => $result['user']->public_id,
            'new_account' => $result['created'],
            'link_sent' => $result['link_sent'],
            // Única vez que se entrega (solo para cuentas nuevas). No se guarda en claro.
            'initial_password' => $result['initial_password'],
            'message' => match (true) {
                ! $result['created'] => 'La persona ya tenía cuenta en TIARE y ahora tiene acceso a esta empresa. Conserva su contraseña actual.',
                $result['initial_password'] !== null => 'Persona agregada con contraseña de inicio. Entrégala por un canal seguro: deberá cambiarla al ingresar.',
                default => 'Invitación enviada: la persona recibirá un correo para definir su contraseña.',
            },
        ], status: 201);
    }

    /** Contraseña de inicio para una persona que solo pertenece a esta empresa (TD-027). Se muestra una vez. */
    public function initialPassword(Request $request, string $publicId, MembershipService $memberships): JsonResponse
    {
        $tenant = $this->tenantOrFail();
        $member = $tenant->users()->where('users.public_id', $publicId)->first();
        if (! $member) {
            return ApiResponse::error('RESOURCE_NOT_FOUND', 'Recurso no encontrado.', 404);
        }

        return ApiResponse::ok([
            'initial_password' => $memberships->issueInitialPassword($tenant, $member, $request->user()),
            'message' => 'Contraseña de inicio generada. Se cerraron sus sesiones y deberá cambiarla al ingresar.',
        ]);
    }

    public function updateUser(Request $request, string $publicId, MembershipService $memberships): JsonResponse
    {
        $tenant = $this->tenantOrFail();
        $v = $request->validate([
            'role_key' => ['sometimes', 'string', Rule::exists('roles', 'key')->where('scope', 'tenant')->whereNull('deleted_at')],
            'is_active' => ['sometimes', 'boolean'],
            'approval_limit_clp' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999'],
        ]);
        $member = $tenant->users()->where('users.public_id', $publicId)->first();
        if (! $member) {
            return ApiResponse::error('RESOURCE_NOT_FOUND', 'Recurso no encontrado.', 404);
        }

        $memberships->update($tenant, $member, $v, $request->user());

        return $this->users();
    }

    /** Quita a la persona de la empresa (TD-031). Si no le queda ningún acceso, su cuenta se elimina. */
    public function removeUser(Request $request, string $publicId, MembershipService $memberships): JsonResponse
    {
        $tenant = $this->tenantOrFail();
        $member = $tenant->users()->where('users.public_id', $publicId)->first();
        if (! $member) {
            return ApiResponse::error('RESOURCE_NOT_FOUND', 'Recurso no encontrado.', 404);
        }
        $memberships->remove($tenant, $member, $request->user());

        return $this->users();
    }

    private function tenantOrFail(): Tenant
    {
        $tenant = $this->current->get();
        if ($tenant === null) {
            throw new NoActiveTenantException;
        }

        return $tenant;
    }

    private function companyPayload(User $user): array
    {
        $tenant = $this->current->get();

        return [
            'public_id' => $tenant->public_id,
            'name' => $tenant->name,
            'legal_name' => $tenant->legal_name,
            'rut' => $tenant->rut,
            'status' => $tenant->status,
            'is_demo' => $tenant->is_demo,
            'members_count' => $tenant->activeMembersCount(),
            'can_manage' => $user->hasPermission('company.manage', $tenant),
            'can_manage_users' => $user->hasPermission('users.manage', $tenant),
            'can_manage_tokens' => $user->hasPermission('api_tokens.manage', $tenant),
            // Plan contratado: la empresa solo ve su propia suscripción vigente.
            'subscription' => $tenant->currentSubscription()?->toSummary(),
            'seats' => ['used' => $tenant->activeMembersCount(), 'max' => $tenant->currentSubscription()?->plan?->max_users],
        ];
    }
}
