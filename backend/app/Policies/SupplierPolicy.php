<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy extends TenantResourcePolicy
{
    protected function resource(): string
    {
        return 'suppliers';
    }

    /** Registrar evaluaciones de desempeño (suppliers.evaluate). */
    public function evaluate(User $user, Supplier $supplier): bool
    {
        return $this->ownsRecord($supplier) && $this->allowed($user, 'evaluate');
    }

    /** Subir o archivar documentos del proveedor (mismo permiso que editarlo). */
    public function manageDocuments(User $user, Supplier $supplier): bool
    {
        return $this->update($user, $supplier);
    }
}
