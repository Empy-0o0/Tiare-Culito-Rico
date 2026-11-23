<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentTenant;

/** Contactos de una organización: se editan con permiso de actualizar clientes o proveedores. */
class OrganizationPolicy
{
    public function manageContacts(User $user, Organization $organization): bool
    {
        $tenant = app(CurrentTenant::class)->get();
        if ($tenant === null || $organization->tenant_id !== $tenant->getKey()) {
            return false;
        }

        return ($organization->customer()->exists() && $user->hasPermission('customers.update', $tenant))
            || ($organization->supplier()->exists() && $user->hasPermission('suppliers.update', $tenant));
    }
}
