<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Catálogo: products.read para leer; products.manage para crear/editar productos y categorías. */
class ProductPolicy extends TenantResourcePolicy
{
    protected function resource(): string
    {
        return 'products';
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->ownsRecord($model) && $this->allowed($user, 'manage');
    }
}
