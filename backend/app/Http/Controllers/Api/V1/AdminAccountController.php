<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\AccountAdministrationService;
use App\Support\ApiResponse;
use App\Support\PlanModules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mantenedor de cuentas y roles de la plataforma (TD-025). Solo administradores TIARE:
 * cuentas → platform.users.manage; roles → platform.roles.manage.
 */
class AdminAccountController extends Controller
{
    public function __construct(private readonly AccountAdministrationService $service) {}

    // ------------------------------------------------------------------ cuentas

    public function users(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'role' => ['nullable', 'string', 'max:64'],
            'tenant' => ['nullable', 'string', 'size:26'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $like = fn (string $t) => '%'.addcslashes($t, '%_\\').'%';

        $page = User::query()
            ->with(['platformRole', 'tenants'])
            ->when($v['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', $like($t))->orWhere('email', 'like', $like($t))))
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('is_active', $s === 'active'))
            ->when($v['role'] ?? null, function ($q, $key) {
                $role = Role::query()->where('key', $key)->first();
                if (! $role) {
                    return $q->whereRaw('1 = 0');
                }

                return $role->scope === Role::SCOPE_PLATFORM
                    ? $q->where('platform_role_id', $role->getKey())
                    : $q->whereHas('tenants', fn ($t) => $t->where('tenant_user.role_id', $role->getKey()));
            })
            ->when($v['tenant'] ?? null, fn ($q, $id) => $q->whereHas('tenants', fn ($t) => $t->where('tenants.public_id', $id)))
            ->orderBy('name')
            ->paginate(20);

        $roles = Role::query()->pluck('name', 'id');
        $roleKeys = Role::query()->pluck('key', 'id');

        return ApiResponse::paginated($page, collect($page->items())->map(fn (User $u) => $this->presentUser($u, $roles, $roleKeys))->values(), [
            'self_public_id' => $request->user()->public_id,
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'credential' => ['nullable', Rule::in(['link', 'password'])],
            'platform_role_key' => ['nullable', 'string', 'max:64'],
            'memberships' => ['nullable', 'array', 'max:20'],
            'memberships.*.tenant_public_id' => ['required', 'string', 'size:26', 'distinct'],
            'memberships.*.role_key' => ['required', 'string', 'max:64'],
        ]);
        $result = $this->service->createUser($v, $request->user());

        return ApiResponse::ok($this->presentUser($result['user']->load(['platformRole', 'tenants'])) + [
            'message' => $result['initial_password']
                ? 'Cuenta creada con contraseña de inicio. Entrégala por un canal seguro: deberá cambiarla al ingresar.'
                : 'Cuenta creada. La persona recibirá un correo para definir su contraseña.',
            'link_sent' => $result['link_sent'],
            // Única vez que se entrega. No se guarda en claro ni se puede volver a consultar.
            'initial_password' => $result['initial_password'],
        ], status: 201);
    }

    public function updateUser(Request $request, string $publicId): JsonResponse
    {
        $user = $this->findUser($publicId);
        $v = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'email' => ['sometimes', 'email:rfc', 'max:190'],
            'is_active' => ['sometimes', 'boolean'],
            'platform_role_key' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $user = $this->service->updateUser($user, $v, $request->user());

        return ApiResponse::ok($this->presentUser($user->load(['platformRole', 'tenants'])));
    }

    public function saveMembership(Request $request, string $publicId, string $tenantPublicId): JsonResponse
    {
        $user = $this->findUser($publicId);
        $v = $request->validate([
            'role_key' => ['required', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->service->upsertMembership($user, $tenantPublicId, $v['role_key'], (bool) ($v['is_active'] ?? true), $request->user());

        return ApiResponse::ok($this->presentUser($user->fresh()->load(['platformRole', 'tenants'])));
    }

    public function removeMembership(Request $request, string $publicId, string $tenantPublicId): JsonResponse
    {
        $user = $this->findUser($publicId);
        $this->service->removeMembership($user, $tenantPublicId, $request->user());

        return ApiResponse::ok($this->presentUser($user->fresh()->load(['platformRole', 'tenants'])));
    }

    public function passwordLink(Request $request, string $publicId): JsonResponse
    {
        $sent = $this->service->sendPasswordLink($this->findUser($publicId), $request->user());

        return ApiResponse::ok(['link_sent' => $sent, 'message' => 'Se envió un enlace para que la persona defina una nueva contraseña.']);
    }

    /** Genera una nueva contraseña de inicio (se muestra una sola vez; obliga a cambiarla al ingresar). */
    public function initialPassword(Request $request, string $publicId): JsonResponse
    {
        $password = $this->service->issueInitialPassword($this->findUser($publicId), $request->user());

        return ApiResponse::ok([
            'initial_password' => $password,
            'message' => 'Contraseña de inicio generada. Entrégala por un canal seguro: se cerraron las sesiones de la cuenta y deberá cambiarla al ingresar.',
        ]);
    }

    /** Opciones para los formularios: roles por alcance y empresas activas. */
    public function references(): JsonResponse
    {
        return ApiResponse::ok([
            'platform_roles' => Role::query()->where('scope', Role::SCOPE_PLATFORM)->orderBy('id')->get(['key', 'name']),
            'tenant_roles' => Role::query()->where('scope', Role::SCOPE_TENANT)->whereNotIn('key', Role::PORTAL_KEYS)->orderBy('id')->get(['key', 'name']),
            'tenants' => Tenant::query()->orderBy('name')->get(['public_id', 'name', 'status']),
        ]);
    }

    // ------------------------------------------------------------------ roles

    public function roles(): JsonResponse
    {
        $roles = Role::query()->with('permissions')->orderBy('scope')->orderBy('id')->get()->map(fn (Role $r) => $this->presentRole($r))->values();
        $catalog = Permission::query()->orderBy('key')->get()->map(fn (Permission $p) => [
            'key' => $p->key,
            'description' => $p->description,
            'group' => $this->permissionGroup($p->key),
            'platform_only' => str_starts_with($p->key, 'platform.') || $p->key === 'public_procurement.sync',
        ])->values();

        return ApiResponse::ok($roles, ['permissions' => $catalog]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120', Rule::unique('roles', 'name')->whereNull('deleted_at')],
            'scope' => ['required', Rule::in([Role::SCOPE_PLATFORM, Role::SCOPE_TENANT])],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'max:96'],
        ], ['name.unique' => 'Ya existe un rol con ese nombre.']);
        $role = $this->service->createRole($v['name'], $v['scope'], $v['description'] ?? null, $v['permissions'], $request->user());

        return ApiResponse::ok($this->presentRole($role->load('permissions')), status: 201);
    }

    public function updateRole(Request $request, string $key): JsonResponse
    {
        $role = $this->findRole($key);
        $v = $request->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:120', Rule::unique('roles', 'name')->whereNull('deleted_at')->ignore($role->getKey())],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'max:96'],
        ], ['name.unique' => 'Ya existe un rol con ese nombre.']);

        return ApiResponse::ok($this->presentRole($this->service->updateRole($role, $v, $request->user())));
    }

    public function resetRole(Request $request, string $key): JsonResponse
    {
        return ApiResponse::ok($this->presentRole($this->service->resetRole($this->findRole($key), $request->user())));
    }

    public function destroyRole(Request $request, string $key): JsonResponse
    {
        $v = $request->validate(['replacement_key' => ['nullable', 'string', 'max:64']]);
        $moved = $this->service->deleteRole($this->findRole($key), $v['replacement_key'] ?? null, $request->user());

        return ApiResponse::ok(['deleted' => true, 'moved_accounts' => $moved]);
    }

    public function destroyUser(Request $request, string $publicId): JsonResponse
    {
        $this->service->deleteUser($this->findUser($publicId), $request->user());

        return ApiResponse::ok(['deleted' => true, 'message' => 'Cuenta eliminada. Su nombre se conserva en el historial de aprobaciones, órdenes y auditoría.']);
    }

    // ------------------------------------------------------------------ helpers

    private function findUser(string $publicId): User
    {
        $user = User::query()->where('public_id', $publicId)->first();
        abort_if($user === null, 404);

        return $user;
    }

    private function findRole(string $key): Role
    {
        $role = Role::query()->where('key', $key)->first();
        abort_if($role === null, 404);

        return $role;
    }

    /** @return array<string, mixed> */
    private function presentUser(User $u, $roles = null, $roleKeys = null): array
    {
        $roles ??= Role::query()->pluck('name', 'id');
        $roleKeys ??= Role::query()->pluck('key', 'id');

        return [
            'public_id' => $u->public_id,
            'name' => $u->name,
            'email' => $u->email,
            'is_active' => $u->is_active,
            'must_change_password' => (bool) $u->must_change_password,
            'password_changed_at' => $u->password_changed_at?->toIso8601String(),
            'platform_role' => $u->platformRole ? ['key' => $u->platformRole->key, 'name' => $u->platformRole->name] : null,
            'memberships' => $u->tenants->map(fn (Tenant $t) => [
                'tenant' => ['public_id' => $t->public_id, 'name' => $t->name, 'status' => $t->status],
                'role' => ['key' => $roleKeys[$t->pivot->role_id] ?? null, 'name' => $roles[$t->pivot->role_id] ?? null],
                'is_active' => (bool) $t->pivot->is_active,
            ])->values(),
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentRole(Role $r): array
    {
        return [
            'key' => $r->key,
            'name' => $r->name,
            'description' => $r->description,
            'scope' => $r->scope,
            'is_system' => $this->service->isSystemRole($r),
            'locked' => in_array($r->key, AccountAdministrationService::LOCKED_ROLES, true),
            'deletable' => ! in_array($r->key, Role::UNDELETABLE_KEYS, true),
            'customized' => $r->permissions_customized_at !== null,
            'permissions' => $r->permissionKeys(),
            'users_count' => $this->service->roleUsage($r),
        ];
    }

    private function permissionGroup(string $key): string
    {
        foreach (PlanModules::MODULES as [$label, $prefixes]) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return $label;
                }
            }
        }

        return match (true) {
            str_starts_with($key, 'platform.') => 'Administración TIARE',
            default => 'Empresa y acceso',
        };
    }
}
