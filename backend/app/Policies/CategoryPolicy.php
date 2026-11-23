<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CategoryPolicy extends TenantResourcePolicy
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
