<?php

namespace App\Services\Company;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccountRetirementService;
use App\Services\Auth\InitialPasswordService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Usuarios de una empresa (tenant): invitación, cambio de rol y activación.
 *
 * - Solo se asignan roles de alcance "tenant"; los roles de plataforma no se administran desde una empresa.
 * - Un usuario nuevo se crea con una contraseña aleatoria que nadie conoce y recibe un enlace para
 *   definir la suya (broker de restablecimiento de Laravel). TIARE nunca muestra ni envía contraseñas.
 * - Nadie puede cambiar su propio rol ni desactivarse, y la empresa nunca queda sin un administrador activo.
 */
class MembershipService
{
    public const ADMIN_ROLE = 'tenant_admin';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InitialPasswordService $initialPasswords,
        private readonly AccountRetirementService $retirement,
    ) {}

    /**
     * @param  string  $credential  'link' (correo para definir contraseña) | 'password' (contraseña de inicio, TD-027)
     * @return array{user: User, created: bool, link_sent: bool, initial_password: ?string}
     */
    public function invite(Tenant $tenant, string $name, string $email, string $roleKey, User $actor, string $credential = 'link'): array
    {
        $role = $this->tenantRole($roleKey);
        $email = Str::lower(trim($email));

        $result = DB::transaction(function () use ($tenant, $name, $email, $role, $actor) {
            $user = User::query()->where('email', $email)->first();
            $created = false;

            if ($user && $tenant->users()->whereKey($user->getKey())->exists()) {
                throw ValidationException::withMessages(['email' => 'Esta persona ya pertenece a la empresa. Cambia su rol desde la lista.']);
            }
            $this->assertSeatAvailable($tenant, 'email');
            if (! $user) {
                $user = User::create(['name' => trim($name), 'email' => $email, 'password' => Str::password(40), 'is_active' => true]);
                $created = true;
            }

            $tenant->users()->attach($user->getKey(), ['role_id' => $role->getKey(), 'is_active' => true]);
            $this->audit->log('company.user_invited', $actor->getKey(), 'user', $user->public_id, ['role' => $role->key, 'new_account' => $created]);

            return ['user' => $user, 'created' => $created];
        });

        // Una cuenta que ya existía conserva su contraseña: la empresa nunca la reemplaza.
        if ($result['created'] && $credential === 'password') {
            $initial = $this->initialPasswords->issue($result['user']);
            $this->audit->log('company.initial_password_issued', $actor->getKey(), 'user', $result['user']->public_id, ['on_invite' => true]);

            return $result + ['link_sent' => false, 'initial_password' => $initial];
        }

        // Fuera de la transacción: el correo solo sale si la membresía quedó guardada.
        $linkSent = false;
        if ($result['created']) {
            $linkSent = Password::broker()->sendResetLink(['email' => $email]) === Password::RESET_LINK_SENT;
        }

        return $result + ['link_sent' => $linkSent, 'initial_password' => null];
    }

    /**
     * El administrador de una empresa puede generar una contraseña de inicio solo para cuentas que
     * pertenecen únicamente a su empresa y no son personal TIARE (TD-027). Devuelve el motivo si no puede.
     */
    public function initialPasswordBlocker(Tenant $tenant, User $member, User $actor): ?string
    {
        $membership = $tenant->users()->whereKey($member->getKey())->first();

        return match (true) {
            ! $membership => 'La persona no pertenece a esta empresa.',
            $member->is($actor) => 'Para tu propia cuenta usa "Mi cuenta" → Cambiar contraseña.',
            ! $member->is_active || ! $membership->pivot->is_active => 'Devuélvele el acceso antes de generar una contraseña de inicio.',
            $member->platform_role_id !== null => 'Es una cuenta del personal TIARE: solo TIARE puede gestionar su contraseña.',
            $member->tenants()->whereKeyNot($tenant->getKey())->exists() => 'La persona también tiene acceso a otra empresa: debe recuperar su contraseña con "¿Olvidaste tu contraseña?".',
            default => null,
        };
    }

    public function issueInitialPassword(Tenant $tenant, User $member, User $actor): string
    {
        if ($reason = $this->initialPasswordBlocker($tenant, $member, $actor)) {
            throw ValidationException::withMessages(['user' => $reason]);
        }
        $password = $this->initialPasswords->issue($member);
        $this->audit->log('company.initial_password_issued', $actor->getKey(), 'user', $member->public_id);

        return $password;
    }

    /** @param array{role_key?: string, is_active?: bool, approval_limit_clp?: ?string} $changes */
    public function update(Tenant $tenant, User $member, array $changes, User $actor): void
    {
        $membership = $tenant->users()->whereKey($member->getKey())->first();
        if (! $membership) {
            throw ValidationException::withMessages(['user' => 'La persona no pertenece a esta empresa.']);
        }
        if ($member->is($actor)) {
            throw ValidationException::withMessages(['user' => 'No puedes cambiar tu propio rol ni desactivarte. Pídeselo a otro administrador.']);
        }

        DB::transaction(function () use ($tenant, $member, $membership, $changes, $actor) {
            $pivot = [];
            if (isset($changes['role_key'])) {
                $pivot['role_id'] = $this->tenantRole($changes['role_key'])->getKey();
            }
            if (array_key_exists('approval_limit_clp', $changes)) {
                $pivot['approval_limit_clp'] = $changes['approval_limit_clp'];
            }
            if (array_key_exists('is_active', $changes)) {
                $pivot['is_active'] = (bool) $changes['is_active'];
            }
            if ($pivot === []) {
                return;
            }
            if (($pivot['is_active'] ?? false) && ! $membership->pivot->is_active) {
                $this->assertSeatAvailable($tenant, 'user');
            }

            $wasAdmin = $membership->pivot->is_active && (int) $membership->pivot->role_id === $this->adminRoleId();
            $staysAdmin = ($pivot['is_active'] ?? (bool) $membership->pivot->is_active)
                && (int) ($pivot['role_id'] ?? $membership->pivot->role_id) === $this->adminRoleId();
            if ($wasAdmin && ! $staysAdmin && $this->activeAdmins($tenant) <= 1) {
                throw ValidationException::withMessages(['user' => 'La empresa debe tener al menos un administrador activo.']);
            }

            $tenant->users()->updateExistingPivot($member->getKey(), $pivot);
            $this->audit->log('company.user_updated', $actor->getKey(), 'user', $member->public_id, [
                'role_from' => Role::query()->whereKey($membership->pivot->role_id)->value('key'),
                'role_to' => isset($pivot['role_id']) ? Role::query()->whereKey($pivot['role_id'])->value('key') : null,
                'active_from' => (bool) $membership->pivot->is_active,
                'active_to' => $pivot['is_active'] ?? null,
            ]);
        });
    }

    /**
     * Quita a una persona de la empresa (TD-031): pierde el acceso y sus tokens de esta empresa.
     * Si la cuenta no queda con otra empresa ni rol TIARE, se elimina (conservando su nombre en el historial).
     * No aplica a uno mismo, al último administrador ni a accesos de portal (se gestionan en la ficha).
     */
    public function remove(Tenant $tenant, User $member, User $actor): bool
    {
        $membership = $tenant->users()->whereKey($member->getKey())->first();
        if (! $membership) {
            throw ValidationException::withMessages(['user' => 'La persona no pertenece a esta empresa.']);
        }
        if ($member->is($actor)) {
            throw ValidationException::withMessages(['user' => 'No puedes quitarte de la empresa. Pídeselo a otro administrador.']);
        }
        if (in_array((int) $membership->pivot->role_id, Role::portalRoleIds(), true)) {
            throw ValidationException::withMessages(['user' => 'Es un acceso de portal: elimínalo desde la ficha del cliente o proveedor.']);
        }
        $wasAdmin = $membership->pivot->is_active && (int) $membership->pivot->role_id === $this->adminRoleId();
        if ($wasAdmin && $this->activeAdmins($tenant) <= 1) {
            throw ValidationException::withMessages(['user' => 'La empresa debe tener al menos un administrador activo.']);
        }

        $retire = $member->platform_role_id === null && ! $member->tenants()->whereKeyNot($tenant->getKey())->exists();
        DB::transaction(function () use ($tenant, $member, $retire) {
            $tenant->users()->detach($member->getKey());
            $member->tokens()->where('tenant_id', $tenant->getKey())->delete();
            if ($retire) {
                $this->retirement->retire($member);
            }
        });
        $this->audit->log('company.user_removed', $actor->getKey(), 'user', $member->public_id, ['name' => $member->name, 'account_deleted' => $retire]);

        return $retire;
    }

    /** El plan vigente limita cuántas personas pueden tener acceso activo (null = sin límite). */
    public function assertSeatAvailable(Tenant $tenant, string $field): void
    {
        $max = $tenant->currentSubscription()?->plan?->max_users;
        if ($max !== null && $tenant->activeMembersCount() >= $max) {
            throw ValidationException::withMessages([$field => "El plan contratado permite hasta {$max} personas con acceso activo. Desactiva a alguien o solicita a TIARE un cambio de plan."]);
        }
    }

    private function tenantRole(string $key): Role
    {
        $role = Role::query()->where('key', $key)->where('scope', 'tenant')->whereNotIn('key', Role::PORTAL_KEYS)->first();
        if (! $role) {
            throw ValidationException::withMessages(['role_key' => 'Rol no válido para una empresa.']);
        }

        return $role;
    }

    private function adminRoleId(): int
    {
        return (int) Role::query()->where('key', self::ADMIN_ROLE)->value('id');
    }

    private function activeAdmins(Tenant $tenant): int
    {
        return $tenant->users()->wherePivot('is_active', true)->wherePivot('role_id', $this->adminRoleId())->where('users.is_active', true)->count();
    }
}
