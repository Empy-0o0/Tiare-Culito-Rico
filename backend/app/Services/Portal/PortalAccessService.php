<?php

namespace App\Services\Portal;

use App\Models\Customer;
use App\Models\PortalAccess;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\InitialPasswordService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Accesos al portal de clientes y proveedores (TD-028).
 *
 * - Cada cliente/proveedor tiene como máximo un acceso; cada acceso es una cuenta con el rol
 *   customer_portal / supplier_portal en la empresa, ligada a ESE registro.
 * - Cuenta nueva → contraseña de inicio (una vez, cambio obligatorio). Cuenta existente de otra empresa
 *   → conserva su contraseña. Nunca se usa una cuenta del equipo de la empresa ni del personal TIARE.
 * - Regenerar la contraseña solo si la cuenta pertenece únicamente a esta empresa.
 * - Revocar quita el acceso, revoca sus tokens de la empresa y desactiva la cuenta si queda sin uso.
 */
class PortalAccessService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InitialPasswordService $passwords,
    ) {}

    public function find(Customer|Supplier $party): ?PortalAccess
    {
        return PortalAccess::query()->where($this->column($party), $party->getKey())->with('user')->first();
    }

    /** Valida antes de crear el cliente/proveedor, para no dejar registros a medias. */
    public function assertEmailAvailable(Tenant $tenant, string $email): void
    {
        $user = User::query()->where('email', Str::lower(trim($email)))->first();
        if (! $user) {
            return;
        }
        if ($user->platform_role_id !== null) {
            throw ValidationException::withMessages(['portal_access.email' => 'Ese correo es de una cuenta del personal TIARE; usa otro correo para el portal.']);
        }
        if ($tenant->users()->whereKey($user->getKey())->exists()) {
            throw ValidationException::withMessages(['portal_access.email' => 'Ese correo ya tiene acceso a esta empresa (como persona del equipo o en otro portal).']);
        }
    }

    /** @return array{access: PortalAccess, created: bool, initial_password: ?string} */
    public function grant(Tenant $tenant, Customer|Supplier $party, string $email, string $name, User $actor): array
    {
        if ($this->find($party)) {
            throw ValidationException::withMessages(['portal_access.email' => 'Este registro ya tiene acceso al portal.']);
        }
        $this->assertEmailAvailable($tenant, $email);
        $email = Str::lower(trim($email));
        $type = $party instanceof Customer ? PortalAccess::CUSTOMER : PortalAccess::SUPPLIER;

        return DB::transaction(function () use ($tenant, $party, $email, $name, $actor, $type) {
            $user = User::query()->where('email', $email)->first();
            $created = false;
            if (! $user) {
                $user = User::create(['name' => Str::limit(trim($name), 120, ''), 'email' => $email, 'password' => Str::password(40), 'is_active' => true]);
                $created = true;
            }
            $tenant->users()->attach($user->getKey(), ['role_id' => Role::query()->where('key', PortalAccess::ROLES[$type])->value('id'), 'is_active' => true]);
            $access = PortalAccess::create([
                'user_id' => $user->getKey(),
                'party_type' => $type,
                $this->column($party) => $party->getKey(),
                'created_by' => $actor->getKey(),
            ]);
            $initial = $created ? $this->passwords->issue($user) : null;
            $this->audit->log('portal.access_granted', $actor->getKey(), $type, $party->public_id, ['user' => $user->public_id, 'new_account' => $created]);

            return ['access' => $access->load('user'), 'created' => $created, 'initial_password' => $initial];
        });
    }

    public function regeneratePassword(Tenant $tenant, PortalAccess $access, User $actor): string
    {
        $user = $access->user;
        if ($user->platform_role_id !== null || $user->tenants()->whereKeyNot($tenant->getKey())->exists()) {
            throw ValidationException::withMessages(['user' => 'La cuenta también tiene acceso a otra empresa: debe recuperar su contraseña con "¿Olvidaste tu contraseña?".']);
        }
        if (! $user->is_active || ! $this->isActive($tenant, $access)) {
            throw ValidationException::withMessages(['user' => 'Activa el acceso antes de generar una contraseña de inicio.']);
        }
        $password = $this->passwords->issue($user);
        $this->audit->log('portal.password_issued', $actor->getKey(), $access->party_type, $this->partyPublicId($access), ['user' => $user->public_id]);

        return $password;
    }

    public function setActive(Tenant $tenant, PortalAccess $access, bool $active, User $actor): void
    {
        $tenant->users()->updateExistingPivot($access->user_id, ['is_active' => $active]);
        if (! $active) {
            $access->user->tokens()->where('tenant_id', $tenant->getKey())->delete();
        }
        $this->audit->log($active ? 'portal.access_enabled' : 'portal.access_disabled', $actor->getKey(), $access->party_type, $this->partyPublicId($access));
    }

    public function revoke(Tenant $tenant, PortalAccess $access, User $actor): void
    {
        $user = $access->user;
        $partyId = $this->partyPublicId($access);
        DB::transaction(function () use ($tenant, $access, $user) {
            $tenant->users()->detach($user->getKey());
            $user->tokens()->where('tenant_id', $tenant->getKey())->delete();
            $access->delete();
            // Sin otras empresas ni rol de plataforma la cuenta queda sin uso: se desactiva y se cierran sus sesiones.
            if ($user->platform_role_id === null && ! $user->tenants()->exists()) {
                $user->forceFill(['is_active' => false])->save();
                $this->passwords->closeAccess($user);
            }
        });
        $this->audit->log('portal.access_revoked', $actor->getKey(), $access->party_type, $partyId, ['user' => $user->public_id]);
    }

    public function isActive(Tenant $tenant, PortalAccess $access): bool
    {
        return (bool) $tenant->users()->whereKey($access->user_id)->first()?->pivot->is_active;
    }

    /** @return array<string, mixed> */
    public function present(Tenant $tenant, PortalAccess $access): array
    {
        $user = $access->user;

        return [
            'public_id' => $access->public_id,
            'type' => $access->party_type,
            'user' => ['name' => $user->name, 'email' => $user->email],
            'is_active' => $user->is_active && $this->isActive($tenant, $access),
            'must_change_password' => (bool) $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $access->created_at?->toIso8601String(),
            'shared_account' => $user->tenants()->whereKeyNot($tenant->getKey())->exists(),
        ];
    }

    private function column(Customer|Supplier $party): string
    {
        return $party instanceof Customer ? 'customer_id' : 'supplier_id';
    }

    private function partyPublicId(PortalAccess $access): ?string
    {
        return $access->party_type === PortalAccess::CUSTOMER ? $access->customer?->public_id : $access->supplier?->public_id;
    }
}
