<?php

namespace App\Policies;

use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Base de políticas para recursos privados de un tenant: exige el permiso del recurso
 * en el tenant activo y que el registro pertenezca a ese tenant (defensa en profundidad
 * además del global scope de BelongsToTenant).
 */
abstract class TenantResourcePolicy
{
    /** Prefijo de permisos, p. ej. "customers". */
    abstract protected function resource(): string;

    protected function allowed(User $user, string $action): bool
    {
        $tenant = app(CurrentTenant::class)->get();

        return $tenant !== null && $user->hasPermission($this->resource().'.'.$action, $tenant);
    }

    protected function ownsRecord(Model $model): bool
    {
        return (int) $model->getAttribute('tenant_id') === app(CurrentTenant::class)->id();
    }

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'read');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->ownsRecord($model) && $this->allowed($user, 'read');
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'create');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->ownsRecord($model) && $this->allowed($user, 'update');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->ownsRecord($model) && $this->allowed($user, 'delete');
    }
}
