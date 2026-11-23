<?php

namespace App\Services\Admin;

use App\Models\Permission;
use App\Models\PortalAccess;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccountRetirementService;
use App\Services\Auth\InitialPasswordService;
use App\Services\Company\MembershipService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mantenedor de cuentas y roles para el administrador TIARE (TD-025).
 *
 * Reglas que evitan bloqueos y escaladas:
 * - Nadie se desactiva ni cambia su propio rol de plataforma.
 * - Siempre queda al menos una cuenta activa con rol administrador de plataforma.
 * - Solo un superadministrador asigna, quita o edita cuentas superadministradoras.
 * - Los roles administradores de plataforma no se editan ni se eliminan.
 * - Un rol de empresa nunca recibe permisos de plataforma.
 * - Cada empresa conserva al menos un administrador activo; se respeta el cupo del plan.
 * - Desactivar una cuenta cierra sus sesiones y revoca sus tokens.
 * - TIARE nunca define ni ve contraseñas: se envía un enlace para que la persona la defina.
 */
class AccountAdministrationService
{
    public const ADMIN_ROLES = ['platform_super_admin', 'tiare_admin'];

    public const LOCKED_ROLES = ['platform_super_admin', 'tiare_admin', 'customer_portal', 'supplier_portal'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MembershipService $memberships,
        private readonly InitialPasswordService $initialPasswords,
        private readonly AccountRetirementService $retirement,
    ) {}

    // ------------------------------------------------------------------ cuentas

    /**
     * Genera una contraseña de inicio (TD-026). Se devuelve una sola vez y nunca se guarda en claro.
     * La cuenta queda obligada a cambiarla al ingresar; se cierran sus sesiones y se revocan sus tokens.
     */
    public function issueInitialPassword(User $user, User $actor, bool $audit = true): string
    {
        $this->assertCanEdit($user, $actor);
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => 'Para tu propia cuenta usa "Mi cuenta" → Cambiar contraseña.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['user' => 'Activa la cuenta antes de generar una contraseña de inicio.']);
        }

        $password = $this->initialPasswords->issue($user);
        if ($audit) {
            $this->audit->log('admin.initial_password_issued', $actor->getKey(), 'user', $user->public_id);
        }

        return $password;
    }

    /**
     * @param  array{name: string, email: string, credential?: string, platform_role_key?: ?string, memberships?: list<array{tenant_public_id: string, role_key: string}>}  $data
     * @return array{user: User, link_sent: bool, initial_password: ?string}
     */
    public function createUser(array $data, User $actor): array
    {
        $email = Str::lower(trim($data['email']));
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Ya existe una cuenta con este correo.']);
        }
        $platformRole = $this->platformRole($data['platform_role_key'] ?? null, $actor);
        $memberships = $data['memberships'] ?? [];
        if (! $platformRole && $memberships === []) {
            throw ValidationException::withMessages(['memberships' => 'Asigna un rol de plataforma o al menos una empresa; sin ellos la cuenta no podría hacer nada.']);
        }

        $user = DB::transaction(function () use ($data, $email, $platformRole, $memberships, $actor) {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => $email,
                'password' => Str::password(40), // nadie la conoce; la persona define la suya con el enlace
                'platform_role_id' => $platformRole?->getKey(),
                'is_active' => true,
            ]);
            foreach ($memberships as $m) {
                $this->upsertMembership($user, $m['tenant_public_id'], $m['role_key'], true, $actor, audit: false);
            }
            $this->audit->log('admin.user_created', $actor->getKey(), 'user', $user->public_id, [
                'platform_role' => $platformRole?->key,
                'tenants' => array_column($memberships, 'tenant_public_id'),
            ]);

            return $user;
        });

        // Credencial inicial: enlace por correo (por defecto) o contraseña de inicio que se entrega una sola vez.
        if (($data['credential'] ?? 'link') === 'password') {
            $initial = $this->issueInitialPassword($user, $actor, audit: false);
            $this->audit->log('admin.initial_password_issued', $actor->getKey(), 'user', $user->public_id, ['on_create' => true]);

            return ['user' => $user->refresh(), 'link_sent' => false, 'initial_password' => $initial];
        }

        $linkSent = Password::broker()->sendResetLink(['email' => $email]) === Password::RESET_LINK_SENT;

        return ['user' => $user, 'link_sent' => $linkSent, 'initial_password' => null];
    }

    /** @param array{name?: string, email?: string, is_active?: bool, platform_role_key?: ?string} $changes */
    public function updateUser(User $user, array $changes, User $actor): User
    {
        $this->assertCanEdit($user, $actor);
        $self = $user->is($actor);

        if (array_key_exists('email', $changes)) {
            $email = Str::lower(trim((string) $changes['email']));
            if (User::query()->where('email', $email)->whereKeyNot($user->getKey())->exists()) {
                throw ValidationException::withMessages(['email' => 'Ya existe una cuenta con este correo.']);
            }
            $changes['email'] = $email;
        }

        $newRoleId = $user->platform_role_id;
        if (array_key_exists('platform_role_key', $changes)) {
            if ($self) {
                throw ValidationException::withMessages(['platform_role_key' => 'No puedes cambiar tu propio rol de plataforma.']);
            }
            $newRoleId = $this->platformRole($changes['platform_role_key'], $actor)?->getKey();
        }
        $active = array_key_exists('is_active', $changes) ? (bool) $changes['is_active'] : $user->is_active;
        if ($self && ! $active) {
            throw ValidationException::withMessages(['is_active' => 'No puedes desactivar tu propia cuenta.']);
        }

        // ¿Deja de ser administrador activo de plataforma? No puede ser el último.
        $wasAdmin = $user->is_active && $this->isAdminRoleId($user->platform_role_id);
        $staysAdmin = $active && $this->isAdminRoleId($newRoleId);
        if ($wasAdmin && ! $staysAdmin && $this->activePlatformAdmins() <= 1) {
            throw ValidationException::withMessages(['user' => 'Debe quedar al menos una cuenta activa de administrador TIARE.']);
        }

        $before = ['active' => $user->is_active, 'platform_role' => $user->platformRole?->key, 'email' => $user->email];
        DB::transaction(function () use ($user, $changes, $newRoleId, $active) {
            $user->forceFill(array_filter([
                'name' => isset($changes['name']) ? trim($changes['name']) : null,
                'email' => $changes['email'] ?? null,
            ], fn ($v) => $v !== null) + ['platform_role_id' => $newRoleId, 'is_active' => $active])->save();
            if (! $active) {
                $this->closeAccess($user);
            }
        });
        $user->refresh();
        $this->audit->log('admin.user_updated', $actor->getKey(), 'user', $user->public_id, [
            'before' => $before,
            'after' => ['active' => $user->is_active, 'platform_role' => $user->platformRole?->key, 'email' => $user->email],
        ]);

        return $user;
    }

    public function upsertMembership(User $user, string $tenantPublicId, string $roleKey, bool $active, User $actor, bool $audit = true): void
    {
        $this->assertCanEdit($user, $actor);
        $tenant = Tenant::query()->where('public_id', $tenantPublicId)->first();
        if (! $tenant) {
            throw ValidationException::withMessages(['tenant_public_id' => 'Empresa no encontrada.']);
        }
        $role = Role::query()->where('key', $roleKey)->where('scope', Role::SCOPE_TENANT)->first();
        if ($role && in_array($role->key, Role::PORTAL_KEYS, true)) {
            throw ValidationException::withMessages(['role_key' => 'El acceso de portal se crea desde la ficha del cliente o proveedor.']);
        }
        if (! $role) {
            throw ValidationException::withMessages(['role_key' => 'Rol no válido para una empresa.']);
        }

        DB::transaction(function () use ($user, $tenant, $role, $active) {
            $current = $tenant->users()->whereKey($user->getKey())->first();
            $wasActive = (bool) $current?->pivot->is_active;
            if ($active && ! $wasActive) {
                $this->memberships->assertSeatAvailable($tenant, 'tenant_public_id');
            }
            if ($current) {
                $this->assertTenantKeepsAdmin($tenant, $user, $active && $role->key === MembershipService::ADMIN_ROLE);
                $tenant->users()->updateExistingPivot($user->getKey(), ['role_id' => $role->getKey(), 'is_active' => $active]);
            } else {
                $tenant->users()->attach($user->getKey(), ['role_id' => $role->getKey(), 'is_active' => $active]);
            }
        });

        if ($audit) {
            $this->audit->log('admin.membership_saved', $actor->getKey(), 'user', $user->public_id, ['tenant' => $tenant->public_id, 'role' => $role->key, 'active' => $active]);
        }
    }

    public function removeMembership(User $user, string $tenantPublicId, User $actor): void
    {
        $this->assertCanEdit($user, $actor);
        $tenant = Tenant::query()->where('public_id', $tenantPublicId)->first();
        if (! $tenant || ! $tenant->users()->whereKey($user->getKey())->exists()) {
            throw ValidationException::withMessages(['tenant_public_id' => 'La cuenta no pertenece a esa empresa.']);
        }
        $this->assertTenantKeepsAdmin($tenant, $user, false);
        $tenant->users()->detach($user->getKey());
        // Sus tokens de esa empresa dejan de existir.
        $user->tokens()->where('tenant_id', $tenant->getKey())->delete();
        $this->audit->log('admin.membership_removed', $actor->getKey(), 'user', $user->public_id, ['tenant' => $tenant->public_id]);
    }

    /**
     * Elimina una cuenta (TD-031) conservando su nombre en el historial.
     * No se elimina la propia cuenta, el último administrador TIARE activo, ni el último
     * administrador de una empresa. Los accesos de portal se eliminan desde la ficha del cliente o proveedor.
     */
    public function deleteUser(User $user, User $actor): void
    {
        $this->assertCanEdit($user, $actor);
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => 'No puedes eliminar tu propia cuenta.']);
        }
        if (PortalAccess::query()->withoutGlobalScopes()->where('user_id', $user->getKey())->exists()) {
            throw ValidationException::withMessages(['user' => 'Es un acceso de portal: elimínalo desde la ficha del cliente o proveedor.']);
        }
        if ($user->is_active && $this->isAdminRoleId($user->platform_role_id) && $this->activePlatformAdmins() <= 1) {
            throw ValidationException::withMessages(['user' => 'Debe quedar al menos una cuenta activa de administrador TIARE.']);
        }
        foreach ($user->tenants as $tenant) {
            $this->assertTenantKeepsAdmin($tenant, $user, false);
        }

        $name = $user->name;
        $tenants = $user->tenants->pluck('public_id')->all();
        $this->retirement->retire($user);
        $this->audit->log('admin.user_deleted', $actor->getKey(), 'user', $user->public_id, ['name' => $name, 'tenants' => $tenants]);
    }

    public function sendPasswordLink(User $user, User $actor): bool
    {
        $this->assertCanEdit($user, $actor);
        $sent = Password::broker()->sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
        $this->audit->log('admin.password_link_sent', $actor->getKey(), 'user', $user->public_id, ['sent' => $sent]);

        return $sent;
    }

    // ------------------------------------------------------------------ roles

    /** @param list<string> $permissions */
    public function createRole(string $name, string $scope, ?string $description, array $permissions, User $actor): Role
    {
        $keys = $this->validPermissions($scope, $permissions);
        $base = 'custom_'.(Str::slug($name, '_') ?: 'rol');
        $key = $base;
        for ($i = 2; Role::withTrashed()->where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        $role = DB::transaction(function () use ($key, $name, $scope, $description, $keys) {
            $role = Role::create(['key' => Str::limit($key, 64, ''), 'name' => trim($name), 'scope' => $scope]);
            $role->forceFill(['description' => $description, 'permissions_customized_at' => now()])->save();
            $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id'));

            return $role;
        });
        $this->audit->log('admin.role_created', $actor->getKey(), 'role', $role->key, ['scope' => $scope, 'permissions' => $keys]);

        return $role;
    }

    /** @param array{name?: string, description?: ?string, permissions?: list<string>} $changes */
    public function updateRole(Role $role, array $changes, User $actor): Role
    {
        $this->assertRoleEditable($role);
        $before = $role->load('permissions')->permissionKeys();

        DB::transaction(function () use ($role, $changes) {
            $role->forceFill(array_filter([
                'name' => isset($changes['name']) ? trim($changes['name']) : null,
            ]) + (array_key_exists('description', $changes) ? ['description' => $changes['description']] : []) + ['permissions_customized_at' => now()])->save();
            if (array_key_exists('permissions', $changes)) {
                $keys = $this->validPermissions($role->scope, $changes['permissions']);
                $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id'));
            }
        });
        $role->load('permissions');
        $this->audit->log('admin.role_updated', $actor->getKey(), 'role', $role->key, [
            'added' => array_values(array_diff($role->permissionKeys(), $before)),
            'removed' => array_values(array_diff($before, $role->permissionKeys())),
        ]);

        return $role;
    }

    /** Vuelve un rol del sistema a sus permisos de fábrica. */
    public function resetRole(Role $role, User $actor): Role
    {
        $this->assertRoleEditable($role);
        $default = RolesAndPermissionsSeeder::ROLES[$role->key] ?? null;
        if (! $default) {
            throw ValidationException::withMessages(['role' => 'Solo los roles del sistema tienen permisos de fábrica.']);
        }
        [, $name, $patterns] = $default;
        $ids = Permission::query()->pluck('id', 'key')->filter(fn ($id, $perm) => collect($patterns)->contains(
            fn ($p) => $p === '*' || $p === $perm || (str_ends_with($p, '.*') && str_starts_with($perm, substr($p, 0, -1)))
        ))->values();
        DB::transaction(function () use ($role, $name, $ids) {
            $role->forceFill(['name' => $name, 'permissions_customized_at' => null])->save();
            $role->permissions()->sync($ids);
        });
        $this->audit->log('admin.role_reset', $actor->getKey(), 'role', $role->key);

        return $role->load('permissions');
    }

    /**
     * Elimina un rol (TD-031). Si tiene cuentas asignadas, se mueven primero al rol de reemplazo
     * (mismo alcance). Los roles propios se borran; los del sistema quedan marcados como eliminados
     * para que el seeder no los recree. Nunca se eliminan los roles de UNDELETABLE_KEYS.
     */
    public function deleteRole(Role $role, ?string $replacementKey, User $actor): int
    {
        if (in_array($role->key, Role::UNDELETABLE_KEYS, true)) {
            throw ValidationException::withMessages(['role' => $role->key === MembershipService::ADMIN_ROLE
                ? 'El rol Administrador de empresa no se elimina: cada empresa necesita al menos uno.'
                : 'Este rol está protegido: sin él se perdería el acceso de administración o de portal.']);
        }
        $usage = $this->roleUsage($role);
        $replacement = null;
        if ($usage > 0) {
            if (! $replacementKey) {
                throw ValidationException::withMessages(['replacement_key' => "El rol está asignado a {$usage} ".($usage === 1 ? 'cuenta' : 'cuentas').'. Elige a qué rol moverlas antes de eliminarlo.']);
            }
            $replacement = Role::query()->where('key', $replacementKey)->where('scope', $role->scope)->whereNotIn('key', Role::PORTAL_KEYS)->first();
            if (! $replacement || $replacement->is($role)) {
                throw ValidationException::withMessages(['replacement_key' => 'Elige otro rol del mismo alcance.']);
            }
            if ($replacement->key === 'platform_super_admin' && $actor->platformRole?->key !== 'platform_super_admin') {
                throw ValidationException::withMessages(['replacement_key' => 'Solo un superadministrador puede asignar ese rol.']);
            }
        }

        DB::transaction(function () use ($role, $replacement) {
            if ($replacement) {
                if ($role->scope === Role::SCOPE_PLATFORM) {
                    User::query()->where('platform_role_id', $role->getKey())->update(['platform_role_id' => $replacement->getKey()]);
                } else {
                    DB::table('tenant_user')->where('role_id', $role->getKey())->update(['role_id' => $replacement->getKey(), 'updated_at' => now()]);
                }
            }
            if ($this->isSystemRole($role)) {
                $role->delete(); // marcado: el seeder no lo vuelve a crear
            } else {
                $role->permissions()->detach();
                $role->forceDelete();
            }
        });
        $this->audit->log('admin.role_deleted', $actor->getKey(), 'role', $role->key, ['moved_accounts' => $usage, 'replacement' => $replacement?->key]);

        return $usage;
    }

    public function isSystemRole(Role $role): bool
    {
        return array_key_exists($role->key, RolesAndPermissionsSeeder::ROLES);
    }

    public function roleUsage(Role $role): int
    {
        return $role->scope === Role::SCOPE_PLATFORM
            ? User::query()->where('platform_role_id', $role->getKey())->count()
            : DB::table('tenant_user')->where('role_id', $role->getKey())->count();
    }

    // ------------------------------------------------------------------ reglas

    private function platformRole(?string $key, User $actor): ?Role
    {
        if ($key === null || $key === '') {
            return null;
        }
        $role = Role::query()->where('key', $key)->where('scope', Role::SCOPE_PLATFORM)->first();
        if (! $role) {
            throw ValidationException::withMessages(['platform_role_key' => 'Rol de plataforma no válido.']);
        }
        if ($role->key === 'platform_super_admin' && $actor->platformRole?->key !== 'platform_super_admin') {
            throw ValidationException::withMessages(['platform_role_key' => 'Solo un superadministrador puede asignar ese rol.']);
        }

        return $role;
    }

    private function assertCanEdit(User $user, User $actor): void
    {
        if ($user->platformRole?->key === 'platform_super_admin' && $actor->platformRole?->key !== 'platform_super_admin') {
            throw ValidationException::withMessages(['user' => 'Solo un superadministrador puede modificar esta cuenta.']);
        }
    }

    private function assertRoleEditable(Role $role): void
    {
        if (in_array($role->key, self::LOCKED_ROLES, true)) {
            throw ValidationException::withMessages(['role' => in_array($role->key, Role::PORTAL_KEYS, true)
                ? 'Los roles de portal solo ven el perfil propio del cliente o proveedor; sus permisos no se modifican.'
                : 'Los roles administradores de plataforma no se pueden modificar (evita perder el acceso de administración).']);
        }
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function validPermissions(string $scope, array $permissions): array
    {
        $keys = array_values(array_unique($permissions));
        $known = Permission::query()->whereIn('key', $keys)->pluck('key')->all();
        if (count($known) !== count($keys)) {
            throw ValidationException::withMessages(['permissions' => 'Hay permisos que no existen.']);
        }
        if ($scope === Role::SCOPE_TENANT) {
            foreach ($keys as $k) {
                if (str_starts_with($k, 'platform.') || $k === 'public_procurement.sync') {
                    throw ValidationException::withMessages(['permissions' => "El permiso {$k} es exclusivo de roles de plataforma."]);
                }
            }
        }

        return $keys;
    }

    private function assertTenantKeepsAdmin(Tenant $tenant, User $user, bool $staysAdmin): void
    {
        $adminRoleId = Role::query()->where('key', MembershipService::ADMIN_ROLE)->value('id');
        $membership = $tenant->users()->whereKey($user->getKey())->first();
        $isAdmin = $membership && $membership->pivot->is_active && (int) $membership->pivot->role_id === (int) $adminRoleId && $user->is_active;
        if ($isAdmin && ! $staysAdmin) {
            $admins = $tenant->users()->wherePivot('is_active', true)->wherePivot('role_id', $adminRoleId)->where('users.is_active', true)->count();
            if ($admins <= 1) {
                throw ValidationException::withMessages(['tenant_public_id' => "La empresa {$tenant->name} debe conservar al menos un administrador activo."]);
            }
        }
    }

    private function isAdminRoleId(?int $roleId): bool
    {
        return $roleId !== null && Role::query()->whereKey($roleId)->whereIn('key', self::ADMIN_ROLES)->exists();
    }

    private function activePlatformAdmins(): int
    {
        return User::query()->where('is_active', true)->whereIn('platform_role_id', Role::query()->whereIn('key', self::ADMIN_ROLES)->pluck('id'))->count();
    }

    /** Cierra sesiones web y revoca tokens API de la cuenta. */
    private function closeAccess(User $user): void
    {
        $this->initialPasswords->closeAccess($user);
    }
}
