<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;

/**
 * Tenant activo de la request. Se resuelve en backend desde la sesión y la pertenencia
 * del usuario; nunca desde un parámetro enviado por el navegador.
 */
final class CurrentTenant
{
    public const SESSION_KEY = 'current_tenant_id';

    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    /** Resuelve el tenant desde la sesión, validando pertenencia; si no, el primero activo. */
    public static function resolveFor(User $user, mixed $sessionTenantId): ?Tenant
    {
        $query = $user->accessibleTenants()->orderBy('tenants.id');
        if ($sessionTenantId !== null) {
            $tenant = (clone $query)->whereKey((int) $sessionTenantId)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        return $query->first();
    }
}
